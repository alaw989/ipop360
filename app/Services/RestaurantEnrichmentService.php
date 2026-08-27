<?php

namespace App\Services;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Cuisine;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Models\SerpApiCallLog;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free-first restaurant enrichment.
 *
 * Persisting flow uses parallel fetch from BizData and Overpass (+ SerpApi /
 * Socrata when configured), then writes real rows to the `restaurants` table
 * (positive IDs, real slugs). Wikidata awards are free (no key) and always run.
 * (Paid Google Places / Outscraper / Foursquare sources were removed — ratings
 * are a walled garden; SerpApi is the only free rating source. See spec-066 revert.)
 */
class RestaurantEnrichmentService
{
    /** Box half-width (degrees) for the single Wikidata award query (~28km). */
    private const AWARD_BOX_DEGREES = 0.25;

    public function __construct(
        private OverpassService $overpass,
        private BizDataApiService $bizData,
        private SerpApiService $serpApiService,
        private SocrataOpenDataService $socrataService,
        private WikidataService $wikidata,
        private PopularityScoreService $popularityScore,
        private RestaurantWebsiteScraperService $websiteScraper,
        private AiEnrichmentService $aiEnrichment,
        private CuisineMatcher $cuisineMatcher,
        private VenuePipeline $venuePipeline,
        private RestaurantValidationService $restaurantValidation,
    ) {}

