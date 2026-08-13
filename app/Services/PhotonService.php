<?php

namespace App\Services;

use App\Models\ExternalApiCache;
use App\Services\Http\RequestSpec;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Photon (Komoot) venue source — free, keyless, OSM-backed text search.
 *
 * Photon is a geocoder whose `q` text-matches OSM venue NAMES, which is exactly
 * the recall the (broken) Overpass name-regex fallback tried to provide: a
 * cuisine-scoped search that yields no cuisine-tagged Overpass venues can still
 * surface name-matched places like "Pho 813" or "Vietnamese Bistro". Photon does
 * this natively and reliably, so it replaces that fallback as a first-class,
 * concurrently-fetched source.
 *
 * Geofenced via `bbox` (Photon has no radius param) and filtered to food
 * establishments via repeated `osm_tag=amenity:*` params (Photon rejects a
 * comma-separated union, so each amenity is its own query parameter).
 */
class PhotonService
{
    private string $baseUrl;

    /** @var array<int, string> */
    private array $amenities;

    public function __construct()
    {
        $this->baseUrl = (string) config('restaurant-finder.sources.photon.base_url', 'https://photon.komoot.io/api/');
        $this->amenities = array_values(array_filter(
            array_map('trim', (array) config('restaurant-finder.sources.photon.amenities', [
                'restaurant', 'fast_food', 'cafe', 'bar', 'pub', 'biergarten', 'ice_cream',
            ])),
            fn ($a) => $a !== ''
        )) ?: ['restaurant'];
    }

    /**
     * Cache key for a Photon query. Shared by the live concurrent-pool path.
     * Folds in the amenity set so a config change cleanly invalidates caches.
     */
    public function cacheKeyFor(float $lat, float $lng, ?string $cuisine = null, int $radius = 25, int $limit = 30): string
    {
        $amenities = implode(',', $this->amenities);

        return 'photon:'.md5(serialize(compact('lat', 'lng', 'cuisine', 'radius', 'limit', 'amenities')));
    }

    /**
     * Build the concurrent-pool request for the live read path. A single GET
     * with the cuisine text query, a radius-derived bbox, and one osm_tag
     * parameter per food amenity.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, RequestSpec>
     */
    public function poolRequestsFor(float $lat, float $lng, ?string $cuisine = null, array $context = []): array
    {
        $timeout = ($context['read_path'] ?? false)
            ? (float) config('restaurant-finder.live_search.http_timeout', 8.0)
            : 15.0;

        $limit = (int) config('restaurant-finder.sources.photon.limit', 30);
        $radiusKm = (int) config('restaurant-finder.sources.photon.radius_km', 25);

        $q = $cuisine !== null && trim($cuisine) !== '' ? $cuisine : 'restaurant';

        $url = $this->buildUrl($q, $limit, $this->bboxFor($lat, $lng, $radiusKm));

        return [
            new RequestSpec(
                method: 'GET',
                url: $url,
                timeout: $timeout,
            ),
        ];
    }

    /**
     * Parse a pooled Photon response into the raw GeoJSON features array (the
     * shape stored in ExternalApiCache). Returns null on HTTP failure or a
     * non-FeatureCollection body.
     *
     * @return array<int, mixed>|null
     */
    public function parsePoolResponse(Response $response, float $lat, float $lng): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || ! is_array($data['features'] ?? null)) {
            return null;
        }

        return $data['features'];
    }

    /**
     * Consume pooled responses for the live read path: parse, cache the raw
     * features (24h), and normalize to venues.
     *
     * @param  array<int, Response|\Throwable>  $responses
     * @return array<int, array<string, mixed>>
     */
    public function consumePoolResponses(array $responses, float $lat, float $lng, ?string $cuisine, string $cacheKey): array
    {
        foreach ($responses as $response) {
            if ($response instanceof \Throwable) {
                continue;
            }

            $features = $this->parsePoolResponse($response, $lat, $lng);
            if ($features === null) {
                continue;
            }

            ExternalApiCache::storeByKey($cacheKey, $features, now()->addHours(
                (int) config('restaurant-finder.cache.photon_ttl_hours', 24)
            ));

            return $this->normalizeRaw($features, $lat, $lng);
        }

        return [];
    }

    /**
     * Normalize raw Photon GeoJSON features to the shared venue shape.
     * Public method for use after parallel fetch / cache hit.
     *
     * @param  array<int, mixed>  $features
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRaw(array $features, float $searchLat, float $searchLng): array
    {
        $results = [];

        foreach ($features as $feature) {
            $venue = $this->normalizeFeature($feature, $searchLat, $searchLng);
            if ($venue !== null) {
                $results[] = $venue;
            }
        }

        usort($results, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>|null
     */
    private function normalizeFeature(array $feature, float $searchLat, float $searchLng): ?array
    {
        $properties = $feature['properties'] ?? [];
        $name = $properties['name'] ?? null;
        if (! $name) {
            return null;
        }

        $coords = $feature['geometry']['coordinates'] ?? null;
        if (! is_array($coords) || count($coords) < 2) {
            return null;
        }

        // GeoJSON coordinate order is [longitude, latitude].
        $lng = (float) $coords[0];
        $lat = (float) $coords[1];

        $osmId = $properties['osm_id'] ?? 0;
        $distance = $this->haversineKm($searchLat, $searchLng, $lat, $lng);

        $housenumber = $properties['housenumber'] ?? null;
        $street = $properties['street'] ?? null;
        $address = array_filter([$housenumber, $street]);

        return [
            'id' => -1 * abs(crc32('photon:'.$osmId)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.substr(md5('photon:'.$osmId), 0, 6),
            'description' => null,
            'address' => $address ? implode(' ', $address) : null,
            'city' => $properties['city'] ?? null,
            'state' => $properties['state'] ?? null,
            'postal_code' => $properties['postcode'] ?? null,
            'country' => $properties['countrycode'] ?? 'US',
            'lat' => $lat,
            'lng' => $lng,
            'photo_url' => null,
            'price_range' => null,
            'phone' => null,
            'website_url' => null,
            'opening_hours' => null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'has_award' => false,
            'popularity_score' => 0,
            'distance' => round($distance, 1),
            'cuisines' => [],
            'features' => [],
            'source' => 'photon',
        ];
    }

    /**
     * Build a Photon bbox string `minLon,minLat,maxLon,maxLat` covering a
     * square of the given radius (km) around the search center. Photon has no
     * radius parameter, so the bounding box is the geographic constraint.
     */
    private function bboxFor(float $lat, float $lng, int $radiusKm): string
    {
        $latDelta = $radiusKm / 111.32;
        $lngDelta = $radiusKm / (111.32 * max(cos(deg2rad($lat)), 0.01));

        return implode(',', [
            $lng - $lngDelta,
            $lat - $latDelta,
            $lng + $lngDelta,
            $lat + $latDelta,
        ]);
    }

    /**
     * Build the full query URL: the plain params first, then one repeated
     * `osm_tag=amenity:*` parameter per food amenity (Photon rejects the
     * comma-separated form and reads repeated params as an OR union).
     */
    private function buildUrl(string $q, int $limit, string $bbox): string
    {
        $params = http_build_query(['q' => $q, 'limit' => $limit, 'bbox' => $bbox]);

        $osmTags = implode('&', array_map(
            fn ($amenity) => 'osm_tag='.urlencode('amenity:'.$amenity),
            $this->amenities
        ));

        return $this->baseUrl.'?'.$params.'&'.$osmTags;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
