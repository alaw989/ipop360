<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use App\Support\SqlDialect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill missing restaurant photos from free sources.
 *
 * Targets rows with no photo_url (38% of the corpus). Website-owners are
 * processed first — the venue's own og:image/<img> tags are the most reliable
 * and are cached by RestaurantWebsiteScraperService::scrape — then rows
 * without a website fall back to Wikimedia Commons / Wikipedia / Google image
 * search via searchAnyImage. When a photo is found it is also written to the
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
        {--min-photos=0 : Also top up rows that already have a photo but fewer than N gallery photos}';

    protected $description = 'Backfill missing restaurant photos + gallery arrays from free sources (website og:image, Wikimedia, Wikipedia)';

    private int $found = 0;

    private int $galleryFilled = 0;

    private int $failed = 0;

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
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

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No restaurants need photos.');

            return self::SUCCESS;
        }

        // Websites first: they have the most reliable (venue-owned) image source.
        $rows = (clone $query)
            ->orderByRaw('CASE WHEN website_url IS NOT NULL AND website_url != \'\' THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->limit($limit > 0 ? $limit : $total)
            ->get();

        $this->info(($apply ? 'Backfilling' : 'Would backfill')." photos for {$rows->count()} restaurant(s)...");

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $restaurant) {
            try {
                $photoUrl = $scraper->searchAnyImage(
                    (string) $restaurant->name,
                    $restaurant->city,
                    $restaurant->state,
                    $restaurant->website_url,
                );

                $updates = [];

                if ($photoUrl !== null && empty($restaurant->photo_url)) {
                    $updates['photo_url'] = $photoUrl;
                    $this->found++;
                }                // Fill the gallery array too: existing photo_url + scraped photos
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
        $this->line("Failed: {$this->failed}");

        return self::SUCCESS;
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
}
