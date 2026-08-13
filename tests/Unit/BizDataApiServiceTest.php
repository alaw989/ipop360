<?php

namespace Tests\Unit;

use App\Services\BizDataApiService;
use Tests\TestCase;

/**
 * Covers the pure normalization/cache-key logic of BizDataApiService — the
 * free, keyless live-search source — that the Feature test (HTTP round-trip)
 * never touches directly.
 */
class BizDataApiServiceTest extends TestCase
{
    private BizDataApiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BizDataApiService::class);
    }

    public function test_normalize_raw_maps_business_fields_to_venue_shape(): void
    {
        $business = [
            'name' => "Joe's Pizza",
            'lat' => 40.7128,
            'lon' => -74.0060,
            'address' => '250 Fulton St',
            'city' => 'Brooklyn',
            'state' => 'NY',
            'zip' => '11201',
            'country' => 'US',
            'phone' => '+1-555-0100',
            'website' => 'https://joespizza.example.com',
            'opening_hours' => '10:00-22:00',
        ];

        $venues = $this->service->normalizeRaw([$business], 40.7128, -74.0060, 'pizza');
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
        $this->assertSame('+1-555-0100', $venue['phone']);
        $this->assertSame('https://joespizza.example.com', $venue['website_url']);
        $this->assertSame('10:00-22:00', $venue['opening_hours']);
        $this->assertSame('bizdata', $venue['source']);
    }

    public function test_normalize_raw_computes_haversine_distance(): void
    {
        [$lat, $lng] = [40.7128, -74.0060];
        $venues = $this->service->normalizeRaw(
            [['name' => 'Zero', 'lat' => $lat, 'lon' => $lng]],
            $lat,
            $lng,
        );

        $this->assertSame(0.0, $venues[0]['distance']);

        $far = $this->service->normalizeRaw(
            [['name' => 'Far', 'lat' => $lat + 0.5, 'lon' => $lng]],
            $lat,
            $lng,
        );

        // ~0.5 degrees of latitude is ~55.5 km.
        $this->assertGreaterThan(50.0, $far[0]['distance']);
        $this->assertLessThan(60.0, $far[0]['distance']);
    }

    public function test_normalize_raw_skips_rows_without_a_name(): void
    {
        $venues = $this->service->normalizeRaw(
            [
                ['name' => 'Kept', 'lat' => 40.0, 'lon' => -74.0],
                ['lat' => 40.0, 'lon' => -74.0],
                ['name' => null, 'lat' => 40.0, 'lon' => -74.0],
                ['name' => '', 'lat' => 40.0, 'lon' => -74.0],
            ],
            40.0,
            -74.0,
        );

        $this->assertCount(1, $venues);
        $this->assertSame('Kept', $venues[0]['name']);
    }

    public function test_normalize_raw_falls_back_across_address_city_state_fields(): void
    {
        $venues = $this->service->normalizeRaw(
            [[
                'name' => 'Taqueria',
                'lat' => 1.0,
                'lon' => 2.0,
                'municipality' => 'Puebla',
                'province' => 'North',
                'postcode' => '72000',
                'country' => 'MX',
                'website_url' => 'https://taqueria.example.com',
            ]],
            1.0,
            2.0,
        );

        $this->assertSame('Puebla', $venues[0]['city']);
        $this->assertSame('North', $venues[0]['state']);
        $this->assertSame('72000', $venues[0]['postal_code']);
        $this->assertSame('MX', $venues[0]['country']);
        $this->assertSame('https://taqueria.example.com', $venues[0]['website_url']);
    }

    public function test_normalize_raw_generates_stable_negative_id_and_slug(): void
    {
        $venues = $this->service->normalizeRaw(
            [['name' => 'Diner', 'lat' => 40.0, 'lon' => -74.0]],
            40.0,
            -74.0,
        );

        $this->assertLessThan(0, $venues[0]['id']);
        $this->assertStringStartsWith('diner-', $venues[0]['slug']);
        $this->assertSame('bizdata', $venues[0]['source']);

        // Same input → identical id/slug (stable across calls).
        $again = $this->service->normalizeRaw(
            [['name' => 'Diner', 'lat' => 40.0, 'lon' => -74.0]],
            40.0,
            -74.0,
        );
        $this->assertSame($venues[0]['id'], $again[0]['id']);
        $this->assertSame($venues[0]['slug'], $again[0]['slug']);
    }

    public function test_normalize_for_enrichment_drops_to_db_shape(): void
    {
        $out = $this->service->normalizeForEnrichment([
            'name' => 'Bakery',
            'lat' => '40.7100',
            'lng' => '-74.0000',
            'address' => '1 Main St',
            'opening_hours' => '07:00-19:00',
        ]);

        $this->assertSame('Bakery', $out['name']);
        $this->assertSame(40.71, $out['lat']);
        $this->assertSame(-74.0, $out['lng']);
        $this->assertSame('1 Main St', $out['address']);
        $this->assertSame('07:00-19:00', $out['opening_hours']);
        $this->assertSame('bizdata', $out['source']);
        $this->assertSame(0, $out['yelp_review_count']);
        // Fields BizData doesn't supply are nulled/emptied, never carried over.
        $this->assertNull($out['price_range']);
        $this->assertNull($out['yelp_rating']);
        $this->assertSame([], $out['features']);
    }

    public function test_normalize_for_enrichment_defaults_missing_name(): void
    {
        $out = $this->service->normalizeForEnrichment(['lat' => 1.0, 'lng' => 2.0]);
        $this->assertSame('Unknown', $out['name']);
    }

    public function test_cache_key_is_deterministic_and_input_sensitive(): void
    {
        $a = $this->service->cacheKeyFor(40.7128, -74.0060, 'pizza', 25, 50);
        $this->assertSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, 'pizza', 25, 50));
        $this->assertNotSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, 'tacos', 25, 50));
        $this->assertNotSame($a, $this->service->cacheKeyFor(40.7128, -74.0060, null, 25, 50));
        $this->assertStringStartsWith('bizdata:', $a);
    }

    public function test_pool_requests_never_send_the_ignored_query_param(): void
    {
        $specs = $this->service->poolRequestsFor(40.7128, -74.0060, 'pizza', ['read_path' => true]);

        $this->assertCount(2, $specs);
        foreach ($specs as $spec) {
            $this->assertArrayNotHasKey('query', $spec->query);
        }
    }

    public function test_pool_requests_fan_out_on_the_live_read_path_only(): void
    {
        // Bounded live retry: the flaky upstream gets N concurrent attempts
        // (default 2) on the read path, but enrichment keeps a single attempt.
        $live = $this->service->poolRequestsFor(40.7128, -74.0060, 'pizza', ['read_path' => true]);
        $enrich = $this->service->poolRequestsFor(40.7128, -74.0060, 'pizza', ['read_path' => false]);

        $this->assertCount(2, $live);
        $this->assertCount(1, $enrich);

        // All attempts are the same GET against the same endpoint.
        $this->assertSame($live[0]->url, $live[1]->url);
        $this->assertSame($live[0]->query, $live[1]->query);
    }
}
