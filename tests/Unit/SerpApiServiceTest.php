<?php

namespace Tests\Unit;

use App\Services\SerpApiService;
use Tests\TestCase;

/**
 * Unit coverage for SerpApiService's venue normalization / cache-key logic —
 * the pure in-memory methods that the feature tests (query construction,
 * exhaustion, quota guard) never exercise.
 */
class SerpApiServiceTest extends TestCase
{
    private SerpApiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SerpApiService;
    }

    public function test_normalize_raw_builds_canonical_venue_shape(): void
    {
        $results = $this->service->normalizeRaw([
            [
                'title' => 'Taste of Oaxaca',
                'gps_coordinates' => ['latitude' => 37.7749, 'longitude' => -122.4194],
                'address' => '123 Main St, San Francisco, CA',
                'city' => 'San Francisco',
                'state' => 'CA',
                'type' => 'restaurant',
                'rating' => '4.6',
                'reviews' => '1287',
                'extracted_price' => 24,
                'phone' => '(415) 555-0100',
                'website' => 'https://tacooaxaca.example.com',
            ],
        ], 37.7749, -122.4194);

        $this->assertCount(1, $results);
        $venue = $results[0];

        $this->assertSame('Taste of Oaxaca', $venue['name']);
        $this->assertSame('$$', $venue['price_range']);
        $this->assertSame(4.6, $venue['google_rating']);
        $this->assertSame(1287, $venue['google_review_count']);
        $this->assertSame(false, $venue['has_award']);
        $this->assertSame(0, $venue['popularity_score']);
        $this->assertSame('serpapi', $venue['source']);
        $this->assertContains('restaurant', $venue['place_types']);
        $this->assertSame(0.0, $venue['distance']);
    }

    public function test_normalize_raw_drops_rows_without_title(): void
    {
        $results = $this->service->normalizeRaw([
            ['gps_coordinates' => ['latitude' => 1.0, 'longitude' => 2.0]], // no title
            ['title' => 'Valid Place'],
        ], 1.0, 2.0);

        $this->assertCount(1, $results);
        $this->assertSame('Valid Place', $results[0]['name']);
    }

    public function test_normalize_raw_computes_haversine_distance(): void
    {
        $results = $this->service->normalizeRaw([
            ['title' => 'Place', 'gps_coordinates' => ['latitude' => 37.800, 'longitude' => -122.400]],
        ], 37.7749, -122.4194);

        $this->assertNotNull($results[0]['distance']);
        $this->assertGreaterThan(2, $results[0]['distance']); // ~3.2km away
        $this->assertLessThan(5, $results[0]['distance']);
    }

    public function test_size_of_google_thumbnail_is_restricted_to_csp_hosts(): void
    {
        $sized = $this->service->normalizeRaw([
            [
                'title' => 'Taco Mesa Oaxaca',
                'thumbnail' => 'https://lh5.googleusercontent.com/p/AF1GiX=w2000-h1500',
            ],
        ], 1.0, 2.0);

        $this->assertSame('https://lh5.googleusercontent.com/p/AF1GiX=w400-h300-c-no', $sized[0]['photo_url']);

        // Non-Google hosts pass through untouched.
        $untouched = $this->service->normalizeRaw([
            [
                'title' => 'Taco Mesa Oaxaca',
                'thumbnail' => 'https://cdn.example.com/photo-full.jpg',
            ],
        ], 1.0, 2.0);

        $this->assertSame('https://cdn.example.com/photo-full.jpg', $untouched[0]['photo_url']);
    }

    public function test_price_range_maps_extracted_price_buckets(): void
    {
        $buckets = [
            10 => '$',
            24 => '$$',
            45 => '$$$',
            60 => '$$$$',
        ];

        foreach ($buckets as $price => $expected) {
            $result = $this->service->normalizeRaw([
                ['title' => 'Place', 'extracted_price' => $price],
            ], 1.0, 2.0);

            $this->assertSame($expected, $result[0]['price_range'], "price {$price}");
        }

        $noPrice = $this->service->normalizeRaw([
            ['title' => 'Place'],
        ], 1.0, 2.0);

        $this->assertNull($noPrice[0]['price_range']);
    }

    public function test_place_types_merges_array_and_enum_forms_without_dupes(): void
    {
        $result = $this->service->normalizeRaw([
            [
                'title' => 'Place',
                'types' => ['Restaurant', 'Food'],
                'place_types' => ['restaurant', 'establishment', 'food'],
            ],
        ], 1.0, 2.0);

        $this->assertSame(['Restaurant', 'Food', 'establishment'], $result[0]['place_types']);
    }

    public function test_normalize_for_enrichment_shapes_db_persistence_format(): void
    {
        $enriched = $this->service->normalizeForEnrichment([
            'name' => 'Taco Mesa Oaxaca',
            'lat' => '37.7749',
            'lng' => '-122.4194',
            'address' => '123 Main St',
            'city' => 'San Francisco',
            'price_range' => '$$',
            'telephone' => '(415) 555-0100',
            'google_rating' => 4.6,
            'google_review_count' => 1287,
            'photo_url' => 'https://cdn.example.com/photo.jpg',
        ]);

        $this->assertSame('Taco Mesa Oaxaca', $enriched['name']);
        $this->assertSame(37.7749, $enriched['lat']);
        $this->assertNull($enriched['phone']);
        $this->assertSame(4.6, $enriched['google_rating']);
        $this->assertSame(0, $enriched['yelp_review_count']);
        $this->assertNull($enriched['yelp_business_id']);
        $this->assertSame('serpapi', $enriched['source']);

        $blank = $this->service->normalizeForEnrichment([]);
        $this->assertSame('Unknown', $blank['name']);
        $this->assertNull($blank['google_rating']);
        $this->assertSame(0, $blank['google_review_count']);
    }

    /**
     * spec-094: normalizeForEnrichment previously omitted website_url and
     * place_types entirely, so a SerpApi-only enrichment venue silently lost
     * its website on persist and lost place_types-based cuisine evidence
     * (CuisineMatcher::venueMatchesCuisine reads place_types) — a real venue
     * with only place_types evidence (no cuisine keyword in its name) could
     * fail to get its cuisine tag attached during offline enrichment.
     */
    public function test_normalize_for_enrichment_carries_website_url_and_place_types(): void
    {
        $enriched = $this->service->normalizeForEnrichment([
            'name' => 'Siam Orchid',
            'website_url' => 'https://siamorchid.example.com',
            'place_types' => ['Thai restaurant'],
        ]);

        $this->assertSame('https://siamorchid.example.com', $enriched['website_url']);
        $this->assertSame(['Thai restaurant'], $enriched['place_types']);

        $blank = $this->service->normalizeForEnrichment([]);
        $this->assertNull($blank['website_url']);
        $this->assertSame([], $blank['place_types']);
    }

    public function test_cache_key_rounds_coordinates_so_jitter_does_not_mint_new_entries(): void
    {
        $a = $this->service->cacheKeyFor(37.77494, -122.41941, 'Italian');
        $b = $this->service->cacheKeyFor(37.774956, -122.419418, 'Italian');
        $diff = $this->service->cacheKeyFor(38.5000, -122.4194, 'Italian');

        $this->assertSame($a, $b, 'sub-100m jitter must produce the same cache key');
        $this->assertNotSame($a, $diff);
    }
}
