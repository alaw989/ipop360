<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generate card-sized WebP thumbnails for restaurant photos served by hosts
 * that don't resize on request.
 *
 * The results grid renders photos at 96–176 px, but a venue's own host serves
 * the full-size original — one 16 MB JPEG put a single search page at 17.6 MB.
 * Google and Wikimedia already resize by URL and are skipped. Rows without a
 * thumbnail (or, with --refresh, whose photo_url has changed) are processed in
 * popularity order, bounded by --limit. Default is a read-only dry run; pass
 * --apply to download and store.
 */
class GeneratePhotoThumbnails extends Command
{
    protected $signature = 'restaurants:photo-thumbnails
        {--apply : Download and store thumbnails (default is a read-only dry run)}
        {--limit=0 : Max thumbnails to generate (0 = all needing one)}
        {--refresh : Also re-generate thumbnails whose photo_url has changed}';

    protected $description = 'Generate card-sized WebP thumbnails for photos from hosts that cannot resize';

    public function handle(PhotoThumbnailService $thumbs): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $refresh = (bool) $this->option('refresh');
        $cap = $limit > 0 ? $limit : PHP_INT_MAX;

        $query = Restaurant::query()
            ->active()
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '');

        if (! $refresh) {
            $query->where(fn ($q) => $q->whereNull('photo_thumb')->orWhere('photo_thumb', ''));
        }

        // Rows with no thumbnail first, then the most search-visible rows.
        $query->orderByRaw("CASE WHEN photo_thumb IS NULL OR photo_thumb = '' THEN 0 ELSE 1 END")
            ->orderByDesc('popularity_score')
            ->orderBy('id');

        $this->info(($apply ? 'Generating' : 'Would generate').' photo thumbnails...');

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $candidates = 0;

        // lazy() pages with separate queries, so each row can be updated
        // mid-iteration; a single cursor() holds the SQLite read open and the
        // writes are dropped.
        foreach ($query->lazy() as $restaurant) {
            if ($generated >= $cap) {
                break;
            }

            if ($thumbs->hasThumb($restaurant)) {
                $skipped++;

                continue;
            }

            $candidates++;

            if (! $apply) {
                $generated++; // "would generate"

                continue;
            }

            try {
                $filename = $thumbs->generate($restaurant);
                if ($filename !== null) {
                    $restaurant->update(['photo_thumb' => $filename]);
                    $generated++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::channel('enrichment')->warning('Photo thumbnail failed', [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (thumbnails stored)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line("Candidates (no fresh thumb): {$candidates}");
        $this->line(($apply ? 'Generated' : 'Would generate').": {$generated}");
        $this->line("Skipped (already have a fresh thumb): {$skipped}");
        $this->line("Failed: {$failed}");

        Log::channel('enrichment')->info('Photo thumbnail sweep complete', [
            'mode' => $apply ? 'applied' : 'dry-run',
            'refresh' => $refresh,
            'candidates' => $candidates,
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return self::SUCCESS;
    }
}
