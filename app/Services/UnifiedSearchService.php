<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * Unified merged search: ALWAYS run the live free-source search, merge the
 * persisted DB rows into it, and rank the union by popularity score in one
 * pass. Both search endpoints delegate here so a city×cuisine with even one
 * stale DB row still surfaces fresh venues from the free unlimited sources,
 * and DB + live rows are never ranked separately.
 *
 * Merge rules (DB row wins as the base identity/engagement row; live overlays
 * the enrichment fields it is authoritative for):
 *   - match keys, in precedence order: google_place_id → slug → phone-last-10
 *     → fuzzy name + ~200m proximity (VenuePipeline::venuesMatch);
 *   - live overlays rating / price / photo / website / description / place_types;
 *   - unmatched live rows are persisted (LiveVenuePersister) and appended.
 *
 * On a cuisine-scoped search the whole union is stamped with `cuisine_match`
 * (mirrors LiveSearchService::stampCuisineMatchStrength) so DB rows share the
 * live rows' active signal set — otherwise the 0.50 cuisine_match weight
 * renormalizes away for DB rows and inflates their other signals. The scored
 * union is then re-sorted by the user's mode (VenuePipeline::sortVenues) and
 * passed through the cuisine-confidence filter (VenuePipeline::
 * filterByCuisineConfidence) so off-cuisine DB rows sink, matching the live
 * search's own post-processing.
 */
