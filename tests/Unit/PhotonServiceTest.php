<?php

namespace Tests\Unit;

use App\Services\PhotonService;
use Tests\TestCase;

/**
 * Covers the pure normalization/cache-key logic of PhotonService — the free,
 * keyless Photon (Komoot) live-search source that replaces the broken Overpass
 * name-regex fallback by text-matching restaurant names over OSM.
 */
class PhotonServiceTest extends TestCase
{
    private PhotonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(PhotonService::class);
    }

    public function test_normalize_raw_maps_feature_collection_to_venue_shape(): void
    {
        $venues = $this->service->normalizeRaw([
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [-74.0060, 40.7128]],
                'properties' => [
                    'osm_id' => 123,
                    'osm_type' => 'N',
                    'osm_key' => 'amenity',
                    'osm_value' => 'restaurant',
                    'name' => "Joe's Pizza",
                    'housenumber' => '250',
                    'street' => 'Fulton St',
                    'city' => 'Brooklyn',
                    'state' => 'NY',
                    'postcode' => '11201',
                    'country' => 'United States',
                    'countrycode' => 'US',
                ],
            ],
        ], 40.7128, -74.0060);

        $this->assertCount(1, $venues);
        $venue = $venues[0];
        $this->assertSame("Joe's Pizza", $venue['name']);
        $this->assertSame(40.7128, $venue['lat']);
        $this->assertSame(-74.0060, $venue['lng']);
        $this->assertSame('250 Fulton St', $venue['address']);
        $this->assertSame('Brooklyn', $venue['city']);
        $this->assertSame('NY', $venue['state']);
        $this->assertSame('11201', $venue['postal_code']);
        $this->assertSame('US', $venue['country']);
        $this->assertSame('photon', $venue['source']);
        $this->assertFalse($venue['has_award']);
        $this->assertLessThan(0, $venue['id']);
        $this->assertStringStartsWith('joes-pizza-', $venue['slug']);
    }

    public function test_normalize_raw_skips_missing_name_or_coords(): void
    {
        $venues = $this->service->normalizeRaw([
            ['geometry' => ['coordinates' => [-74.0, 40.0]], 'properties' => ['osm_id' => 1]],
            ['geometry' => [], 'properties' => ['osm_id' => 2, 'name' => 'No Coords']],
            ['geometry' => ['coordinates' => [-74.0, 40.0]], 'properties' => ['osm_id' => 3, 'name' => 'Kept']],
        ], 40.0, -74.0);

        $this->assertCount(1, $venues);
        $this->assertSame('Kept', $venues[0]['name']);
    }

    public function test_normalize_raw_computes_distance_and_sorts_ascending(): void
    {
        [$lat, $lng] = [40.0, -74.0];
        $venues = $this->service->normalizeRaw([
            ['geometry' => ['coordinates' => [$lng, $lat + 0.5]], 'properties' => ['osm_id' => 1, 'name' => 'Far']],
            ['geometry' => ['coordinates' => [$lng, $lat]], 'properties' => ['osm_id' => 2, 'name' => 'Here']],
        ], $lat, $lng);

        $this->assertCount(2, $venues);
        $this->assertSame('Here', $venues[0]['name']);
        $this->assertSame('Far', $venues[1]['name']);
        $this->assertSame(0.0, $venues[0]['distance']);
        $this->assertGreaterThan(50.0, $venues[1]['distance']);
        $this->assertLessThan(60.0, $venues[1]['distance']);
    }

    public function test_normalize_raw_generates_stable_negative_id_and_slug(): void
    {
        $feature = ['geometry' => ['coordinates' => [-74.0, 40.0]], 'properties' => ['osm_id' => 555, 'name' => 'Diner']];
        $venues = $this->service->normalizeRaw([$feature], 40.0, -74.0);

        $this->assertLessThan(0, $venues[0]['id']);
        $this->assertStringStartsWith('diner-', $venues[0]['slug']);

        $again = $this->service->normalizeRaw([$feature], 40.0, -74.0);

        $this->assertSame($venues[0]['id'], $again[0]['id']);
        $this->assertSame($venues[0]['slug'], $again[0]['slug']);
    }

    public function test_cache_key_is_deterministic_and_input_sensitive(): void
    {
        $a = $this->service->cacheKeyFor(40.7128, -74.0060, 'pizza', 25, 30);
        $this->assertSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, 'pizza', 25, 30));
        $this->assertNotSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, 'tacos', 25, 30));
        $this->assertNotSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, null, 25, 30));
        $this->assertStringStartsWith('photon:', $a);
    }

    public function test_pool_request_uses_cuisine_query_and_food_amenity_filter(): void
    {
        $specs = $this->service->poolRequestsFor(40.7128, -74.0060, 'vietnamese', ['read_path' => true]);

        $this->assertCount(1, $specs);
        $spec = $specs[0];
        $this->assertSame('GET', $spec->method);
        $this->assertStringContainsString('q=vietnamese', $spec->url);
        $this->assertStringContainsString('osm_tag=amenity%3Arestaurant', $spec->url);
        $this->assertStringContainsString('bbox=', $spec->url);
    }

    public function test_pool_request_defaults_to_generic_restaurant_query_when_unscoped(): void
    {
        $specs = $this->service->poolRequestsFor(40.7128, -74.0060, null, ['read_path' => true]);

        $this->assertCount(1, $specs);
        $this->assertStringContainsString('q=restaurant', $specs[0]->url);
    }
}
