<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use App\Support\PhotoLiveness;
use App\Support\SqlDialect;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Backfill missing restaurant photos from free sources.
 *
 * Targets rows with no photo_url (38% of the corpus). Website-owners are
 * processed first — the venue's own og:image/<img> tags are the most reliable
 * and are cached by RestaurantWebsiteScraperService::scrape — then rows
 * without a website fall back to Wikimedia Commons / Wikipedia / Google image
 * search via searchImageForRestaurant. When a photo is found it is also
 * written to the
 * `photos` gallery array (multi-image, capped by live_search.gallery_photos_max)
 * so cards use the 6-slot gallery instead of a single image.
 *
 * All sources are free; SerpApi budget is never touched. Default is a bounded
 * dry run; pass --apply to persist.
 */
class BackfillRestaurantPhotos extends Command
{
    protected $signature = 'restaurants:backfill-photos
        {--apply : Persist changes (default is a read-only dry run)}
        {--limit=0 : Max restaurants to process (0 = all missing)}
        {--with-website : Only process rows that already have a website_url}
        {--min-photos=0 : Also top up rows that already have a photo but fewer than N gallery photos}
        {--verify : Verify existing photo URLs (HEAD/GET), re-source dead ones, dedupe gallery}
        {--backfill-source : Infer photo_source for existing rows that have a photo_url but no photo_source (URL-host heuristic)}';

    protected $description = 'Backfill missing restaurant photos + gallery arrays from free sources (website og:image, Wikimedia, Wikipedia)';

    private int $found = 0;

    private int $galleryFilled = 0;

    private int $failed = 0;

    private int $verified = 0;

    private int $dead = 0;

    private int $resourced = 0;

    private int $promoted = 0;

    private int $cleared = 0;

    private int $skipped = 0;

    /** Rows skipped because a self-hosted copy preserves their photo. */
    private int $preserved = 0;

    /** Re-source candidates rejected as the same URL or not loading. */
    private int $rejected = 0;

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
        if ($this->option('backfill-source')) {
            return $this->handleBackfillSource();
        }

        if ($this->option('verify')) {
            return $this->handleVerify($scraper);
        }

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $withWebsite = (bool) $this->option('with-website');
        $minPhotos = (int) $this->option('min-photos');
        $galleryMax = (int) config('restaurant-finder.live_search.gallery_photos_max', 6);

        $query = Restaurant::query()
            ->active()
            ->where(function ($q) use ($minPhotos) {
                // Missing primary photo, or under the gallery target (--min-photos).
                $q->where(fn ($sub) => $sub->whereNull('photo_url')->orWhere('photo_url', ''));
                if ($minPhotos > 0) {
                    $q->orWhereRaw('photos IS NULL OR '.SqlDialect::jsonArrayLength('photos').' < ?', [$minPhotos]);
                }
            });

        if ($withWebsite) {
            $query->whereNotNull('website_url')->where('website_url', '!=', '');
        }

        // Skip rows whose photo state was verified within the cooldown window —
        // a recently confirmed-dead row (cleared to null) must not be re-sourced
        // again before the cooldown elapses.
        $cutoff = $this->photoVerifyCutoff();
        $this->skipped = (clone $query)
            ->whereNotNull('photo_verified_at')
            ->where('photo_verified_at', '>=', $cutoff)
            ->count();
        $query->where(fn ($q) => $q->whereNull('photo_verified_at')->orWhere('photo_verified_at', '<', $cutoff));

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No restaurants need photos.');