class UnifiedSearchService
{
    public function __construct(
        private LiveSearchService $liveSearchService,
        private PopularityScoreService $scoreService,
        private CuisineMatcher $cuisineMatcher,
        private VenuePipeline $venuePipeline,
        private LiveVenuePersister $venuePersister,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(
        float $lat,
        float $lng,
        ?string $cuisineSlug = null,
        ?string $categorySlug = null,
        string $sort = 'best_match',
        ?float $distanceKm = null,
        ?string $priceRange = null,
    ): array {
        $scope = $this->cuisineMatcher->resolveScope($cuisineSlug, $categorySlug);

        // A cuisine/category was requested but is unknown to the taxonomy →
        // honest empty (parity with LiveSearchService).
        if ($scope->isInvalid()) {
            return [];
        }

        // Always run the live search — never gate it on the DB being empty.
        $liveRows = $this->liveSearchService->search($lat, $lng, $cuisineSlug, $categorySlug, false, $sort, $distanceKm);

        $dbRows = $this->fetchDbRows($lat, $lng, $distanceKm);

        $merged = $this->merge($dbRows, $liveRows);

        // Price-range filter (parity with SearchController's `where('price_range',
        // ...)`). Runs BEFORE scoring so the aggregates (credible quality mean,
        // min/max) are computed over the filtered set — mirroring the DB-first
        // path, which filtered price in SQL before scoring. A price-dropped
        // unmatched live row is never persisted (its `_persist` tag is filtered
        // out with the row).
        if ($priceRange !== null) {
            $merged = array_values(array_filter(
                $merged,
                fn (array $row) => ($row['price_range'] ?? null) === $priceRange
            ));
        }

        if ($scope->isScoped()) {
            $merged = $this->stampCuisineMatch($merged, $scope);
        }

        $scored = $this->scoreUnion($merged, $lat, $lng);

        // Respect the user sort mode across the WHOLE union (DB + live), then
        // apply the cuisine-confidence filter on the scoped union so off-cuisine
        // DB rows sink (the broad nearby() fetch pulls in every nearby active
        // venue, not just the searched cuisine — mirror LiveSearchService).
        $scored = $this->venuePipeline->sortVenues($scored, $sort, true);
        $scored = $this->venuePipeline->filterByCuisineConfidence($scored, $scope);

        return $this->persistUnmergedLiveRows($scored);
    }

    /**
     * Fetch persisted nearby rows (broad — the cuisine_match stamp + confidence
     * filter rank relevance, not a strict whereHas, so a scoped search still
     * surfaces rows whose cuisine tags are absent but whose name matches).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchDbRows(float $lat, float $lng, ?float $distanceKm): array
    {
        $rows = Restaurant::query()
            ->with('cuisines')
            ->active()
            ->nearby($lat, $lng, $distanceKm)
            ->get();

        return $rows->map(function (Restaurant $r): array {
            $row = $r->toArray();
            $row['distance'] = $r->getAttribute('distance');
            // Normalize to the live-search lat/lng key convention for uniform
            // matching + scoring across the merged union.
            $row['lat'] = $r->latitude;
            $row['lng'] = $r->longitude;

            return $row;
        })->all();
    }

    /**
     * Merge DB rows and live rows into a single union. DB row wins as the base;
     * each live row folds into at most one DB row (match keys in precedence
     * order); unmatched live rows are tagged `_persist` and appended (persisted
     * after the union is scored, so they carry a real union score + id).
     *
     * @param  array<int, array<string, mixed>>  $dbRows
     * @param  array<int, array<string, mixed>>  $liveRows
     * @return array<int, array<string, mixed>>
     */
    private function merge(array $dbRows, array $liveRows): array
    {
        $merged = [];
        $consumedLive = [];

        foreach ($dbRows as $db) {
            $base = $db;

            foreach ($liveRows as $i => $live) {
                if (isset($consumedLive[$i])) {
                    continue;
                }

                if ($this->sameVenue($db, $live)) {
                    $base = $this->overlayLiveOntoDb($base, $live);
                    $consumedLive[$i] = true;
                }
            }

            $merged[] = $base;
        }

        foreach ($liveRows as $i => $live) {
            if (isset($consumedLive[$i])) {
                continue;
            }

            $live['_persist'] = true;
            $merged[] = $live;
        }

        return $merged;
    }

    /**
     * Do a DB row and a live row represent the same physical venue? Match keys
     * in precedence order: google_place_id → slug → phone-last-10 → fuzzy name
     * + ~200m proximity.
     *
     * @param  array<string, mixed>  $db
     * @param  array<string, mixed>  $live
     */
    private function sameVenue(array $db, array $live): bool
    {
        $dbPlaceId = (string) ($db['google_place_id'] ?? '');
        $livePlaceId = (string) ($live['google_place_id'] ?? '');
        if ($dbPlaceId !== '' && $livePlaceId !== '' && $dbPlaceId === $livePlaceId) {
            return true;
        }

        $dbSlug = (string) ($db['slug'] ?? '');
        $liveSlug = (string) ($live['slug'] ?? '');
        if ($dbSlug !== '' && $liveSlug !== '' && $dbSlug === $liveSlug) {
            return true;
        }

        $dbPhone = $this->lastTenDigits($db['phone'] ?? null);
        $livePhone = $this->lastTenDigits($live['phone'] ?? null);
        if ($dbPhone !== null && $livePhone !== null && $dbPhone === $livePhone) {
            return true;
        }

        $radius = (float) config('restaurant-finder.dedup.match_radius_km', 0.2);
        $similarity = (float) config('restaurant-finder.dedup.name_similarity_threshold', 85.0);

        return $this->venuePipeline->venuesMatch($db, $live, $radius, $similarity);
    }

    /**
     * DB row wins as the base; live overlays the enrichment fields it is
     * authoritative for when it actually has a value (never clobber a good DB
     * value with an empty live one).
     *
     * @param  array<string, mixed>  $db
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private function overlayLiveOntoDb(array $db, array $live): array
    {
        $fields = [
            'google_rating', 'google_review_count', 'yelp_rating', 'yelp_review_count',
            'price_range', 'photo_url', 'website_url', 'description',
        ];

        foreach ($fields as $field) {
            if ($this->hasValue($live[$field] ?? null)) {
                $db[$field] = $live[$field];
            }
        }

        if (! empty($live['place_types'])) {
            $liveTypes = is_array($live['place_types']) ? $live['place_types'] : [$live['place_types']];
            $db['place_types'] = array_values(array_unique(array_merge(
                $db['place_types'] ?? [],
                $liveTypes,
            )));
        }

        return $db;
    }

    /**
     * Persist the live rows that had no DB counterpart (tagged `_persist` during
     * merge). Runs AFTER scoring so each persisted row stores a real union score
     * and carries a real DB id for engagement tracking. In-place, order-preserving.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function persistUnmergedLiveRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (! isset($row['_persist'])) {
                continue;
            }

            $row['popularity_score'] ??= 0.0;
            $result = $this->venuePersister->persist(
                $row,
                $this->venuePersister->knownCuisineIds($row)
            );
            $row['id'] = $result['venue']['id'];
            unset($row['_persist']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Last 10 digits of a phone number (the unique-enough tail), mirroring
     * VenuePipeline::normalizePhone. Returns null when there are fewer than 10
     * digits (a shared short line can't reliably identify a venue).
     */
    private function lastTenDigits(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (! is_string($digits) || strlen($digits) < 10) {
            return null;
        }

        return substr($digits, -10);
    }

    /**
     * Stamp cuisine_match on the union for a scoped search (DB rows included).
     * Mirrors LiveSearchService::stampCuisineMatchStrength so DB and live rows
     * share one active signal set.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function stampCuisineMatch(array $rows, CuisineScope $scope): array
    {
        if (! (bool) config('restaurant-finder.ranking.cuisine_match', true)) {
            return $rows;
        }

        $onPattern = '/'.implode('|', $scope->onKeywords).'/i';
        $targetSlugs = array_flip($scope->targetSlugs);

        foreach ($rows as &$r) {
            $name = (string) ($r['name'] ?? '');
            $placeTypes = is_array($r['place_types'] ?? null) ? implode(' ', $r['place_types']) : '';
            $description = (string) ($r['description'] ?? '');

            foreach (($r['cuisines'] ?? []) as $venueCuisine) {
                $slug = strtolower((string) ($venueCuisine['slug'] ?? ''));
                if ($slug !== '' && isset($targetSlugs[$slug])) {
                    $r['cuisine_match'] = 1.0;

                    continue 2;
                }

                $tagText = trim($slug.' '.($venueCuisine['name'] ?? ''));
                if ($tagText !== '' && preg_match($onPattern, $tagText) === 1) {
                    $r['cuisine_match'] = 1.0;

                    continue 2;
                }
            }

            if ($name !== '' && preg_match($onPattern, $name) === 1) {
                $r['cuisine_match'] = 1.0;

                continue;
            }

            if (preg_match($onPattern, $placeTypes.' '.$description) === 1) {
                $r['cuisine_match'] = 0.5;

                continue;
            }

            $r['cuisine_match'] = 0.0;
        }
        unset($r);

        return $rows;
    }

    /**
     * Score the merged union in ONE pass: aggregates computed once over the
     * union (shared denominators + credible quality mean), then each row scored.
     * Mirrors LiveSearchService::scoreWithUnifiedService (neutral-proximity
     * sentinel included). Returns the union sorted by score desc.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function scoreUnion(array $rows, float $searchLat, float $searchLng): array
    {
        if (empty($rows)) {
            return [];
        }

        $aggregates = $this->scoreService->computeAggregates(new Collection($rows));

        foreach ($rows as &$r) {
            $lat = $r['lat'] ?? $r['latitude'] ?? null;
            $lng = $r['lng'] ?? $r['longitude'] ?? null;
            $noUsableCoords = $lat === null || $lng === null
                || ((float) $lat === 0.0 && (float) $lng === 0.0);
            $stampedNeutral = false;

            if ($noUsableCoords
                && config('restaurant-finder.ranking.no_coords_neutral_proximity', true)
            ) {
                $r['distance'] = (float) config('restaurant-finder.ranking.proximity_scale_km', 2.0);
                $stampedNeutral = true;
            } elseif (! $noUsableCoords && ! isset($r['distance'])) {
                $r['distance'] = $this->venuePipeline->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng);
            }

            $breakdown = $this->scoreService->calculateBreakdownWithAggregates($r, $aggregates);
            $r['popularity_score'] = $breakdown['total'];
            $r['score_breakdown'] = $breakdown;

            if ($stampedNeutral) {
                unset($r['distance']);
            }
        }
        unset($r);

        usort($rows, fn ($a, $b) => ($b['popularity_score'] ?? 0.0) <=> ($a['popularity_score'] ?? 0.0));

        return $rows;
    }

    /**
     * Is a value "present" enough to overlay? null, empty string, and numeric
     * 0 (a rating/review count of zero means "no data") are absent.
     */
    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_numeric($value)) {
            return (float) $value != 0.0;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
