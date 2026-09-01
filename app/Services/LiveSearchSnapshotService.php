<?php

namespace App\Services;

use App\Models\ExternalApiCache;

/**
 * Owns the live-search snapshot cache lifecycle (spec-100 item 2).
 *
 * RestaurantController used to hand-build ExternalApiCache keys and read/write
 * the cache directly for two kinds of snapshot:
 *   - page snapshots (browse_page:{hash} / union_page:{hash}) backing
 *     deterministic pagination without re-burning the live sources;
 *   - per-venue preview snapshots (preview:{slug}) backing the zero-quota
 *     /restaurants/preview/{slug} detail page.
 *
 * This service wraps those calls so the cache keys, TTLs, and empty-result
 * retry guard all live in one testable place; the controller stays thin.
 */
class LiveSearchSnapshotService
{
    /**
     * Read a page snapshot. Returns [] when the key is absent or expired (or
     * holds a non-array payload) so callers can treat a miss as an empty page
     * rather than re-running the live search mid-pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readPageSnapshot(string $key): array
    {
        $snapshotted = ExternalApiCache::findByKey($key);

        return is_array($snapshotted) ? $snapshotted : [];
    }

    /**
     * Store a page snapshot under the given key at the configured
     * page_snapshot_minutes TTL.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    public function storePageSnapshot(string $key, array $results): void
    {
        ExternalApiCache::storeByKey(
            $key,
            $results,
            now()->addMinutes((int) config('restaurant-finder.live_search.page_snapshot_minutes', 10))
        );
    }

    /**
     * Store every venue with a non-empty slug under preview:{slug} so the
     * detail page can render it from a direct lookup (spec-040). No-op on an
     * empty result set.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    public function storePreviews(array $results): void
    {
        if (empty($results)) {
            return;
        }

        foreach ($results as $venue) {
            $slug = $venue['slug'] ?? null;
            if (! empty($slug)) {
                $this->storePreview($slug, $venue);
            }
        }
    }

    /**
     * Store a single venue under preview:{slug} at the configured
     * preview_snapshot_days TTL.
     *
     * @param  array<string, mixed>  $venue
     */
    public function storePreview(string $slug, array $venue): void
    {
        ExternalApiCache::storeByKey(
            "preview:{$slug}",
            $venue,
            now()->addDays((int) config('restaurant-finder.cache.preview_snapshot_days', 7))
        );
    }

    /**
     * Read a venue preview snapshot. Returns null when absent or expired (the
     * caller 404s rather than falling back to a live fetch).
     *
     * @return array<string, mixed>|null
     */
    public function readPreview(string $slug): ?array
    {
        return ExternalApiCache::findByKey("preview:{$slug}");
    }
}
