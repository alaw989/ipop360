<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Models\SerpApiCallLog;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SerpApiService
{
    private ?string $apiKey;

    /**
     * Cache key for the provider-level exhaustion flag. When SerpApi returns a
     * 429 "out of searches", the account itself is spent regardless of what the
     * app's cache-store tracker says — flag it so live search + enrichment stop
     * hammering a dead account for the retry window.
     */
    private const EXHAUSTED_CACHE_KEY = 'serpapi_provider_exhausted';

    private const EXHAUSTED_RETRY_HOURS = 24;

    /**
     * Cache key for the periodic account-status snapshot synced from
     * SerpApi's own /account.json (spec-107) — the provider-confirmed truth,
     * not an inference from this app's own call history.
     */
    private const ACCOUNT_STATUS_CACHE_KEY = 'serpapi_account_status_snapshot';

    private const ACCOUNT_STATUS_CACHE_HOURS = 2;

    /**
     * Zoom level for the google_maps `ll` parameter (`@lat,lng,<zoom>z`).
     * SerpApi/Google Maps controls the search area via zoom, not a metre
     * radius. 15 ≈ neighborhood/street level, appropriate for "restaurants
     * near this point". Lower = wider area.
     */
    private const MAP_ZOOM = 11;

    /**
     * Whether the most recent consumePoolResponses() call stored real results
     * (i.e. reached the success path without recording a failure). Exposed so the
     * live-search caller can debit the per-IP limiter only on genuine success.
     */
    private bool $lastConsumePoolSucceeded = false;

    public function __construct()
    {
        $this->apiKey = config('services.serpapi.api_key');
    }

    /**
     * Is the SerpApi account flagged as exhausted (provider 429 "out of
     * searches")? When true, live search and enrichment skip SerpApi entirely
     * for the retry window instead of firing calls that just 429.
     */
    public function isProviderExhausted(): bool
    {
        return Cache::has(self::EXHAUSTED_CACHE_KEY);
    }

    /**
     * Flag the account as exhausted for a bounded window so the free sources
     * (BizData/Overpass/Socrata) keep serving while SerpApi is dead.
     */
    public function markProviderExhausted(): void
    {
        Cache::put(
            self::EXHAUSTED_CACHE_KEY,
            now()->toDateTimeString(),
            now()->addHours(self::EXHAUSTED_RETRY_HOURS),
        );
    }

    /**
     * Clear the provider-exhaustion flag early when a fresh account-status
     * sync confirms searches are actually available again, instead of
     * waiting out the blind EXHAUSTED_RETRY_HOURS timer.
     */
    public function clearProviderExhausted(): void
    {
        Cache::forget(self::EXHAUSTED_CACHE_KEY);
    }

    /**
     * Fetch SerpApi's own account status (/account.json) — reports
     * total_searches_left / this_month_usage / plan_renewal_date etc.
     * directly from the provider. This is an account-info call, NOT the
     * metered /search endpoint, so it costs zero quota. Pure fetch, no side
     * effects; returns null on a missing key, HTTP failure, a response
     * missing the expected fields, or a thrown exception.
     *
     * @return array<string, mixed>|null
     */
    public function fetchAccountStatus(): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://serpapi.com/account.json', [
                'api_key' => $this->apiKey,
            ]);

            if ($response->failed()) {
                Log::warning('SerpApi account-status fetch failed', ['status' => $response->status()]);

                return null;
            }

            $data = $response->json();
            if (! is_array($data) || ! array_key_exists('total_searches_left', $data)) {
                Log::warning('SerpApi account-status response missing expected fields');

                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::warning('SerpApi account-status fetch threw exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Sync the account-status snapshot from the provider and reconcile the
     * local exhausted flag against the authoritative total_searches_left
     * field. A fetch failure leaves the flag and cached snapshot untouched —
     * a transient network blip must never wrongly clear a real exhaustion
     * (or wrongly mark a healthy account dead).
     *
     * @return array<string, mixed>|null the synced snapshot, or null on fetch failure
     */
    public function syncAccountStatus(): ?array
    {
        $data = $this->fetchAccountStatus();
        if ($data === null) {
            return null;
        }

        $snapshot = [
            'total_searches_left' => $data['total_searches_left'] ?? null,
            'searches_per_month' => $data['searches_per_month'] ?? null,
            'this_month_usage' => $data['this_month_usage'] ?? null,
            'account_status' => $data['account_status'] ?? null,
            'plan_name' => $data['plan_name'] ?? null,
            'plan_renewal_date' => $data['plan_renewal_date'] ?? null,
            'synced_at' => now()->toIso8601String(),
        ];

        Cache::put(self::ACCOUNT_STATUS_CACHE_KEY, $snapshot, now()->addHours(self::ACCOUNT_STATUS_CACHE_HOURS));

        $searchesLeft = $data['total_searches_left'];
        if (is_numeric($searchesLeft)) {
            if ($searchesLeft <= 0) {
                $this->markProviderExhausted();
            } else {
                $this->clearProviderExhausted();
            }
        }

        return $snapshot;
    }

    /**
     * Read-only accessor for the cached account-status snapshot — no live
     * network call, so admin dashboard loads stay fast. The scheduled
     * serpapi:sync-account-status command is the only writer.
     *
     * @return array<string, mixed>|null
     */
    public function cachedAccountSnapshot(): ?array
    {
        return Cache::get(self::ACCOUNT_STATUS_CACHE_KEY);
    }

    /**
     * Detect a provider-level exhaustion response (429 whose body says the
     * searches are spent). Called on every failure path so the flag self-heals.
     */
    public function detectProviderExhaustion(?Response $response): void
    {
        if ($response === null) {
            return;
        }

        if ($response->status() !== 429) {
            return;
        }

        $error = (string) ($response->json()['error'] ?? $response->body());
        if (str_contains(strtolower($error), 'out of searches')) {
            $this->markProviderExhausted();
            Log::warning('SerpApi provider exhausted (out of searches); pausing live fetches', [
                'retry_hours' => self::EXHAUSTED_RETRY_HOURS,
            ]);
        }
    }

    /**
     * Record a real outbound SerpApi call that FAILED so quota accounting stays
     * honest. Failed calls still burn (or attempt) quota at SerpApi, but the old
     * code only wrote cache rows on success — so 429/5xx/connection failures were
     * invisible to `serpapi_calls_last_30d` and the circuit breaker tripped late.
     *
     * Writing an empty row under the same cache key also briefly self-heals: the
     * empty data gets the short empty_retry_hours TTL (see storeByKey), so a
     * transient failure isn't retried for the full source TTL, but still counts
     * toward the 30-day quota via `fetched_at`.
     */
    private function recordFailedCall(string $cacheKey): void
    {
        ExternalApiCache::storeByKey(
            $cacheKey,
            [],
            now()->addHours((int) config('restaurant-finder.cache.serpapi_ttl_hours', 720))
        );
    }

    /**
     * Search Google Maps for restaurants via SerpApi.
     * Returns normalized restaurant data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(float $lat, float $lng, ?string $query = null): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $cacheKey = $this->cacheKeyFor($lat, $lng, $query);

        $cached = ExternalApiCache::findByKey($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Honor provider exhaustion: a flagged account ("out of searches") can't
        // return anything live for the retry window. Serve nothing instead of
        // firing a doomed call (consistent with poolRequestsFor).
        if ($this->isProviderExhausted()) {
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->get('https://serpapi.com/search', [
                    'engine' => 'google_maps',
                    'q' => $this->buildQuery($query),
                    'll' => "@{$lat},{$lng},".self::MAP_ZOOM.'z',
                    'type' => 'search',
                    'api_key' => $this->apiKey,
                ]);

            SerpApiCallLog::record();

            if ($response->failed()) {
                $this->detectProviderExhaustion($response);
                $this->recordFailedCall($cacheKey);
                Log::warning('SerpApi request failed', [
                    'status' => $response->status(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return [];
            }

            $data = $response->json();
            $localResults = $data['local_results'] ?? [];

            $results = $this->normalizeResults($localResults, $lat, $lng);

            ExternalApiCache::storeByKey($cacheKey, $results, now()->addHours((int) config('restaurant-finder.cache.serpapi_ttl_hours', 720)));

            return $results;
        } catch (\Throwable $e) {
            SerpApiCallLog::record();
            $this->recordFailedCall($cacheKey);
            Log::warning('SerpApi threw exception', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return [];
        }
    }

    /**
     * Normalize raw SerpApi local_results to the shared venue shape.
     * Public method for use after parallel fetch.
     *
     * @param  array<int, array<string, mixed>>  $localResults
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRaw(array $localResults, float $searchLat, float $searchLng): array
    {
        return $this->normalizeResults($localResults, $searchLat, $searchLng);
    }

    /**
     * Cache key for a SerpApi query. Shared by search() and the live
     * concurrent-pool path (byte-identical) — critical because SerpApi is the
     * quota-constrained source.
     *
     * Coordinates are rounded to ~3 dp IN THE KEY (not the fetch) so sub-100m
     * GPS/IP-geo jitter no longer mints distinct cache entries and re-burns
     * quota (spec-073). The outbound ll= call still uses full-precision coords.
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $query = null): string
    {
        $lat = round($lat, 3);
        $lng = round($lng, 3);

        return 'serpapi:'.md5(serialize(compact('lat', 'lng', 'query')));
    }

    /**
     * Build the concurrent-pool request for the live read path. Returns []
     * (disabled) when no API key is configured, so the cache-pass can still
     * short-circuit a prior keyed result while a keyless deployment skips the
     * outbound call entirely.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, RequestSpec>
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $query = null, array $context = []): array
    {
        if (empty($this->apiKey) || $this->isProviderExhausted()) {
            return [];
        }

        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.http_timeout', 8.0)
            : 15.0;

        return [
            new RequestSpec(
                method: 'GET',
                url: 'https://serpapi.com/search',
                query: [
                    'engine' => 'google_maps',
                    'q' => $this->buildQuery($query),
                    'll' => "@{$lat},{$lng},".self::MAP_ZOOM.'z',
                    'type' => 'search',
                    'api_key' => $this->apiKey,
                ],
                timeout: $timeout,
            ),
        ];
    }

    /**
     * Parse a pooled SerpApi response into the raw local_results array (the
     * shape stored in ExternalApiCache). Returns null on HTTP failure.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            $this->detectProviderExhaustion($response);

            return null;
        }

        $data = $response->json();

        return $data['local_results'] ?? [];
    }

    /**
     * Whether the most recent consumePoolResponses() call stored real results
     * (success path) rather than recording a failed call.
     */
    public function lastConsumePoolSucceeded(): bool
    {
        return $this->lastConsumePoolSucceeded;
    }

    /**
     * Consume pooled responses for the live read path: parse, cache the raw
     * payload (30-day SerpApi TTL), and normalize. Quota-safe: the cache pass
     * runs before this, so a repeat search never reaches here.
     *
     * @param  array<int, Response|\Throwable>  $responses
     * @return array<int, array<string, mixed>>
     */
    public function consumePoolResponses(array $responses, float $lat, float $lng, ?string $cuisine, string $cacheKey): array
    {
        $this->lastConsumePoolSucceeded = false;

        foreach ($responses as $response) {
            SerpApiCallLog::record();

            if ($response instanceof \Throwable) {
                $this->recordFailedCall($cacheKey);

                continue;
            }

            $localResults = $this->parsePoolResponse($response, $lat, $lng);
            if ($localResults === null) {
                $this->recordFailedCall($cacheKey);

                continue;
            }

            ExternalApiCache::storeByKey(
                $cacheKey,
                $localResults,
                now()->addHours((int) config('restaurant-finder.cache.serpapi_ttl_hours', 720))
            );

            $this->lastConsumePoolSucceeded = true;

            return $this->normalizeRaw($localResults, $lat, $lng);
        }

        return [];
    }

    /**
     * Build the search query for SerpApi.
     *
     * A BARE cuisine adjective returns 0 results from google_maps for many
     * cuisines — verified directly against SerpApi: "jamaican"→0, "caribbean"→0,
     * (same for cuban/ethiopian/trinidadian/haitian/nigerian in production),
     * while the common adjectives happen to work bare ("italian"→20, "chinese",
     * "thai", "mexican"), which masked this for months. Appending the "restaurant"
     * noun makes Google return actual places for EVERY cuisine (jamaican 0→20,
     * caribbean 0→20, all confirmed). The noun is NOT redundant like the old
     * "near me" suffix was — geo-anchoring comes from ll=, but "restaurant" is
     * the search discriminator; without it Google's bare-adjective lookup is a
     * coin flip per cuisine. The cache key is built from the raw $query term
     * (not this output), so this never turns over cache entries.
     */
    private function buildQuery(?string $query): string
    {
        $query = trim($query ?? '');

        // Unscoped search: the generic default Google Maps understands.
        if ($query === '') {
            return 'restaurants';
        }

        // Scoped cuisine/category search: "<cuisine> restaurant".
        return $query.' restaurant';
    }

    /**
     * Normalize SerpApi results to the shared venue shape.
     *
     * @param  array<int, array<string, mixed>>  $localResults
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResults(array $localResults, float $searchLat, float $searchLng): array
    {
        $results = [];

        foreach ($localResults as $r) {
            $name = $r['title'] ?? null;
            if (! $name) {
                continue;
            }

            $lat = $r['gps_coordinates']['latitude'] ?? null;
            $lng = $r['gps_coordinates']['longitude'] ?? null;
            $distance = $lat !== null && $lng !== null
                ? $this->haversineKm($searchLat, $searchLng, (float) $lat, (float) $lng)
                : null;

            $fingerprint = $name.($lat ?? '').($lng ?? '');

            // Parse rating and reviews from SerpApi response
            $rating = $r['rating'] ?? null;
            $reviews = $r['reviews'] ?? null;
            $priceLevel = $this->parsePriceRange($r);
            // Size Google's thumbnail so we don't ship multi-MB originals.
            // See sizeGoogleThumbnail(): only lh[3-6].googleusercontent.com URLs are touched.
            $photo = $this->sizeGoogleThumbnail($r['thumbnail'] ?? null);

            // Capture Google's structured place classification. SerpApi's
            // q="<cuisine>" still leaks off-cuisine rows (spec-028), so the
            // cuisine-relevance filter inspects this against a rival-cuisine set; and
            // spec-042's filterNonRestaurants() drops non-food places (churches, salons,
            // groceries). 'type' is the primary field (string); 'types' is the alternate
            // array form. SerpApi *also* returns a snake_case `place_types` enum array on
            // some rows (beauty_salon, hair_care, restaurant, establishment, ...) — the
            // authoritative Google type. Capture it too (spec-046): a waxing salon often
            // arrives with NO human-readable type/types but a populated enum, so without
            // this its place_types is [] and it slips through the non-restaurant filter.
            $rawType = $r['type'] ?? null;
            $rawTypes = $r['types'] ?? null;
            $rawEnums = $r['place_types'] ?? null;
            $placeTypes = [];
            if (is_array($rawTypes)) {
                $placeTypes = array_values(array_filter($rawTypes, 'is_string'));
            } elseif (is_string($rawType) && $rawType !== '') {
                $placeTypes = [$rawType];
            }
            if (is_array($rawEnums)) {
                $existingLower = array_map('strtolower', $placeTypes);
                foreach ($rawEnums as $enum) {
                    if (is_string($enum) && $enum !== '' && ! in_array(strtolower($enum), $existingLower, true)) {
                        $placeTypes[] = $enum;
                        $existingLower[] = strtolower($enum);
                    }
                }
            }

            $results[] = [
                'id' => -1 * abs(crc32('serpapi:'.$fingerprint)),
                'name' => $name,
                'slug' => Str::slug($name).'-'.substr(md5($fingerprint), 0, 6),
                'description' => $r['description'] ?? null,
                'address' => $this->parseAddress($r),
                'city' => $r['city'] ?? null,
                'state' => $r['state'] ?? null,
                'lat' => $lat,
                'lng' => $lng,
                'photo_url' => $photo,
                'photo_source' => $photo ? 'google_thumbnail' : null,
                'photos' => $photo ? [$photo] : [],
                'price_range' => $priceLevel,
                'phone' => $r['phone'] ?? null,
                'website_url' => $r['website'] ?? $r['links']['website'] ?? null,
                'opening_hours' => $r['operating_hours'] ?? null,
                'google_rating' => is_numeric($rating) ? (float) $rating : null,
                'google_review_count' => is_numeric($reviews) ? (int) $reviews : 0,
                'yelp_rating' => null,
                'yelp_review_count' => 0,
                'has_award' => false,
                'popularity_score' => 0,
                'distance' => $distance !== null ? round($distance, 1) : null,
                'place_types' => $placeTypes,
                'cuisines' => [],
                'features' => [],
                'source' => 'serpapi',
            ];
        }

        return $results;
    }

    /**
     * Parse SerpApi price_level to our price_range format.
     * SerpApi uses integers 1-4; we convert to $-$$$$.
     *
     * @param  array<string, mixed>  $venue
     */
    private function parsePriceRange(array $venue): ?string
    {
        // SerpApi returns extracted_price (int, low-end of price range) and
        // price (string like "$10–20"). price_level is never present — Google
        // discontinued it in favor of the numeric range format.
        $raw = $venue['extracted_price'] ?? null;
        if (is_numeric($raw)) {
            $val = (int) $raw;

            return match (true) {
                $val < 15 => '$',
                $val < 30 => '$$',
                $val < 50 => '$$$',
                default => '$$$$',
            };
        }

        // Fallback: parse the human-readable price string
        $price = $venue['price'] ?? null;
        if ($price !== null && preg_match('/(\d+)/', $price, $m)) {
            $val = (int) $m[1];

            return match (true) {
                $val < 15 => '$',
                $val < 30 => '$$',
                $val < 50 => '$$$',
                default => '$$$$',
            };
        }

        return null;
    }

    /**
     * Downsize a Google thumbnail URL to the card's 4:3 reference (400x300).
     *
     * SerpApi passes the raw lh[3-6].googleusercontent.com thumbnail through,
     * which can be a multi-Megabyte original. Google's image CDN sizes via a
     * trailing `=...` argument, so we replace it with a 400x300 crop. Any other
     * host (Google Places Photo API, Foursquare, internal /storage) is returned
     * untouched — those are already sized at their source.
     */
    private function sizeGoogleThumbnail(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (! preg_match('#^https?://lh[3-6]\.googleusercontent\.com/#i', $url)) {
            return $url;
        }

        return preg_replace('/=[^\/]+$/', '=w400-h300-c-no', $url);
    }

    /**
     * Parse address from SerpApi response.
     *
     * @param  array<string, mixed>  $result
     */
    private function parseAddress(array $result): ?string
    {
        if (! empty($result['address'])) {
            return $result['address'];
        }

        $parts = array_filter([
            $result['street'] ?? null,
            $result['city'] ?? null,
            $result['state'] ?? null,
            $result['zip_code'] ?? null,
            $result['country'] ?? null,
        ]);

        return empty($parts) ? null : implode(', ', $parts);
    }

    /**
     * Calculate Haversine distance between two coordinates.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Normalize a SerpApi venue result to the enrichment venue shape.
     * This converts the rich live-search format to the simpler DB-persistence format
     * used by RestaurantEnrichmentService.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public function normalizeForEnrichment(array $r): array
    {
        $rating = $r['google_rating'] ?? null;
        $reviewCount = $r['google_review_count'] ?? 0;

        return [
            'yelp_business_id' => null,
            'name' => $r['name'] ?? 'Unknown',
            'lat' => isset($r['lat']) ? (float) $r['lat'] : null,
            'lng' => isset($r['lng']) ? (float) $r['lng'] : null,
            'address' => $r['address'] ?? null,
            'city' => $r['city'] ?? null,
            'state' => $r['state'] ?? null,
            'postal_code' => $r['postal_code'] ?? null,
            'country' => $r['country'] ?? null,
            'phone' => $r['phone'] ?? null,
            'website_url' => $r['website_url'] ?? null,
            'price_range' => $r['price_range'] ?? null,
            'description' => $r['description'] ?? null,
            'photo_url' => $r['photo_url'] ?? null,
            'photo_source' => $r['photo_source'] ?? null,
            'opening_hours' => $r['opening_hours'] ?? null,
            'place_types' => $r['place_types'] ?? [],
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'google_rating' => isset($rating) && is_numeric($rating) ? (float) $rating : null,
            'google_review_count' => isset($reviewCount) && is_numeric($reviewCount) ? (int) $reviewCount : 0,
            'features' => [],
            'source' => 'serpapi',
        ];
    }
}