    /**
     * Enrich restaurants for a given cuisine near a location.
     * Returns the count of restaurants enriched.
     */
    public function enrichByCuisine(float $lat, float $lng, Cuisine $cuisine, bool $freeOnly = false, ?string $cityName = null, ?string $stateCode = null): int
    {
        // Fetch all sources concurrently (skip SerpApi when freeOnly)
        $venues = $this->fetchAndNormalizeAllSources($lat, $lng, $cuisine, $freeOnly);

        if (empty($venues)) {
            Log::channel('enrichment')->info('No free venues found', [
                'lat' => $lat,
                'lng' => $lng,
                'cuisine' => $cuisine->name,
            ]);

            return 0;
        }

        // Filter garbage names from OSM-derived sources
        $venues = $this->venuePipeline->filterGarbageNames($venues);

        // Cross-source dedup: collapse fuzzy-name + proximity matches
        $venues = $this->venuePipeline->crossSourceDedup($venues);

        // Persist each free venue
        $restaurantIds = [];
        foreach ($venues as $venue) {
            try {
                $restaurant = DB::transaction(fn () => $this->processFreeVenue($venue, $cuisine, $cityName, $stateCode));
                if ($restaurant !== null) {
                    $restaurantIds[] = $restaurant->id;
                }
            } catch (\Throwable $e) {
                Log::channel('enrichment')->error('Failed to process free venue', [
                    'name' => $venue['name'] ?? '',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $restaurantIds = array_unique($restaurantIds);

        if (empty($restaurantIds)) {
            return 0;
        }

        $restaurants = Restaurant::whereIn('id', $restaurantIds)->get();

        // Optional award (Wikidata, free) — one box query, match each row
        $this->enrichAwards($restaurants, $lat, $lng);

        // Optional website scraper — fetch opening hours/menu from own websites
        $this->enrichWebsiteData($restaurants);

        // Optional AI enrichment — async job dispatch, never runs on request path
        $this->enrichWithAi($restaurants);

        // Score the persisted set together (uses the now-bonus-enriched models)
        // Compute all breakdowns first, then batch-update using CASE WHEN.
        // spec-078: compute the collection-level aggregates ONCE and reuse per
        // row — calculateBreakdown() recomputed them every iteration (O(n²)).
        $scoresByRestaurant = [];
        $updatedAt = now()->toDateTimeString();
        $aggregates = $this->popularityScore->computeAggregates($restaurants);

        foreach ($restaurants as $restaurant) {
            try {
                $breakdown = $this->popularityScore->calculateBreakdownWithAggregatesFromEloquent($restaurant, $aggregates);
                $scoresByRestaurant[$restaurant->id] = [
                    'popularity_score' => $breakdown['total'],
                    'score_breakdown' => (string) json_encode($breakdown),
                ];
            } catch (\Throwable $e) {
                Log::channel('enrichment')->error('Failed to compute popularity score', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Batch update using raw CASE WHEN to reduce N UPDATEs to ceil(N/100) queries
        // This is significantly faster than individual model updates
        $this->applyScoreUpdateBatch($scoresByRestaurant, $updatedAt);

        Log::channel('enrichment')->info('Restaurant enrichment complete', [
            'cuisine' => $cuisine->name,
            'enriched_count' => count($restaurantIds),
        ]);

        return count($restaurantIds);
    }

    /**
     * Persist popularity scores for an id → score map in one pass.
     *
     * Uses a raw CASE WHEN batch-update so N rows are written in ceil(N/100)
     * queries instead of N individual model updates. The `score_breakdown` JSON
     * is quoted-doubled: that is the only escape both SQLite and MySQL honour
     * inside single-quoted literals (spec-104). Entries whose id is absent from
     * the map are skipped, so mixed real/computed sets stay safe.
     *
     * @param  array<int, array{popularity_score: int|float, score_breakdown: string}>  $scoresByRestaurant
     */
    private function applyScoreUpdateBatch(array $scoresByRestaurant, string $updatedAt): void
    {
        if (empty($scoresByRestaurant)) {
            return;
        }

        DB::transaction(function () use ($scoresByRestaurant, $updatedAt) {
            collect(array_keys($scoresByRestaurant))->chunk(100)->each(function ($chunk) use ($scoresByRestaurant, $updatedAt) {
                $caseScore = 'CASE id';
                $caseBreakdown = 'CASE id';
                $chunkIds = [];

                foreach ($chunk as $id) {
                    $data = $scoresByRestaurant[$id] ?? null;
                    if ($data === null) {
                        continue;
                    }
                    $chunkIds[] = $id;
                    $escapedScore = (float) $data['popularity_score'];
                    $escapedBreakdown = str_replace("'", "''", $data['score_breakdown']);
                    $caseScore .= " WHEN {$id} THEN {$escapedScore}";
                    $caseBreakdown .= " WHEN {$id} THEN '{$escapedBreakdown}'";
                }

                $caseScore .= ' END';
                $caseBreakdown .= ' END';
                $idsIn = implode(',', $chunkIds);

                DB::update("
                    UPDATE restaurants
                    SET popularity_score = ({$caseScore}),
                        score_breakdown = ({$caseBreakdown}),
                        updated_at = ?
                    WHERE id IN ({$idsIn})
                ", [$updatedAt]);
            });
        });
    }

    /**
     * Fetch and normalize all sources using real Http::pool() concurrency.
     * Wall time is max of sources, not sum. Isolates failures per source.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAndNormalizeAllSources(float $lat, float $lng, Cuisine $cuisine, bool $freeOnly = false): array
    {
        // Enrichment must issue the SAME queries the live read path issues and
        // cache them under the SAME keys, so nightly enrichment pre-warms the
        // read path (and never re-burns SerpApi quota on a key mismatch — the
        // spec-072 fix). Query-style sources get the humanized term (identical
        // to LiveSearchService's $scope->queryTerm); Overpass gets the slug
        // (identical to $scope->primarySlug, which its config lookup expects).
        $context = ['read_path' => false];
        $queryTerm = $this->cuisineMatcher->humanize($cuisine->slug);

        $specs = [
            'bizdata' => $this->bizData->poolRequestsFor($lat, $lng, $queryTerm, $context),
            'overpass' => $this->overpass->poolRequestsFor($lat, $lng, $cuisine->slug, $context),
            'socrata' => $this->socrataService->poolRequestsFor($lat, $lng, $queryTerm, $context),
        ];

        if (! $freeOnly) {
            $specs['serpapi'] = $this->serpApiService->poolRequestsFor($lat, $lng, $queryTerm, $context);
        }

        // Flatten to composite keys for the pool
        $flat = [];
        $owner = [];
        foreach ($specs as $label => $labelSpecs) {
            foreach ($labelSpecs as $i => $spec) {
                $composite = "{$label}.{$i}";
                $flat[$composite] = $spec;
                $owner[$composite] = $label;
            }
        }

        if (empty($flat)) {
            return [];
        }

        // Execute all requests concurrently
        $responses = Http::pool(function (Pool $pool) use ($flat) {
            $requests = [];
            foreach ($flat as $key => $spec) {
                $request = $pool->as($key)->timeout($spec->timeout);
                if (! empty($spec->headers)) {
                    $request = $request->withHeaders($spec->headers);
                }
                if ($spec->method === 'POST') {
                    $requests[] = $spec->asForm
                        ? $request->asForm()->post($spec->url, $spec->body)
                        : $request->post($spec->url, $spec->body);
                } else {
                    $requests[] = $request->get($spec->url, $spec->query);
                }
            }

            return $requests;
        });

        // Group results back by source label
        $grouped = [];
        foreach ($responses as $composite => $result) {
            $label = $owner[$composite] ?? null;
            if ($label === null) {
                continue;
            }
            $index = (int) substr($composite, strlen($label) + 1);
            $grouped[$label][$index] = $result;
        }

        // Normalize responses to enrichment venue shape
        $venues = [];
        foreach ($grouped as $label => $responses) {
            $venues = array_merge($venues, $this->normalizePoolResponses($label, $responses, $lat, $lng, $cuisine));
        }

        return $venues;
    }

    /**
     * Normalize pooled responses for a source into enrichment venue shape.
     * Uses each service's consumePoolResponses to parse, cache, and normalize.
     * Then delegates to each service's normalizeForEnrichment for the enrichment format.
     * Handles failures (throwables) by skipping the source.
     *
     * @param  array<int, Response|\Throwable>  $responses
     * @return array<int, array<string, mixed>>
     */
    private function normalizePoolResponses(string $label, array $responses, float $lat, float $lng, Cuisine $cuisine): array
    {
        try {
            // Cache under each source's OWN cacheKeyFor, using the same canonical
            // value the live read path uses — so enrichment writes land in the
            // exact entries the read path reads (spec-072). The old generic
            // buildCacheKey() used a `cuisine` compact key for every source,
            // which matched no source's read-path key; for SerpApi it diverged
            // from isSerpApiCacheFresh's `query` key, so the skip-check never saw
            // its own store → enrichment re-fetched every combo every run.
            $queryTerm = $this->cuisineMatcher->humanize($cuisine->slug);
            $cacheKey = match ($label) {
                'serpapi' => $this->serpApiService->cacheKeyFor($lat, $lng, $queryTerm),
                'socrata' => $this->socrataService->cacheKeyFor($lat, $lng, $queryTerm),
                'bizdata' => $this->bizData->cacheKeyFor($lat, $lng, $queryTerm),
                'overpass' => $this->overpass->cacheKeyFor($lat, $lng, $cuisine->slug),
                default => $this->buildCacheKey($label, $lat, $lng, $queryTerm),
            };

            // Each source's consumer expects its canonical cuisine string: the
            // humanized query term for query-style sources, the slug for Overpass
            // (its cuisine config lookup is slug-keyed).
            $consumeCuisine = $label === 'overpass' ? $cuisine->slug : $queryTerm;

            $normalized = match ($label) {
                'bizdata' => $this->bizData->consumePoolResponses($responses, $lat, $lng, $consumeCuisine, $cacheKey),
                'serpapi' => $this->serpApiService->consumePoolResponses($responses, $lat, $lng, $consumeCuisine, $cacheKey),
                'socrata' => $this->socrataService->consumePoolResponses($responses, $lat, $lng, $consumeCuisine, $cacheKey),
                'overpass' => $this->overpass->consumePoolResponses($responses, $lat, $lng, $consumeCuisine, $cacheKey),
                default => [],
            };

            $venues = [];
            foreach ($normalized as $r) {
                $venues[] = match ($label) {
                    'bizdata' => $this->bizData->normalizeForEnrichment($r),
                    'serpapi' => $this->serpApiService->normalizeForEnrichment($r),
                    'socrata' => $this->socrataService->normalizeForEnrichment($r),
                    'overpass' => $this->overpass->normalizeForEnrichment($r),
                    default => [],
                };
            }

            return $venues;
        } catch (\Throwable $e) {
            Log::channel('enrichment')->warning("{$label} pool response consumption failed", ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Build cache key for a source request.
     */
    private function buildCacheKey(string $label, float $lat, float $lng, string $cuisine): string
    {
        return "{$label}:".md5(serialize(compact('lat', 'lng', 'cuisine')));
    }

    /**
     * Process a single free venue: build attributes, upsert, attach cuisine.
     * Upserts by yelp_business_id when present, else by name + ≤200m proximity.
     *
     * @param  array<string, mixed>  $venue
     */
    private function processFreeVenue(array $venue, Cuisine $cuisine, ?string $cityName = null, ?string $stateCode = null): ?Restaurant
    {
        if (empty($venue['name'])) {
            return null;
        }

        // Coordinates can be absent on real responses (e.g. a Yelp business it
        // could not geocode still carries rating/reviews/address). The lat/lng
        // columns are nullable and the nearby() scope excludes null-coord rows,
        // so persist the venue rather than silently dropping its data.
        if ($venue['lat'] === null || $venue['lng'] === null) {
            Log::channel('enrichment')->info('Persisting free venue without coordinates', [
                'name' => $venue['name'],
                'source' => $venue['source'] ?? null,
            ]);
        }

        $rating = $venue['google_rating'] ?? null;
        $reviewCount = $venue['google_review_count'] ?? 0;

        $attributes = [
            'name' => $venue['name'],
            'address' => $venue['address'] ?? null,
            'city' => $venue['city'] ?? $cityName,
            'state' => $venue['state'] ?? $stateCode,
            'postal_code' => $venue['postal_code'] ?? null,
            'country' => $venue['country'] ?? 'US',
            'latitude' => $venue['lat'] ?? null,
            'longitude' => $venue['lng'] ?? null,
            'phone' => $venue['phone'] ?? null,
            'website_url' => $venue['website_url'] ?? $venue['website'] ?? null,
            'price_range' => $venue['price_range'] ?? null,
            'description' => $venue['description'] ?? null,
            'photo_url' => $venue['photo_url'] ?? null,
            'photo_source' => empty($venue['photo_url']) ? null : ($venue['photo_source'] ?? (($venue['source'] ?? null) === 'overpass' ? 'osm' : null)),
            'opening_hours' => $venue['opening_hours'] ?? null,
            'yelp_rating' => $venue['yelp_rating'] ?? null,
            'yelp_review_count' => $venue['yelp_review_count'] ?? 0,
            'google_rating' => isset($rating) && is_numeric($rating) ? (float) $rating : null,
            'google_review_count' => isset($reviewCount) && is_numeric($reviewCount) ? (int) $reviewCount : 0,
            'features' => ! empty($venue['features']) ? $venue['features'] : null,
            'is_active' => true,
        ];

        $attributes = $this->restaurantValidation->normalize($attributes);

        $yelpId = $venue['yelp_business_id'] ?? null;

        if (! empty($yelpId)) {
            $attributes['yelp_business_id'] = $yelpId;
        }

        // Resolve an existing row for this physical venue without creating
        // cross-source duplicates or clobbering keyed-source data:
        //  - by yelp id first (finds prior Yelp rows), then
        //  - by name + proximity, restricted to rows with NO yelp id, so an OSM
        //    venue never overwrites a Yelp-enriched row while a Yelp venue can
        //    still promote a prior OSM-only row.
        $existing = ! empty($yelpId)
            ? Restaurant::where('yelp_business_id', $yelpId)->first()
            : null;

        // Proximity matching needs coords; a null-coord venue can't be matched
        // (and findByNameAndProximity's float-typed params reject null anyway).
        $existing ??= ($venue['lat'] !== null && $venue['lng'] !== null)
            ? $this->findByNameAndProximity($venue['name'], $venue['lat'], $venue['lng'])
            : null;

        if ($existing !== null) {
            $existing->update($attributes);
            $restaurant = $existing;
        } else {
            $restaurant = Restaurant::create($attributes);
        }

        // Only attach the searched cuisine when the venue actually carries
        // evidence for it. The offline enrichment grid runs every city x
        // cuisine, and unfiltered sources (BizData ignores its query param)
        // return ALL nearby restaurants — so tagging every venue would stamp
        // wrong cuisines onto the pivot. The venue is persisted either way;
        // the tag just isn't attached. Evidence = name / place_types /
        // description match, or the venue's own OSM `cuisine` tag.
        $hasEvidence = $this->cuisineMatcher->venueMatchesCuisine($venue, $cuisine->slug);

        if (! $hasEvidence) {
            foreach (($venue['cuisines'] ?? []) as $venueCuisine) {
                $slug = strtolower((string) ($venueCuisine['slug'] ?? ''));
                // Exact seeded-slug match (cuisine=vietnamese for vietnamese).
                if ($slug !== '' && $slug === $cuisine->slug) {
                    $hasEvidence = true;

                    break;
                }
                // Keyword-level OSM tag (cuisine=mediterranean / arab / kebab for
                // a Lebanese/Middle-Eastern tag): credit it against the searched
                // cuisine's own lexicon. Mirrors the live-search stamp, so a venue
                // tagged with a sibling keyword is tagged, not silently dropped.
                if ($slug !== '' && $this->cuisineMatcher->matchesEvidence($slug, $cuisine->slug)) {
                    $hasEvidence = true;

                    break;
                }
            }
        }

        if ($hasEvidence) {
            $restaurant->cuisines()->syncWithoutDetaching([$cuisine->id]);
        }

        // Post-creation backfill: if still no website_url, check cache by phone
        if (empty($restaurant->website_url) && ! empty($venue['phone'])) {
            $cachedUrl = $this->findWebsiteByPhoneInCache($venue['phone']);
            if ($cachedUrl !== null) {
                $restaurant->update(['website_url' => $cachedUrl]);
            }
        }

        $populatedFields = [];
        foreach (['phone', 'website_url', 'price_range', 'description', 'photo_url', 'opening_hours', 'google_rating', 'google_review_count', 'features'] as $f) {
            if (! empty($attributes[$f])) {
                $populatedFields[] = $f;
            }
        }

        Log::channel('enrichment')->info(
            $existing ? 'Venue updated' : 'Venue created',
            [
                'origin' => 'enrichment',
                'restaurant_id' => $restaurant->id,
                'restaurant_name' => $restaurant->name,
                'source' => $venue['source'] ?? null,
                'cuisine' => $cuisine->name,
                'has_coords' => $venue['lat'] !== null && $venue['lng'] !== null,
                'populated_fields' => $populatedFields,
                'google_rating' => $attributes['google_rating'] ?? null,
                'google_review_count' => $attributes['google_review_count'],
            ]
        );

        return $restaurant;
    }

    /**
     * Search the external API cache for a website URL matching a phone number.
     */
    private function findWebsiteByPhoneInCache(string $phone): ?string
    {
        $phoneDigits = substr((string) preg_replace('/\D+/', '', $phone), -10);
        if (strlen($phoneDigits) !== 10) {
            return null;
        }

        $cacheEntries = ExternalApiCache::whereIn('source', ['serpapi', 'preview', 'bizdata'])->get();

        foreach ($cacheEntries as $entry) {
            $venues = $entry->data;

            foreach ($venues as $venue) {
                $cachedPhone = $venue['phone'] ?? null;
                if (empty($cachedPhone)) {
                    continue;
                }

                $cachedDigits = substr(preg_replace('/\D+/', '', $cachedPhone), -10);
                if ($cachedDigits === $phoneDigits) {
                    $website = $venue['website'] ?? $venue['website_url'] ?? null;
                    if (! empty($website)) {
                        return $website;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Optional award enrichment (Wikidata, free): one SPARQL box query for the
     * search area, then match each persisted restaurant by name + proximity.
     *
     * @param  Collection<int, Restaurant>  $restaurants
     */
    private function enrichAwards(Collection $restaurants, float $lat, float $lng): void
    {
        if ($restaurants->isEmpty()) {
            return;
        }

        try {
            $awarded = $this->wikidata->findAwardedRestaurantsInBox(
                $lat - self::AWARD_BOX_DEGREES,
                $lng - self::AWARD_BOX_DEGREES,
                $lat + self::AWARD_BOX_DEGREES,
                $lng + self::AWARD_BOX_DEGREES,
            );

            foreach ($restaurants as $restaurant) {
                $hasAward = $this->wikidata->hasAwardInSet(
                    $restaurant->name ?? '',
                    (float) $restaurant->latitude,
                    (float) $restaurant->longitude,
                    $awarded,
                );

                if ((bool) $restaurant->has_award !== $hasAward) {
                    $restaurant->update(['has_award' => $hasAward]);
                    Log::channel('enrichment')->info('Award status changed', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'has_award' => $hasAward,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('enrichment')->debug('Award enrichment skipped', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Optional website scraper enrichment (free): scrape restaurant's own website
     * for opening hours and menu data. Runs only for restaurants with a website_url.
     * Mutates the passed models in place.
     *
     * @param  Collection<int, Restaurant>  $restaurants
     */
    private function enrichWebsiteData(Collection $restaurants): void
    {
        $scrapedHours = 0;
        $photosFound = 0;
        $imageFallbacks = 0;
        $alreadyHave = 0;
        $noWebsite = 0;
        $failed = 0;

        foreach ($restaurants as $restaurant) {
            try {
                if (empty($restaurant->website_url)) {
                    $noWebsite++;

                    if (empty($restaurant->photo_url)) {
                        $result = $this->websiteScraper->searchImageForRestaurant($restaurant);
                        if ($result !== null) {
                            $restaurant->update(['photo_url' => $result['url'], 'photo_source' => $result['source']]);
                            $photosFound++;
                            Log::channel('enrichment')->info('Image enrichment found photo for restaurant without website', [
                                'restaurant_id' => $restaurant->id,
                                'restaurant_name' => $restaurant->name,
                                'photo_url' => $result['url'],
                            ]);
                        }
                    }

                    continue;
                }

                // Note: photo backfill runs even when opening_hours already exist
                // (previously the early-continue below skipped the photo fallback
                // for hours-complete rows, leaving them permanently photo-less).
                $scrapedData = empty($restaurant->opening_hours)
                    ? $this->websiteScraper->scrape($restaurant->website_url)
                    : null;

                if (! empty($restaurant->opening_hours)) {
                    $alreadyHave++;
                } elseif ($scrapedData !== null && (! empty($scrapedData['opening_hours']) || ! empty($scrapedData['menu_url']) || ! empty($scrapedData['photo_url']))) {
                    $updates = [];
                    if (! empty($scrapedData['opening_hours'])) {
                        $updates['opening_hours'] = $scrapedData['opening_hours'];
                    }
                    if (! empty($scrapedData['menu_url'])) {
                        $updates['menu_url'] = $scrapedData['menu_url'];
                    }
                    if (! empty($scrapedData['photo_url']) && empty($restaurant->photo_url)) {
                        $updates['photo_url'] = $scrapedData['photo_url'];
                        $updates['photo_source'] = 'website';
                    }
                    if (! empty($updates)) {
                        $restaurant->update($updates);
                    }
                    $scrapedHours++;

                    Log::channel('enrichment')->info('Website scrape found data', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $restaurant->website_url,
                        'menu_url' => $scrapedData['menu_url'] ?? null,
                        'opening_hours_count' => isset($scrapedData['opening_hours']) ? (is_array($scrapedData['opening_hours']) ? count($scrapedData['opening_hours']) : 1) : 0,
                        'photo_url' => $scrapedData['photo_url'] ?? null,
                    ]);
                } else {
                    if (empty($restaurant->opening_hours)) {
                        $failed++;
                    }

                    Log::channel('enrichment')->info('Website scrape returned no opening hours', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $restaurant->website_url,
                        'scraped_data_null' => $scrapedData === null,
                    ]);
                }

                // Photo fallback: scrape og:image (or search free sources) for any
                // row that still lacks a photo, regardless of hours/menu state.
                if (empty($restaurant->photo_url)) {
                    $result = $this->websiteScraper->searchImageForRestaurant($restaurant);
                    if ($result !== null) {
                        $restaurant->update(['photo_url' => $result['url'], 'photo_source' => $result['source']]);
                        $imageFallbacks++;
                        Log::channel('enrichment')->info('Image enrichment found photo via fallback', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'photo_url' => $result['url'],
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::channel('enrichment')->warning('Website scraping failed for restaurant', [
                    'restaurant_id' => $restaurant->id,
                    'website_url' => $restaurant->website_url ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('enrichment')->info('Website enrichment summary', [
            'total_processed' => $restaurants->count(),
            'already_have_opening_hours' => $alreadyHave,
            'no_website_url' => $noWebsite,
            'hours_scraped' => $scrapedHours,
            'photos_found' => $photosFound,
            'image_fallbacks' => $imageFallbacks,
            'scrape_failed_or_empty' => $failed,
        ]);
    }

    /**
     * Optional AI enrichment (async): dispatch jobs to fill data gaps.
     * Never runs on the request path (queue only). No-op without AI key.
     *
     * @param  Collection<int, Restaurant>  $restaurants
     */
    private function enrichWithAi(Collection $restaurants): void
    {
        // No key = no-op (service returns null, no job dispatched)
        if (empty(config('services.ai.api_key'))) {
            return;
        }

        foreach ($restaurants as $restaurant) {
            try {
                // Skip if recently enriched (within 7 days)
                if (! empty($restaurant->ai_metadata['enriched_at'])) {
                    $enrichedAt = now()->parse($restaurant->ai_metadata['enriched_at']);
                    if ($enrichedAt->gt(now()->subDays(7))) {
                        continue;
                    }
                }

                // Dispatch async job (never blocks request path)
                EnrichRestaurantWithAi::dispatch($restaurant->id);
            } catch (\Throwable $e) {
                Log::channel('enrichment')->warning('AI enrichment dispatch failed for restaurant', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Find an existing restaurant by exact name within the match radius.
     * Composite upsert key for venues without a yelp_business_id. Restricted to
     * rows with no yelp id so an OSM venue never matches (and overwrites) a row
     * already enriched by Yelp; pre-filtered by a tight bbox to bound candidates.
     */
    private function findByNameAndProximity(string $name, float $lat, float $lng): ?Restaurant
    {
        return Restaurant::where('name', $name)
            ->whereNull('yelp_business_id')
            ->whereNotNull('latitude')
            ->whereBetween('latitude', [$lat - 0.01, $lat + 0.01])
            ->whereBetween('longitude', [$lng - 0.01, $lng + 0.01])
            ->get()
            ->first(fn (Restaurant $r) => $this->venuePipeline->haversineKm(
                $lat,
                $lng,
                (float) $r->latitude,
                (float) $r->longitude,
            ) <= config('restaurant-finder.dedup.match_radius_km', 0.2));
    }

    /**
     * Count real (non-cached) SerpApi calls made in the last 30 days.
     * Uses ExternalApiCache to estimate: each cache entry represents one real call.
     */
    private function countRealSerpApiCallsLast30Days(): int
    {
        return SerpApiCallLog::countLast30Days();
    }

    /**
     * Check if a specific SerpApi cache entry is fresh (exists and not expired).
     */
    private function isSerpApiCacheFresh(float $lat, float $lng, string $query): bool
    {
        // Delegate to SerpApiService::cacheKeyFor so the skip-check key is
        // byte-identical to the key enrichment stores under AND the read path
        // reads — the fix for the re-fetch leak (spec-072).
        return ExternalApiCache::findByKey(
            $this->serpApiService->cacheKeyFor($lat, $lng, $query)
        ) !== null;
    }

    /**
     * Throttled enrichment for all cities with SerpApi quota protection.
     * Rotates through city×cuisine combos, skipping cache-fresh ones,
     * and stops when the combos-per-run cap, per-run cap, monthly budget,
     * or wall-clock max runtime is reached.
     *
     * @return array{combos_processed: int, combos_cap_reached: bool, total_processed: int, real_calls_made: int, cache_hits_skipped: int, quota_exhausted: bool, per_run_cap_reached: bool, max_runtime_reached: bool}
     */
    public function enrichAllCitiesThrottled(): array
    {
        $cities = config('restaurant-finder.cities', []);
        $cuisines = $this->getConfiguredCuisines();

        if (empty($cities) || $cuisines->isEmpty()) {
            return [
                'combos_processed' => 0,
                'combos_cap_reached' => false,
                'total_processed' => 0,
                'real_calls_made' => 0,
                'cache_hits_skipped' => 0,
                'quota_exhausted' => false,
                'per_run_cap_reached' => false,
                'max_runtime_reached' => false,
            ];
        }

        $perRunCap = config('restaurant-finder.enrich.per_run_cap', 40);
        $monthlyBudget = config('restaurant-finder.enrich.monthly_budget', 40);
        $combosPerRun = (int) config('restaurant-finder.enrich.combos_per_run', 60);
        $maxRuntimeMinutes = (float) config('restaurant-finder.enrich.max_runtime_minutes', 300);
        $startedAt = microtime(true);

        $realCallsThisMonth = $this->countRealSerpApiCallsLast30Days();
        $realCallsThisRun = 0;
        $cacheHitsSkipped = 0;
        $totalProcessed = 0;
        $combosProcessed = 0;
        $quotaExhausted = false;
        $perRunCapReached = false;
        $combosCapReached = false;
        $maxRuntimeReached = false;

        Log::channel('enrichment')->info('Starting throttled enrichment', [
            'per_run_cap' => $perRunCap,
            'monthly_budget' => $monthlyBudget,
            'combos_per_run' => $combosPerRun,
            'max_runtime_minutes' => $maxRuntimeMinutes,
            'real_calls_this_month' => $realCallsThisMonth,
        ]);

        $combos = $this->buildCityCuisineGrid($cities, $cuisines);

        foreach ($combos as $combo) {
            // Wall-clock guard: a fail-open free-source sweep (per-venue website
            // scrape + image search) can take ~15h for 60 combos and outlive the
            // schedule mutex, overlapping the next day's run. Bound the total run
            // so it always finishes within the mutex window.
            if ($maxRuntimeMinutes > 0 && ((microtime(true) - $startedAt) / 60) >= $maxRuntimeMinutes) {
                $maxRuntimeReached = true;
                Log::channel('enrichment')->info('Throttled enrichment max runtime reached, stopping', [
                    'elapsed_minutes' => round((microtime(true) - $startedAt) / 60, 1),
                    'max_runtime_minutes' => $maxRuntimeMinutes,
                    'combos_processed' => $combosProcessed,
                ]);
                break;
            }

            if ($combosProcessed >= $combosPerRun) {
                $combosCapReached = true;
                break;
            }
            $combosProcessed++;

            $cityName = $combo['city'];
            $lat = $combo['lat'];
            $lng = $combo['lng'];
            $cuisine = $combo['cuisine'];

            $serpApiFresh = $this->isSerpApiCacheFresh($lat, $lng, $this->cuisineMatcher->humanize($cuisine->slug));

            if ($serpApiFresh) {
                $cacheHitsSkipped++;

                // M4: SerpApi cache is fresh, but free sources (BizData, Overpass,
                // Socrata) have 24h TTLs — still run them to discover new venues.
                try {
                    $stateCode = config('restaurant-finder.city_states.'.$cityName);
                    $this->enrichByCuisine($lat, $lng, $cuisine, true, $cityName, $stateCode);
                    Log::channel('enrichment')->debug('Ran free sources for cache-fresh combo', [
                        'city' => $cityName,
                        'cuisine' => $cuisine->name,
                    ]);
                } catch (\Throwable $e) {
                    Log::channel('enrichment')->error('Failed to enrich combo (free only)', [
                        'city' => $cityName,
                        'cuisine' => $cuisine->name,
                        'message' => $e->getMessage(),
                    ]);
                }

                continue;
            }

            // Fail-open: when SerpApi has flagged the account exhausted (429
            // "out of searches"), no live call can succeed for the retry window.
            // Don't stop the grid — run the free sources (BizData/Overpass/
            // Socrata + the AI/photo/social/website enrichment they trigger) for
            // this combo anyway, and keep quota_exhausted surfaced. Ratings
            // backfill later via the need-ordering grid (buildCityCuisineGrid).
            if ($this->serpApiService->isProviderExhausted()) {
                $quotaExhausted = true;

                try {
                    $stateCode = config('restaurant-finder.city_states.'.$cityName);
                    $count = $this->enrichByCuisine($lat, $lng, $cuisine, true, $cityName, $stateCode);
                    $totalProcessed++;
                    Log::channel('enrichment')->info('SerpApi provider exhausted; ran free sources for combo', [
                        'city' => $cityName,
                        'cuisine' => $cuisine->name,
                        'restaurants_enriched' => $count,
                    ]);
                } catch (\Throwable $e) {
                    Log::channel('enrichment')->error('Failed to enrich combo (free only, provider exhausted)', [
                        'city' => $cityName,
                        'cuisine' => $cuisine->name,
                        'message' => $e->getMessage(),
                    ]);
                }

                continue;
            }

            if ($realCallsThisMonth >= $monthlyBudget) {
                $quotaExhausted = true;
                Log::channel('enrichment')->info('Monthly budget exhausted, stopping enrichment', [
                    'real_calls_this_month' => $realCallsThisMonth,
                    'monthly_budget' => $monthlyBudget,
                ]);
                break;
            }

            if ($realCallsThisRun >= $perRunCap) {
                $perRunCapReached = true;
                Log::channel('enrichment')->info('Per-run cap reached, stopping enrichment', [
                    'real_calls_this_run' => $realCallsThisRun,
                    'per_run_cap' => $perRunCap,
                ]);
                break;
            }

            // Enrich this combo (will make one real SerpApi call)
            try {
                $stateCode = config('restaurant-finder.city_states.'.$cityName);
                $count = $this->enrichByCuisine($lat, $lng, $cuisine, false, $cityName, $stateCode);
                $realCallsThisRun++;
                $realCallsThisMonth++;
                $totalProcessed++;
                Log::channel('enrichment')->info('Enriched combo', [
                    'city' => $cityName,
                    'cuisine' => $cuisine->name,
                    'restaurants_enriched' => $count,
                    'real_calls_this_run' => $realCallsThisRun,
                ]);
            } catch (\Throwable $e) {
                Log::channel('enrichment')->error('Failed to enrich combo', [
                    'city' => $cityName,
                    'cuisine' => $cuisine->name,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('enrichment')->info('Throttled enrichment complete', [
            'combos_processed' => $combosProcessed,
            'total_processed' => $totalProcessed,
            'real_calls_made' => $realCallsThisRun,
            'cache_hits_skipped' => $cacheHitsSkipped,
            'quota_exhausted' => $quotaExhausted,
            'per_run_cap_reached' => $perRunCapReached,
            'combos_cap_reached' => $combosCapReached,
            'max_runtime_reached' => $maxRuntimeReached,
        ]);

        return [
            'combos_processed' => $combosProcessed,
            'combos_cap_reached' => $combosCapReached,
            'total_processed' => $totalProcessed,
            'real_calls_made' => $realCallsThisRun,
            'cache_hits_skipped' => $cacheHitsSkipped,
            'quota_exhausted' => $quotaExhausted,
            'per_run_cap_reached' => $perRunCapReached,
            'max_runtime_reached' => $maxRuntimeReached,
        ];
    }

    /**
     * Build all city×cuisine combos ordered to maximize rating coverage.
     *
     * Cities with the most unrated restaurants come first so each real SerpApi
     * call (the only rating source) targets the neediest rows. Brand-new cities
     * (no rows yet) sort ahead of low-need populated ones so they still get
     * seeded. Cuisines are shuffled within each city so a run doesn't always
     * hammer the same cuisine. Replaces the previous blind shuffle, which spent
     * quota on a coin-flip of combo need.
     *
     * @param  array<string, array{float, float}>  $cities
     * @param  Collection<int, Cuisine>  $cuisines
     * @return array<array{city:string, lat:float, lng:float, cuisine:Cuisine}>
     */
    private function buildCityCuisineGrid(array $cities, Collection $cuisines): array
    {
        $needByCity = Restaurant::query()
            ->selectRaw('city, COUNT(*) as count')
            ->where(function ($q) {
                $q->whereNull('google_rating')
                    ->orWhere('google_rating', '<=', 0);
            })
            ->groupBy('city')
            ->pluck('count', 'city')
            ->toArray();

        $totalByCity = Restaurant::query()
            ->selectRaw('city, COUNT(*) as count')
            ->groupBy('city')
            ->pluck('count', 'city')
            ->toArray();

        $orderedCities = collect($cities)
            ->map(function ($coords, $name) use ($needByCity, $totalByCity) {
                $need = (int) ($needByCity[$name] ?? 0);
                $total = (int) ($totalByCity[$name] ?? 0);

                return [
                    'name' => $name,
                    'lat' => $coords[0],
                    'lng' => $coords[1],
                    // Existing gaps rank by size; a brand-new city (no rows yet)
                    // counts as need 1 so it still gets seeded ahead of fully-rated
                    // cities but behind cities that actually have unrated rows.
                    'need' => $need > 0 ? $need : ($total === 0 ? 1 : 0),
                ];
            })
            ->sortByDesc('need')
            ->values();

        $combos = [];
        foreach ($orderedCities as $city) {
            foreach ($cuisines->shuffle() as $cuisine) {
                $combos[] = [
                    'city' => $city['name'],
                    'lat' => $city['lat'],
                    'lng' => $city['lng'],
                    'cuisine' => $cuisine,
                ];
            }
        }

        return $combos;
    }

    /**
     * Get cuisines filtered to the configured set, falling back to all
     * when no config is defined (preserves test behavior).
     *
     * @return Collection<int, Cuisine>
     */
    private function getConfiguredCuisines(): Collection
    {
        $configuredCuisines = config('restaurant-finder.cuisines', []);

        if (empty($configuredCuisines)) {
            return Cuisine::all();
        }

        $slugs = array_map(fn (string $name) => str()->slug($name), $configuredCuisines);

        return Cuisine::whereIn('slug', $slugs)->get();
    }
}
