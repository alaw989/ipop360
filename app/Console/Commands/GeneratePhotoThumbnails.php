<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Self-host a WebP copy of every restaurant photo.
 *
 * Copies are normally queued the moment photo_url changes (Restaurant::saved →
 * GeneratePhotoThumbnail); this sweep is the backstop for writes that bypass
 * model events. Google gps-cs-s photos come first, newest first: their source
 * URLs expire within weeks, so they are the ones on a clock. Then the rest in
 * popularity order, bounded by --limit (counts copy attempts, not just
 * successes, so a run of dead sources can't turn it into a full-table walk).
 * --prune deletes stored files that no longer match their row's photo.
 * Default is a read-only dry run; pass --apply to download and store.
 */
class GeneratePhotoThumbnails extends Command
{
    protected $signature = 'restaurants:photo-thumbnails
        {--apply : Download and store thumbnails (default is a read-only dry run)}
        {--limit=0 : Max thumbnails to generate (0 = all needing one)}
        {--refresh : Also re-generate thumbnails whose photo_url has changed}
        {--prune : Delete stored thumbnails that no longer match their row\'s photo}';

    protected $description = 'Self-host WebP copies of restaurant photos (Google photos first: their URLs expire)';

    public function handle(PhotoThumbnailService $thumbs): int
    {
        if ($this->option('prune')) {
            return $this->prune($thumbs);
        }

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

        // Rows with no thumbnail first; within them, expiring Google photos
        // (newest first — the freshest still have a live source), then the
        // most search-visible rows.
        $query->orderByRaw("CASE WHEN photo_thumb IS NULL OR photo_thumb = '' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN photo_url LIKE '%gps-cs-s%' OR COALESCE(photo_source, '') = 'google_thumbnail' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('popularity_score')
            ->orderBy('id');

        $this->info(($apply ? 'Generating' : 'Would generate').' photo copies...');

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $attempted = 0;
        /** @var array<string, int> $failedByHost */
        $failedByHost = [];

        // Snapshot the ordered ids, then load rows in chunks. Offset paging
        // (lazy()) would skip rows as earlier ones leave the photo_thumb IS NULL
        // filter, and a single cursor() holds the SQLite read open so the
        // writes are dropped.
        $ids = array_values(array_map('intval', $query->pluck('id')->all()));

        foreach ($this->inOrder($ids) as $restaurant) {
            if ($attempted >= $cap) {
                break;
            }

            if ($thumbs->hasThumb($restaurant)) {
                $skipped++;

                continue;
            }

            $attempted++;

            if (! $apply) {
                $generated++; // "would generate"

                continue;
            }

            try {
                $filename = $thumbs->generate($restaurant);
            } catch (\Throwable $e) {
                $filename = null;
                Log::channel('enrichment')->warning('Photo thumbnail failed', [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'message' => $e->getMessage(),
                ]);
            }

            if ($filename !== null) {
                // Quiet write: photo_thumb is derived and must not re-fire the
                // model hooks (which would queue a second copy) or bump updated_at.
                Restaurant::query()->whereKey($restaurant->id)->toBase()->update(['photo_thumb' => $filename]);
                $generated++;
            } else {
                $failed++;
                $host = strtolower((string) (parse_url((string) $restaurant->photo_url, PHP_URL_HOST) ?: 'unknown'));
                $failedByHost[$host] = ($failedByHost[$host] ?? 0) + 1;
            }
        }

        arsort($failedByHost);
        $topFailedHosts = array_slice($failedByHost, 0, 10, true);

        $this->newLine();
        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (copies stored)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line("Attempted: {$attempted}");
        $this->line(($apply ? 'Generated' : 'Would generate').": {$generated}");
        $this->line("Skipped (already have a fresh thumb): {$skipped}");
        $this->line("Failed: {$failed}");
        foreach ($topFailedHosts as $host => $count) {
            $this->line(sprintf('  %-40s %6d', $host, $count));
        }

        Log::channel('enrichment')->info('Photo thumbnail sweep complete', [
            'mode' => $apply ? 'applied' : 'dry-run',
            'refresh' => $refresh,
            'attempted' => $attempted,
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
            'failed_by_host' => $topFailedHosts,
        ]);

        return self::SUCCESS;
    }

    /**
     * Rows for the given ids, in the given order, loaded 500 at a time.
     *
     * @param  list<int>  $ids
     * @return \Generator<int, Restaurant>
     */
    private function inOrder(array $ids): \Generator
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Restaurant::query()->whereIn('id', $chunk)->get()->keyBy('id');
            foreach ($chunk as $id) {
                $row = $rows->get($id);
                if ($row instanceof Restaurant) {
                    yield $row;
                }
            }
        }
    }

    /**
     * Delete stored copies whose row's photo changed or went away. A dry run
     * only counts them.
     */
    private function prune(PhotoThumbnailService $thumbs): int
    {
        $apply = (bool) $this->option('apply');
        $orphans = $thumbs->orphanedFiles();

        if ($apply) {
            foreach ($orphans as $file) {
                Storage::disk('local')->delete($thumbs->storagePath($file));
            }
        }

        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (orphans deleted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line(($apply ? 'Deleted' : 'Would delete').' orphaned thumbnails: '.count($orphans));

        Log::channel('enrichment')->info('Photo thumbnail prune complete', [
            'mode' => $apply ? 'applied' : 'dry-run',
            'orphans' => count($orphans),
        ]);

        return self::SUCCESS;
    }
}
