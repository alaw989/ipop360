<?php

namespace Tests\Unit;

use App\Services\OverpassService;
use Tests\TestCase;

/**
 * Covers the pure normalization/cache-key logic of OverpassService — the free,
 * keyless OpenStreetMap live-search source — that the HTTP round-trip Feature
 * tests never exercise directly.
 */
class OverpassServiceTest extends TestCase
{
    private OverpassService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(OverpassService::class);
    }

    public function test_normalize_raw_handles_node_elements(): void
    {
        $venues = $this->service->normalizeRaw([
            [
                'type' => 'node',
                'id' => 123,
                'lat' => 40.7128,
                'lon' => -74.0060,
                'tags' => [
                    'name' => 'Joe\'s Pizza',
                    'addr:housenumber' => '250',
                    'addr:street' => 'Fulton St',
                    'addr:city' => 'Brooklyn',
                    'addr:state' => 'NY',
                    'addr:postcode' => '11201',
                    'phone' => '+1-555-0100',
                    'website' => 'https://joespizza.example.com',
                    'opening_hours' => '10:00-22:00',
                ],
            ],
        ], 40.7128, -74.0060);

        $this->assertCount(1, $venues);
        $venue = $venues[0];
        $this->assertSame('Joe\'s Pizza', $venue['name']);
        $this->assertSame(40.7128, $venue['lat']);
        $this->assertSame(-74.0060, $venue['lng']);
        $this->assertSame('250 Fulton St', $venue['address']);
        $this->assertSame('Brooklyn', $venue['city']);
        $this->assertSame('NY', $venue['state']);
        $this->assertSame('11201', $venue['postal_code']);
        $this->assertSame('US', $venue['country']);
        $this->assertSame('+1-555-0100', $venue['phone']);
        $this->assertSame('https://joespizza.example.com', $venue['website_url']);
        $this->assertSame('10:00-22:00', $venue['opening_hours']);
        $this->assertSame('overpass', $venue['source']);
        $this->assertFalse($venue['has_award']);
    }

    public function test_normalize_raw_uses_center_coords_for_way_and_relation(): void
    {
        $venues = $this->service->normalizeRaw([
            [
                'type' => 'way',
                'id' => 456,
                'center' => ['lat' => 40.0, 'lon' => -74.0],
                'tags' => ['name' => 'Way Diner'],
            ],
            [
                'type' => 'relation',
                'id' => 789,
                'center' => ['lat' => 41.0, 'lon' => -73.0],
                'tags' => ['name' => 'Rel Spot'],
            ],
        ], 40.0, -74.0);

        $this->assertCount(2, $venues);
        $this->assertSame(40.0, $venues[0]['lat']);
        $this->assertSame(-74.0, $venues[0]['lng']);
        $this->assertSame(41.0, $venues[1]['lat']);
        $this->assertSame(-73.0, $venues[1]['lng']);
    }

    public function test_normalize_raw_skips_missing_coords_or_name(): void
    {
        $venues = $this->service->normalizeRaw([
            ['type' => 'node', 'id' => 1, 'tags' => ['name' => 'No Coords']],
            ['type' => 'way', 'id' => 2, 'tags' => ['name' => 'Way No Center']],
            ['type' => 'node', 'id' => 3, 'lat' => 40.0, 'lon' => -74.0, 'tags' => []],
            ['type' => 'node', 'id' => 4, 'lat' => 40.0, 'lon' => -74.0, 'tags' => ['name' => 'Kept']],
        ], 40.0, -74.0);

        $this->assertCount(1, $venues);
        $this->assertSame('Kept', $venues[0]['name']);
    }

    public function test_normalize_raw_computes_distance_and_sorts_ascending(): void
    {
        [$lat, $lng] = [40.0, -74.0];
        $venues = $this->service->normalizeRaw([
            ['type' => 'node', 'id' => 1, 'lat' => $lat + 0.5, 'lon' => $lng, 'tags' => ['name' => 'Far']],
            ['type' => 'node', 'id' => 2, 'lat' => $lat, 'lon' => $lng, 'tags' => ['name' => 'Here']],
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
        $venues = $this->service->normalizeRaw([
            ['type' => 'node', 'id' => 555, 'lat' => 40.0, 'lon' => -74.0, 'tags' => ['name' => 'Diner']],
        ], 40.0, -74.0);

        $this->assertLessThan(0, $venues[0]['id']);
        $this->assertStringStartsWith('diner-', $venues[0]['slug']);

        $again = $this->service->normalizeRaw([
            ['type' => 'node', 'id' => 555, 'lat' => 40.0, 'lon' => -74.0, 'tags' => ['name' => 'Diner']],
        ], 40.0, -74.0);

        $this->assertSame($venues[0]['id'], $again[0]['id']);
        $this->assertSame($venues[0]['slug'], $again[0]['slug']);
    }

    public function test_normalize_raw_falls_back_across_address_website_fields(): void
    {
        $venues = $this->service->normalizeRaw([
            ['type' => 'node', 'id' => 7, 'lat' => 1.0, 'lon' => 2.0, 'tags' => [
                'name' => 'No Street',
                'addr:postcode' => '72000',
                'addr:country' => 'MX',
                'url' => 'https://nobuilding.example.com',
            ]],
        ], 1.0, 2.0);

        $this->assertNull($venues[0]['address']);
        $this->assertSame('72000', $venues[0]['postal_code']);
        $this->assertSame('MX', $venues[0]['country']);
        $this->assertSame('https://nobuilding.example.com', $venues[0]['website_url']);
    }

    public function test_normalize_raw_extracts_cuisines_and_features(): void
    {
        $venues = $this->service->normalizeRaw([
            ['type' => 'node', 'id' => 9, 'lat' => 40.0, 'lon' => -74.0, 'tags' => [
                'name' => 'Veg Bistro',
                'cuisine' => 'vegan;  vegetarian',
                'outdoor_seating' => 'yes',
                'wheelchair' => 'limited',
                'unknown_tag' => 'ignored',
            ]],
        ], 40.0, -74.0);

        $this->assertSame([
            ['id' => abs(crc32('vegan')), 'name' => 'Vegan', 'slug' => 'vegan'],
            ['id' => abs(crc32('vegetarian')), 'name' => 'Vegetarian', 'slug' => 'vegetarian'],
        ], $venues[0]['cuisines']);

        $this->assertSame(['outdoor_seating' => 'yes', 'wheelchair' => 'limited'], $venues[0]['features']);
    }

    public function test_normalize_for_enrichment_drops_to_db_shape(): void
    {
        $out = $this->service->normalizeForEnrichment([
            'name' => 'Bakery',
            'lat' => '40.7100',
            'lng' => '-74.0000',
            'address' => '1 Main St',
            'opening_hours' => '07:00-19:00',
            'cuisines' => [['name' => 'Bakery']],
        ]);

        $this->assertSame('Bakery', $out['name']);
        $this->assertSame(40.71, $out['lat']);
        $this->assertSame(-74.0, $out['lng']);
        $this->assertSame('1 Main St', $out['address']);
        $this->assertSame('07:00-19:00', $out['opening_hours']);
        $this->assertSame('overpass', $out['source']);
        $this->assertSame(0, $out['yelp_review_count']);
        $this->assertSame([['name' => 'Bakery']], $out['cuisines']);
        $this->assertNull($out['price_range']);
        $this->assertNull($out['yelp_rating']);
        $this->assertSame([], $out['features']);
    }

    public function test_normalize_for_enrichment_defaults_missing_name_and_country(): void
    {
        $out = $this->service->normalizeForEnrichment(['lat' => 1.0, 'lng' => 2.0]);
        $this->assertSame('Unknown', $out['name']);
        $this->assertSame('US', $out['country']);
    }

    public function test_cache_key_is_deterministic_and_input_sensitive(): void
    {
        $a = $this->service->cacheKeyFor(40.7128, -74.0060, 'pizza', 25000, 50);
        $this->assertSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, 'pizza', 25000, 50));
        $this->assertNotSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, 'tacos', 25000, 50));
        $this->assertNotSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, null, 25000, 50));
        $this->assertStringStartsWith('overpass_search:', $a);
    }
}