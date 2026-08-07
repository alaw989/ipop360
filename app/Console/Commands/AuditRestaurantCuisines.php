<?php

namespace App\Console\Commands;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\CuisineMatcher;
use Illuminate\Console\Command;

/**
 * One-time audit + sweep of the cuisine_restaurant pivot.
 *
 * The enrichment pipeline used to attach the *searched* cuisine to EVERY venue
 * it found (BizData ignores its query param and returns all nearby restaurants,
 * and the live search is recall-protective), so the pivot accumulated thousands
 * of wrong tags (e.g. ABC Pizza tagged Vietnamese, Arco Iris tagged Vietnamese).
 *
 * This command re-derives each restaurant's cuisines from positive evidence and:
 *   - DROPS tags with no supporting evidence (name / description / AI)
 *   - ADDS tags backed by ai_metadata.cuisines that are missing
 *
 * Evidence sources (all used to KEEP an existing tag):
 *   1. name match against the config/cuisine-keywords.php lexicon
 *   2. description match against the same lexicon
 *   3. ai_metadata.cuisines normalized to seeded cuisine slugs
 *
 * Adds are AI-backed only (conservative): the lexicon's generic terms (e.g.
 * "mediterranean", "african", "tex") would otherwise multi-tag ambiguous names.
 *
 * Default is a read-only dry run; pass --apply to persist.
 */
class AuditRestaurantCuisines extends Command
{
    protected $signature = 'restaurants:audit-cuisines
        {--apply : Persist changes (default is a read-only dry run)}
        {--all : Also audit restaurants with no cuisine tags (only AI-backed adds apply)}
        {--limit=0 : Max restaurants to process (0 = all)}
        {--cuisine= : Only audit restaurants carrying this cuisine slug}';

    protected $description = 'Audit and sweep restaurant cuisine tags against evidence (name/description/AI)';

    private int $scanned = 0;

    private int $clean = 0;

    private int $changed = 0;

    /** @var array<string, array{drop?: int, add?: int}> */
    private array $stats = [];

    /** @var array<int, array{name: string, drop: string[], add: string[]}> */
    private array $examples = [];

    public function handle(CuisineMatcher $matcher): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $cuisineSlug = $this->option('cuisine');

        $slugToId = Cuisine::pluck('id', 'slug')->all();
        $normalizedNameToSlug = Cuisine::all(['id', 'name', 'slug'])
            ->mapWithKeys(fn ($c) => [$this->normalizeCuisineName((string) $c->name) => $c->slug])
            ->filter(fn ($slug, $key) => $key !== '')
            ->all();

        $query = $this->option('all')
            ? Restaurant::query()
            : Restaurant::query()->has('cuisines');

        if ($cuisineSlug) {
            $query->whereHas('cuisines', fn ($q) => $q->where('slug', $cuisineSlug));
        }

        $total = $limit > 0 ? $query->count() : $query->count();
        if ($total === 0) {
            $this->warn('No restaurants matched the criteria.');

            return self::SUCCESS;
        }

        $this->info($this->option('all')
            ? "Auditing all {$total} restaurants..."
            : "Auditing {$total} tagged restaurants...");

        $restaurants = $limit > 0 ? $query->limit($limit)->with('cuisines')->get() : $query->with('cuisines')->get();

        $bar = $this->output->createProgressBar($restaurants->count());
        $bar->start();

