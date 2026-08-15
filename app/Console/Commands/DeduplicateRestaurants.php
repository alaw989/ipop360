<?php

namespace App\Console\Commands;

use App\Services\RestaurantDeduplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge exact-duplicate restaurant rows.
 *
 * The live-search read path persisted a snapshot copy (persistLiveResults /
 * preview snapshots) on top of enrichment-created rows, so 118 pairs exist
 * with the SAME name + city + coords but different slugs. The earlier row is
 * consistently the richer one (has rating/website/photo). This command:
 *   1. finds exact-duplicate pairs (same name, city, non-null coords),
 *   2. keeps the earlier (richer) row, deletes the later,
 *   3. repoints restaurant_engagement / restaurant_social_links /
 *      favorite_restaurant_user to the kept row (dropping a dup-row social
 *      link when the kept row already has the same platform),
 *   4. unions the cuisine_restaurant pivot.
 *
 * Default is a read-only dry run; pass --apply to persist. Every change is
 * also logged to the enrichment channel for auditability.
 */
class DeduplicateRestaurants extends Command
{
    protected $signature = 'restaurants:dedupe
        {--apply : Persist merges (default is a read-only dry run)}
        {--limit=0 : Max pairs to process (0 = all)}';

    protected $description = 'Merge exact-duplicate restaurant rows (same name/city/coords)';

    private int $pairs = 0;

    private int $rowsDeleted = 0;

    private int $linksRepointed = 0;

    private int $linksDropped = 0;

    private int $engagementMerged = 0;

    private int $favoritesRepointed = 0;

    private int $pivotUnions = 0;

    public function __construct(private readonly RestaurantDeduplicationService $dedupe)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $pairs = $this->findDuplicatePairs($limit);

        if (empty($pairs)) {
            $this->info('No exact duplicate pairs found.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'Merging' : 'Would merge').' '.count($pairs).' duplicate pair(s)...');

        $bar = $this->output->createProgressBar(count($pairs));
        $bar->start();

        foreach ($pairs as $pair) {
            $this->mergePair((int) $pair['keep_id'], (int) $pair['dupe_id'], $apply);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (changes persisted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line("Pairs merged: {$this->pairs}");
        $this->line("Duplicate rows deleted: {$this->rowsDeleted}");
        $this->line("Social links repointed: {$this->linksRepointed}");
        $this->line("Social links dropped (platform collision): {$this->linksDropped}");
        $this->line("Engagement rows repointed: {$this->engagementMerged}");
        $this->line("Favorites repointed: {$this->favoritesRepointed}");
        $this->line("Cuisine pivot unions: {$this->pivotUnions}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{keep_id: int, dupe_id: int, name: string}>
     */
    private function findDuplicatePairs(int $limit): array
    {
        $query = DB::table('restaurants as a')
            ->join('restaurants as b', function ($join) {
                $join->on('a.name', '=', 'b.name')
                    ->on('a.id', '<', 'b.id');
            })
            ->whereNotNull('a.latitude')
            ->whereNotNull('a.longitude')
            ->whereColumn('a.latitude', 'b.latitude')
            ->whereColumn('a.longitude', 'b.longitude')
            ->whereColumn('a.city', 'b.city')
            ->select('a.id as keep_id', 'b.id as dupe_id', 'a.name')
            ->orderBy('a.id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($row) => [
            'keep_id' => (int) $row->keep_id,
            'dupe_id' => (int) $row->dupe_id,
            'name' => (string) $row->name,
        ])->all();
    }

    private function mergePair(int $keepId, int $dupeId, bool $apply): void
    {
        $stats = $this->dedupe->mergePair($keepId, $dupeId, $apply);

        $this->pairs++;
        $this->rowsDeleted += $stats['rows_deleted'];
        $this->linksRepointed += $stats['links_repointed'];
        $this->linksDropped += $stats['links_dropped'];
        $this->engagementMerged += $stats['engagement_merged'];
        $this->favoritesRepointed += $stats['favorites_repointed'];
        $this->pivotUnions += $stats['pivot_unions'];
    }
}
