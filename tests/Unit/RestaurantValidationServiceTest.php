<?php

namespace Tests\Unit;

use App\Services\RestaurantValidationService;
use Tests\TestCase;

class RestaurantValidationServiceTest extends TestCase
{
    private RestaurantValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RestaurantValidationService;
    }

    public function test_normalize_url_prefixes_missing_scheme(): void
    {
        $this->assertSame('https://example.com', $this->service->normalizeUrl('example.com'));
    }

    public function test_normalize_url_preserves_existing_scheme(): void
    {
        $this->assertSame('http://example.com', $this->service->normalizeUrl('http://example.com'));
        $this->assertSame('https://example.com', $this->service->normalizeUrl('https://example.com'));
    }

    public function test_normalize_url_rejects_invalid_and_empty(): void
    {
        $this->assertNull($this->service->normalizeUrl('not a url'));
        $this->assertNull($this->service->normalizeUrl(''));
        $this->assertNull($this->service->normalizeUrl('   https://   '));
    }

    public function test_clamp_rating_bounds_and_rounds(): void
    {
        $this->assertSame(5.0, $this->service->clampRating(7.8));
        $this->assertSame(0.0, $this->service->clampRating(-3.2));
        $this->assertSame(4.6, $this->service->clampRating(4.567));
        $this->assertNull($this->service->clampRating(null));
    }

    public function test_clamp_lat_lng_bounds(): void
    {
        $this->assertSame(90.0, $this->service->clampLatitude(150.0));
        $this->assertSame(-90.0, $this->service->clampLatitude(-150.0));
        $this->assertSame(35.5, $this->service->clampLatitude(35.5));
        $this->assertSame(180.0, $this->service->clampLongitude(220.0));
        $this->assertSame(-180.0, $this->service->clampLongitude(-220.0));
        $this->assertSame(-122.4, $this->service->clampLongitude(-122.4));
        $this->assertNull($this->service->clampLatitude(null));
        $this->assertNull($this->service->clampLongitude(null));
    }

    public function test_normalize_phone_strips_non_digits(): void
    {
        $this->assertSame('4155550100', $this->service->normalizePhone('(415) 555-0100'));
        $this->assertSame('123', $this->service->normalizePhone('+1 2 3.'));
        $this->assertNull($this->service->normalizePhone(''));
        $this->assertNull($this->service->normalizePhone('abc'));
    }

    public function test_normalize_price_range_caps_dollars(): void
    {
        $this->assertSame('$$', $this->service->normalizePriceRange('$$'));
        $this->assertSame('$$$$', $this->service->normalizePriceRange('$$$$$$$$'));
        $this->assertSame('Moderate', $this->service->normalizePriceRange('  Moderate  '));
        $this->assertNull($this->service->normalizePriceRange(''));
        $this->assertNull($this->service->normalizePriceRange(null));
    }

    public function test_normalize_trims_strings(): void
    {
        $result = $this->service->normalize(['name' => '  Tacos  ', 'city' => '  SF  ']);
        $this->assertSame('Tacos', $result['name']);
        $this->assertSame('SF', $result['city']);
    }

    public function test_normalize_applies_transformations_to_known_keys(): void
    {
        $result = $this->service->normalize([
            'name' => 'Taco Mesa',
            'website_url' => 'example.com',
            'google_rating' => 7.5,
            'yelp_rating' => -1.0,
            'latitude' => 112.0,
            'longitude' => -300.0,
            'phone' => '(415) 555-0100',
            'price_range' => '$$$$$$',
            'google_review_count' => -20,
            'yelp_review_count' => 99,
        ]);

        $this->assertSame('https://example.com', $result['website_url']);
        $this->assertSame(5.0, $result['google_rating']);
        $this->assertSame(0.0, $result['yelp_rating']);
        $this->assertSame(90.0, $result['latitude']);
        $this->assertSame(-180.0, $result['longitude']);
        $this->assertSame('4155550100', $result['phone']);
        $this->assertSame('$$$$', $result['price_range']);
        $this->assertSame(0, $result['google_review_count']);
        $this->assertSame(99, $result['yelp_review_count']);
    }

    public function test_normalize_truncates_name_and_skips_empty_urls(): void
    {
        $result = $this->service->normalize([
            'name' => str_repeat('a', 300),
            'website_url' => '',
            'photo_url' => '   ',
        ]);

        $this->assertSame(255, mb_strlen($result['name']));
        // empty / whitespace-only URLs are trimmed and left untouched by normalizeUrl
        $this->assertSame('', $result['website_url']);
        $this->assertSame('', $result['photo_url']);
    }
}