        foreach ($restaurants as $restaurant) {
            [$keepSlugs, $dropSlugs, $addSlugs] = $this->recommendations(
                $restaurant,
                $matcher,
                $slugToId,
                $normalizedNameToSlug,
            );

            $this->scanned++;

            if (empty($dropSlugs) && empty($addSlugs)) {
                $this->clean++;
                $bar->advance();

                continue;
            }

            $this->changed++;

            foreach ($dropSlugs as $slug) {
                $this->stats[$slug]['drop'] = ($this->stats[$slug]['drop'] ?? 0) + 1;
            }
            foreach ($addSlugs as $slug) {
                $this->stats[$slug]['add'] = ($this->stats[$slug]['add'] ?? 0) + 1;
            }

            if (count($this->examples) < 100) {
                $this->examples[] = [
                    'name' => $restaurant->name,
                    'drop' => array_values($dropSlugs),
                    'add' => array_values($addSlugs),
                ];
            }

            if ($apply) {
                $finalIds = array_values(array_unique(array_merge(
                    array_values(array_intersect_key($slugToId, array_flip($keepSlugs))),
                    array_values(array_intersect_key($slugToId, array_flip($addSlugs))),
                )));
                $restaurant->cuisines()->sync($finalIds);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line("Scanned: {$this->scanned}  Clean: {$this->clean}  Changed: {$this->changed}");
        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (changes persisted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));

        if ($this->changed > 0) {
            $this->newLine();
            $this->line('Per-cuisine change counts:');
            ksort($this->stats);
            $this->table(
                ['cuisine', 'dropped', 'added'],
                collect($this->stats)->map(fn ($s, $slug) => [$slug, $s['drop'] ?? 0, $s['add'] ?? 0])->all(),
            );

            $this->newLine();
            $this->line('Sample changes (first 100):');
            foreach ($this->examples as $ex) {
                $parts = [];
                if (! empty($ex['drop'])) {
                    $parts[] = 'drop ['.implode(', ', $ex['drop']).']';
                }
                if (! empty($ex['add'])) {
                    $parts[] = 'add ['.implode(', ', $ex['add']).']';
                }
                $this->line("  {$ex['name']}  —  ".implode('  ', $parts));
            }
        }

        if (! $apply && $this->changed > 0) {
            $this->newLine();
            $this->line('Run with --apply to persist these changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Count how many distinct on-cuisine keywords appear in $text. Used to
     * break category-collapse ties: "Turkish Flame Mediterranean Restaurant"
     * hits 2 turkish keywords (turkish + mediterranean) vs 1 lebanese keyword
     * (mediterranean), so turkish wins even though both share "mediterranean".
     */
    private function countKeywordHits(string $text, string $slug, CuisineMatcher $matcher): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $pattern = '/'.implode('|', $matcher->keywordsFor([$slug])).'/i';
        if (preg_match_all($pattern, $text, $m) === false) {
            return 0;
        }

        return count(array_unique($m[0]));
    }

    /**
     * When 3+ kept tags belong to the same category, collapse them to the single
     * member with the strongest evidence. Fixes the old enrichment artifact that
     * stamped every member of a searched CATEGORY onto each venue (a Mediterranean
     * spot tagged egyptian|israeli|lebanese|moroccan|turkish). Real cross-category
     * fusion is untouched because it spans distinct categories.
     *
     * @param  array<string, bool>  $keep  slug => true
     * @param  string[]  $aiSlugs
     * @return array<string, bool>
     */
    private function collapseSameCategoryTags(array $keep, array $aiSlugs, string $name, string $description, CuisineMatcher $matcher): array
    {
        $categories = config('cuisine-keywords.categories', []);

        // Build slug => category membership from the config taxonomy.
        $slugCategory = [];
        foreach ($categories as $category => $members) {
            foreach ($members as $member) {
                $slugCategory[$member] = $category;
            }
        }

        // Group kept slugs by category.
        $byCategory = [];
        foreach (array_keys($keep) as $slug) {
            $byCategory[$slugCategory[$slug] ?? ''] ??= [];
            $byCategory[$slugCategory[$slug] ?? ''][] = $slug;
        }

        foreach ($byCategory as $category => $slugs) {
            if ($category === '' || count($slugs) < 3) {
                continue;
            }

            // Pick the member with the strongest evidence: distinct name/desc
            // keyword hits (counted, so "turkish" beats shared "mediterranean"),
            // then AI backing as a tiebreak, then the first (deterministic).
            $winner = null;
            $winnerScore = 0;

            foreach ($slugs as $slug) {
                $score = $this->countKeywordHits($name, $slug, $matcher);
                if ($description !== '') {
                    $score += 0.5 * $this->countKeywordHits($description, $slug, $matcher);
                }
                if (in_array($slug, $aiSlugs, true)) {
                    $score += 0.5;
                }

                if ($score > $winnerScore) {
                    $winner = $slug;
                    $winnerScore = $score;
                }
            }

            if ($winner === null) {
                $winner = $slugs[0];
            }

            foreach ($slugs as $slug) {
                if ($slug !== $winner) {
                    unset($keep[$slug]);
                }
            }
        }

        return $keep;
    }

    /**
     * Decide which of a restaurant's current tags to keep, drop, and add.
     *
     * @param  array<string, int>  $slugToId
     * @param  array<string, string>  $normalizedNameToSlug
     * @return array{0: string[], 1: string[], 2: string[]} [keep, drop, add] slugs
     */
    private function recommendations(
        Restaurant $restaurant,
        CuisineMatcher $matcher,
        array $slugToId,
        array $normalizedNameToSlug,
    ): array {
        $name = (string) $restaurant->name;
        $description = (string) ($restaurant->description ?? '');
        $aiSlugs = $this->aiCuisineSlugs($restaurant, $normalizedNameToSlug);

        $currentSlugs = $restaurant->cuisines
            ->pluck('slug')
            ->filter(fn ($slug) => isset($slugToId[$slug]))
            ->all();

        $keep = [];
        foreach ($currentSlugs as $slug) {
            $hasEvidence = in_array($slug, $aiSlugs, true)
                || $matcher->matchesEvidence($name, $slug)
                || ($description !== '' && $matcher->matchesEvidence($description, $slug));

            // Positive evidence keeps the tag. Without evidence, keep it UNLESS
            // the venue visibly signals a different cuisine (positive rival
            // contradiction, e.g. "Oishi Ramen" tagged chinese). A neutral name
            // ("Mr. Dumpling") is kept — absence of a lexicon keyword is not
            // proof the tag is wrong (the lexicon is precision-tight by design).
            if ($hasEvidence) {
                $keep[$slug] = true;

                continue;
            }

            $nameText = $name;
            if ($description !== '') {
                $nameText .= ' '.$description;
            }
            if (! $matcher->matchesRivalEvidence($nameText, $slug)) {
                $keep[$slug] = true;
            }
        }

        // Category collapse: the old enrichment stamped every member of a
        // searched CATEGORY onto each venue (e.g. a Mediterranean spot tagged
        // egyptian|israeli|lebanese|moroccan|turkish). When 3+ kept tags share
        // a category, keep only the member with the strongest evidence. Real
        // cross-category fusion (chinese|vietnamese, japanese|thai) is untouched.
        $keep = $this->collapseSameCategoryTags($keep, $aiSlugs, $name, $description, $matcher);

        $drop = array_values(array_diff($currentSlugs, array_keys($keep)));
        $add = array_values(array_diff($aiSlugs, array_keys($keep)));

        return [array_keys($keep), $drop, $add];
    }

    /**
     * Normalize ai_metadata.cuisines (free-text) to seeded cuisine slugs.
     *
     * @param  array<string, string>  $normalizedNameToSlug
     * @return string[]
     */
    private function aiCuisineSlugs(Restaurant $restaurant, array $normalizedNameToSlug): array
    {
        $aiCuisines = $restaurant->ai_metadata['cuisines'] ?? [];

        if (! is_array($aiCuisines) || empty($aiCuisines)) {
            return [];
        }

        $slugs = [];
        foreach ($aiCuisines as $cuisine) {
            $slug = $this->normalizeCuisineName((string) $cuisine);
            if ($slug !== '' && isset($normalizedNameToSlug[$slug])) {
                $slugs[] = $normalizedNameToSlug[$slug];
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Normalize a cuisine name/string into a slug-like key ("Cajun/Creole" →
     * "cajun-creole", "Tex-Mex" → "tex-mex", "New American" → "new-american").
     */
    private function normalizeCuisineName(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }
}