            return self::SUCCESS;
        }

        // Websites first: they have the most reliable (venue-owned) image source.
        // Within each group, the highest-popularity rows first, so the daily
        // --limit budget is spent on the most search-visible restaurants.
        $rows = (clone $query)
            ->orderByRaw('CASE WHEN website_url IS NOT NULL AND website_url != \'\' THEN 0 ELSE 1 END')
            ->orderByDesc('popularity_score')
            ->orderBy('id')
            ->limit($limit > 0 ? $limit : $total)
            ->get();

        $this->info(($apply ? 'Backfilling' : 'Would backfill')." photos for {$rows->count()} restaurant(s)...");

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $restaurant) {
            try {
                $result = $scraper->searchImageForRestaurant(
                    $restaurant,
                    $this->osmContextImage($restaurant),
                );
                $photoUrl = $result['url'] ?? null;

                // Never store a link that doesn't load (cached website scrapes
                // can hand back a since-deleted og:image).
                if ($photoUrl !== null && ! PhotoLiveness::isAlive($photoUrl)) {
                    $photoUrl = null;
                    $result = null;
                    $this->rejected++;
                }

                $updates = [];

                if ($result !== null && $photoUrl !== null && empty($restaurant->photo_url)) {
                    $updates['photo_url'] = $photoUrl;
                    $updates['photo_source'] = $result['source'];
                    $this->found++;
                }

                // Fill the gallery array too: existing photo_url + scraped photos
                // (website og:image/<img> when available), deduped, capped.
                $gallery = $this->mergeGallery((array) ($restaurant->photos ?? []), $photoUrl, $galleryMax);
                if (count($gallery) > count((array) ($restaurant->photos ?? []))) {
                    $updates['photos'] = $gallery;
                    $this->galleryFilled++;
                }

                if (! empty($updates)) {
                    if ($apply) {
                        $restaurant->update($updates);
                        if (! empty($updates['photo_url'])) {
                            Log::channel('enrichment')->info('Photo backfill found photo', [
                                'restaurant_id' => $restaurant->id,
                                'restaurant_name' => $restaurant->name,
                                'photo_url' => $updates['photo_url'],
                            ]);
                        }
                    } else {
                        Log::channel('enrichment')->info('Photo backfill (dry-run) would update', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'photo_url' => $updates['photo_url'] ?? null,
                            'gallery_count' => count($updates['photos'] ?? []),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $this->failed++;
                Log::channel('enrichment')->warning('Photo backfill failed', [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'message' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (changes persisted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line("Primary photos found: {$this->found}");
        $this->line("Gallery arrays filled/top-up: {$this->galleryFilled}");
        $this->line("Rejected (found photo did not load): {$this->rejected}");
        $this->line("Skipped (recently verified): {$this->skipped}");
        $this->line("Failed: {$this->failed}");

        Log::channel('enrichment')->info('Photo backfill sweep complete', [
            'mode' => $apply ? 'applied' : 'dry-run',
            'total' => $rows->count(),
            'found' => $this->found,
            'gallery_filled' => $this->galleryFilled,
            'rejected' => $this->rejected,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ]);

        return self::SUCCESS;
    }

    /**
     * Verify existing photo URLs: HTTP-check each distinct URL (HEAD→GET
     * fallback) across the primary photo_url AND the photos gallery array,
     * keep valid photos untouched, and re-source confirmed-dead ones via the
     * free searchImageForRestaurant chain. Dead URLs are dropped from the
     * gallery; when the primary is dead but a gallery entry is alive, that
     * entry is promoted to photo_url instead of re-sourcing. gps-cs-s Google
     * CDN URLs decay opaquely (~1-month) so they are prioritized for checking.
     * Rows with a self-hosted copy are skipped: the copy is what's shown. A
     * replacement must be a different URL that loads, and every checked row is
     * stamped, so an unfixable row isn't re-checked every week.
     * Default is a dry run; pass --apply to persist.
     */
    private function handleVerify(RestaurantWebsiteScraperService $scraper): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $galleryMax = (int) config('restaurant-finder.live_search.gallery_photos_max', 6);

        // Decaying-source rows (SerpApi's gps-cs-s Google thumbnail, ~1-month
        // opaque decay) get a much shorter cooldown than everything else
        // (website/social/osm/wikidata), which doesn't decay the same way. The
        // photo_url pattern check covers legacy rows that predate the
        // photo_source column; photo_source covers everything written since.
        // COALESCE guards against SQL's three-valued logic: a NULL photo_source
        // (legacy rows) would otherwise make `photo_source = '...'` evaluate to
        // NULL rather than false, poisoning the NOT(...) branch below into NULL
        // (excluded) instead of true (correctly routed to the stable cooldown).
        $decayingSql = "(photo_url LIKE '%gps-cs-s%' OR COALESCE(photo_source, '') = 'google_thumbnail')";
        $decayingCutoff = $this->photoVerifyCutoff(decaying: true);
        $stableCutoff = $this->photoVerifyCutoff(decaying: false);

        $base = Restaurant::query()
            ->active()
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '');

        // A row with a self-hosted copy is shown from the copy, so its source
        // dying no longer matters: nothing to verify. (Changing photo_url
        // clears photo_thumb — see Restaurant::saving — so a set column means
        // the copy belongs to the current photo.)
        $this->preserved = (clone $base)->whereNotNull('photo_thumb')->where('photo_thumb', '!=', '')->count();

        // Rows verified within their applicable cooldown window are skipped
        // this sweep.
        $this->skipped = (clone $base)
            ->whereNotNull('photo_verified_at')
            ->whereRaw(
                "(({$decayingSql} AND photo_verified_at >= ?) OR (NOT {$decayingSql} AND photo_verified_at >= ?))",
                [$decayingCutoff, $stableCutoff]
            )
            ->count();

        $query = (clone $base)->where(fn ($q) => $q->whereNull('photo_thumb')->orWhere('photo_thumb', ''))->where(function ($q) use ($decayingSql, $decayingCutoff, $stableCutoff) {
            $q->whereNull('photo_verified_at')
                ->orWhereRaw(
                    "(({$decayingSql} AND photo_verified_at < ?) OR (NOT {$decayingSql} AND photo_verified_at < ?))",
                    [$decayingCutoff, $stableCutoff]
                );
        });

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No restaurants with photos to verify.');

            return self::SUCCESS;
        }

        // gps-cs-s CDN URLs decay opaquely (~1-month) — check those first,
        // then the most search-visible rows (homepage Trending, top of search).
        $rows = (clone $query)
            ->orderByRaw("CASE WHEN photo_url LIKE '%gps-cs-s%' THEN 0 ELSE 1 END")
            ->orderByDesc('popularity_score')
            ->orderBy('id')
            ->limit($limit > 0 ? $limit : $total)
            ->get();

        $this->info(($apply ? 'Verifying' : 'Would verify')." photos for {$rows->count()} restaurant(s)...");

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $restaurant) {
            try {
                $current = trim((string) $restaurant->photo_url);
                $gallery = $this->normalizeGallery((array) ($restaurant->photos ?? []));

                // Distinct URLs to HTTP-check once: primary first, then gallery.
                $distinct = [];
                foreach (array_merge($current !== '' ? [$current] : [], $gallery) as $url) {
                    if (in_array($url, $distinct, true)) {
                        continue;
                    }
                    $distinct[] = $url;
                }

                $alive = [];
                foreach ($distinct as $url) {
                    if (PhotoLiveness::isAlive($url)) {
                        $alive[] = $url;
                    }
                }

                // Keep only alive gallery entries, in original order.
                $keptGallery = array_values(array_filter(
                    $gallery,
                    fn ($url) => in_array($url, $alive, true)
                ));

                $updates = [];

                if ($current !== '' && in_array($current, $alive, true)) {
                    $this->verified++;
                    $updates['photo_verified_at'] = now();
                } else {
                    $this->dead++;

                    if (! empty($alive)) {
                        // Promote an alive gallery entry into the primary slot.
                        // The gallery entry's own source isn't tracked per-slot,
                        // so the tier is left as-is (a prior write already set it).
                        $updates['photo_url'] = $alive[0];
                        $updates['photo_verified_at'] = now();
                        $this->promoted++;
                    } else {
                        $result = $scraper->searchImageForRestaurant(
                            $restaurant,
                            $this->osmContextImage($restaurant),
                        );
                        $fresh = $result['url'] ?? null;

                        // A candidate is only a replacement if it is a different
                        // URL that actually loads. The website scrape is cached,
                        // so it often hands back the same dead og:image; before
                        // this check those rows were left unstamped and dead,
                        // and re-checked every week forever.
                        if ($fresh !== null && ($fresh === $current || ! PhotoLiveness::isAlive($fresh))) {
                            $fresh = null;
                            $this->rejected++;
                        }

                        if ($result !== null && $fresh !== null) {
                            $updates['photo_url'] = $fresh;
                            $updates['photo_source'] = $result['source'];
                            $updates['photo_verified_at'] = now();
                            $this->resourced++;
                        }

                        if ($fresh !== null) {
                            $keptGallery = $this->mergeGallery($keptGallery, $fresh, $galleryMax);
                        } else {
                            // Confirmed-dead-unresolvable: clear to null so the card
                            // falls back to an honest no-image state, and stamp it
                            // so neither sweep re-checks it before the cooldown.
                            $updates['photo_url'] = null;
                            $updates['photo_source'] = null;
                            $updates['photo_verified_at'] = now();
                            $this->cleared++;
                        }
                    }
                }

                if ($keptGallery !== $gallery) {
                    $updates['photos'] = $keptGallery;
                }

                if (! empty($updates)) {
                    if ($apply) {
                        $restaurant->update($updates);
                        Log::channel('enrichment')->info('Photo verify updated photo', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'old_photo_url' => $current,
                            'new_photo_url' => $updates['photo_url'] ?? null,
                            'cleared' => ($updates['photo_url'] ?? null) === null,
                        ]);
                    } else {
                        Log::channel('enrichment')->info('Photo verify (dry-run) would update', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'old_photo_url' => $current,
                            'new_photo_url' => $updates['photo_url'] ?? null,
                            'cleared' => ($updates['photo_url'] ?? null) === null,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $this->failed++;
                Log::channel('enrichment')->warning('Photo verify failed', [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'message' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (changes persisted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line("Photos verified alive: {$this->verified}");
        $this->line("Dead photos found: {$this->dead}");
        $this->line("Promoted from gallery: {$this->promoted}");
        $this->line("Re-sourced: {$this->resourced}");
        $this->line("Cleared (dead-unresolvable): {$this->cleared}");
        $this->line("Rejected candidates (same URL or dead): {$this->rejected}");
        $this->line("Skipped (recently verified): {$this->skipped}");
        $this->line("Skipped (self-hosted copy): {$this->preserved}");
        $this->line("Failed: {$this->failed}");

        Log::channel('enrichment')->info('Photo verify sweep complete', [
            'mode' => $apply ? 'applied' : 'dry-run',
            'total' => $rows->count(),
            'verified' => $this->verified,
            'dead' => $this->dead,
            'promoted' => $this->promoted,
            'resourced' => $this->resourced,
            'cleared' => $this->cleared,
            'rejected' => $this->rejected,
            'skipped' => $this->skipped,
            'preserved' => $this->preserved,
            'failed' => $this->failed,
        ]);

        return self::SUCCESS;
    }

    /**
     * Infer photo_source for existing rows that have a photo_url but no
     * photo_source (i.e. written before the photo_source column existed),
     * from the URL's host: SerpApi's decaying Google CDN thumbnail, a
     * Wikimedia/Wikipedia CDN URL (keyword-search sourced — LOW trust per
     * PhotoSourceTier even though the host is identifiable), or the venue's
     * own website domain (HIGH trust). Anything else is tagged 'unknown'
     * (also LOW trust) rather than guessed. Dry run by default; --apply to persist.
     */
    private function handleBackfillSource(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $query = Restaurant::query()
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '')
            ->whereNull('photo_source');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No restaurants need photo_source backfill.');

            return self::SUCCESS;
        }

        $rows = $query->limit($limit > 0 ? $limit : $total)->get(['id', 'photo_url', 'website_url']);

        $counts = ['google_thumbnail' => 0, 'wikimedia' => 0, 'website' => 0, 'unknown' => 0];

        foreach ($rows as $restaurant) {
            $source = $this->inferPhotoSource($restaurant);
            $counts[$source]++;

            if ($apply) {
                $restaurant->update(['photo_source' => $source]);
            }
        }

        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (changes persisted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        foreach ($counts as $source => $count) {
            $this->line(sprintf('  %-18s %6d', $source, $count));
        }

        Log::channel('enrichment')->info('Photo source backfill complete', array_merge(
            ['mode' => $apply ? 'applied' : 'dry-run', 'total' => $rows->count()],
            $counts
        ));

        return self::SUCCESS;
    }

    /**
     * Best-effort host-based inference of a legacy row's photo source.
     */
    private function inferPhotoSource(Restaurant $restaurant): string
    {
        $host = strtolower((string) (parse_url((string) $restaurant->photo_url, PHP_URL_HOST) ?? ''));

        if ($host === '') {
            return 'unknown';
        }

        if (str_contains($host, 'googleusercontent.com') || str_contains((string) $restaurant->photo_url, 'gps-cs-s')) {
            return 'google_thumbnail';
        }

        if (str_contains($host, 'wikimedia.org') || str_contains($host, 'wikipedia.org')) {
            return 'wikimedia';
        }

        $websiteHost = strtolower((string) (parse_url((string) $restaurant->website_url, PHP_URL_HOST) ?? ''));
        if ($websiteHost !== '' && ($host === $websiteHost || str_ends_with($host, '.'.$websiteHost))) {
            return 'website';
        }

        return 'unknown';
    }

    /**
     * The row's current photo_url, when it is a stable Wikimedia/OSM-sourced
     * image worth reusing as verified context. Decay-prone Google CDN URLs
     * (gps-cs-s / lh3.googleusercontent.com) are excluded, so a dead CDN row
     * re-sources from context (website → social → wikidata → wikimedia) before
     * touching Google CSE again.
     */
    private function osmContextImage(Restaurant $restaurant): ?string
    {
        $url = $restaurant->photo_url;
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return str_contains($host, 'wikimedia.org') || str_contains($host, 'wikipedia.org')
            ? $url
            : null;
    }

    /**
     * Normalize the photos gallery to a deduped list of trimmed, non-empty URLs.
     *
     * @param  array<int, string>  $photos
     * @return string[]
     */
    private function normalizeGallery(array $photos): array
    {
        $normalized = [];
        foreach ($photos as $url) {
            $url = trim((string) $url);
            if ($url === '' || in_array($url, $normalized, true)) {
                continue;
            }
            $normalized[] = $url;
        }

        return $normalized;
    }

    /**
     * Merge an existing photo_url + any scraped photos into the gallery array,
     * deduped and capped at the configured gallery max.
     *
     * @param  string[]  $existing
     * @return string[]
     */
    private function mergeGallery(array $existing, ?string $photoUrl, int $max): array
    {
        $merged = [];
        foreach (array_merge($existing, $photoUrl !== null ? [$photoUrl] : []) as $url) {
            $url = trim((string) $url);
            if ($url === '' || in_array($url, $merged, true)) {
                continue;
            }
            $merged[] = $url;
            if (count($merged) >= $max) {
                break;
            }
        }

        return $merged;
    }

    /**
     * The photo-verify cooldown cutoff: rows stamped at or after this moment are
     * still within the cooldown window and must be skipped by both sweeps.
     * A decaying-source row (gps-cs-s / google_thumbnail) uses a much shorter
     * cooldown, since it decays in ~1 month rather than the general assumption
     * of a stable, venue-anchored photo.
     */
    private function photoVerifyCutoff(bool $decaying = false): Carbon
    {
        $weeks = $decaying
            ? (int) config('restaurant-finder.live_search.photo_verify_cooldown_weeks_decaying', 5)
            : (int) config('restaurant-finder.live_search.photo_verify_cooldown_weeks', 28);

        return now()->subWeeks($weeks);
    }
}
