<?php

namespace Tests\Unit;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\BizDataApiService;
use App\Services\CuisineMatcher;
use App\Services\OverpassService;
use App\Services\PopularityScoreService;
use App\Services\PriceLevelNormalizer;
use App\Services\RestaurantEnrichmentService;
use App\Services\RestaurantValidationService;
use App\Services\RestaurantWebsiteScraperService;
use App\Services\SerpApiService;
use App\Services\SocrataOpenDataService;
use App\Services\VenuePipeline;
use App\Services\WikidataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers RestaurantEnrichmentService::processFreeVenue — the persisting free
 * venue path: empty-name skip, attribute normalization/clamping, upsert by
 * yelp id, null-coord persistence, and the evidence-gated cuisine pivot.
 * Collaborators that are NOT involved in this path are mocks; CuisineMatcher,
 * VenuePipeline+PriceLevelNormalizer, and RestaurantValidationService are real
 * so the match/normalize logic is actually exercised.
 */
class RestaurantEnrichmentProcessFreeVenueTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): RestaurantEnrichmentService
    {
        $mocks = [];
        $mocks[] = Mockery::mock(OverpassService::class)->shouldIgnoreMissing();
        $mocks[] = Mockery::mock(BizDataApiService::class)->shouldIgnoreMissing();
        $mocks[] = Mockery::mock(SerpApiService::class)->shouldIgnoreMissing();
        $mocks[] = Mockery::mock(SocrataOpenDataService::class)->shouldIgnoreMissing();
        $mocks[] = Mockery::mock(WikidataService::class)->shouldIgnoreMissing();
        $mocks[] = Mockery::mock(PopularityScoreService::class)->shouldIgnoreMissing();
        $mocks[] = Mockery::mock(RestaurantWebsiteScraperService::class)->shouldIgnoreMissing();
        $mocks[] = Mockery::mock(AiEnrichmentService::class)->shouldIgnoreMissing();
        $mocks[] = new CuisineMatcher;
        $mocks[] = new VenuePipeline(new PriceLevelNormalizer);
        $mocks[] = new RestaurantValidationService;

        return new RestaurantEnrichmentService(...$mocks);
    }

    /** Invoke the private, real-DB persisting path. */
    private function processFreeVenue(array $venue, Cuisine $cuisine): ?Restaurant
    {
        $method = new ReflectionMethod(RestaurantEnrichmentService::class, 'processFreeVenue');
        $method->setAccessible(true);

        return $method->invoke($this->makeService(), $venue, $cuisine);
    }

    public function test_creates_new_venue_and_attaches_evidence_cuisine(): void
    {
        $cuisine = Cuisine::factory()->create(['slug' => 'japanese', 'name' => 'Japanese']);

        $restaurant = $this->processFreeVenue([
            'name' => 'Sushi Izakaya',
            'lat' => 30.69,
            'lng' => -88.04,
            'address' => '1200 Broad St',
            'phone' => '(251) 555-0123',
            'google_rating' => '4.5',
            'google_review_count' => '120',
            'website' => 'http://sushi-izakaya.example',
            'source' => 'bizdata',
        ], $cuisine);

        $this->assertNotNull($restaurant);
        $this->assertDatabaseHas('restaurants', [
            'name' => 'Sushi Izakaya',
            'google_rating' => 4.5,
            'google_review_count' => 120,
            'website_url' => 'http://sushi-izakaya.example',
            'latitude' => 30.69,
            'longitude' => -88.04,
        ]);
        // Evidence name ("sushi") → searched cuisine attached.
        $this->assertTrue($cuisine->restaurants()->where('restaurants.id', $restaurant->id)->exists());
    }

    public function test_skips_venue_with_empty_name(): void
    {
        $cuisine = Cuisine::factory()->create(['slug' => 'japanese', 'name' => 'Japanese']);

        $restaurant = $this->processFreeVenue([
            'name' => '',
            'lat' => 30.69,
            'lng' => -88.04,
            'source' => 'overpass',
        ], $cuisine);

        $this->assertNull($restaurant);
        $this->assertDatabaseCount('restaurants', 0);
    }

    public function test_updates_existing_row_by_yelp_id_without_duplicate(): void
    {
        $cuisine = Cuisine::factory()->create(['slug' => 'japanese', 'name' => 'Japanese']);
        $existing = Restaurant::factory()->create([
            'yelp_business_id' => 'yelp-123',
            'name' => 'Old Name',
        ]);

        $restaurant = $this->processFreeVenue([
            'name' => 'Sushi Izakaya',
            'yelp_business_id' => 'yelp-123',
            'lat' => 30.69,
            'lng' => -88.04,
            'source' => 'serpapi',
        ], $cuisine);

        $this->assertSame($existing->id, $restaurant->id);
        $this->assertDatabaseCount('restaurants', 1);
        $this->assertDatabaseHas('restaurants', ['id' => $existing->id, 'name' => 'Sushi Izakaya']);
    }

    public function test_persists_venue_without_coordinates(): void
    {
        $cuisine = Cuisine::factory()->create(['slug' => 'japanese', 'name' => 'Japanese']);

        $restaurant = $this->processFreeVenue([
            'name' => 'Sushi Izakaya',
            'lat' => null,
            'lng' => null,
            'source' => 'overpass',
        ], $cuisine);

        $this->assertNotNull($restaurant);
        $this->assertDatabaseHas('restaurants', ['id' => $restaurant->id, 'latitude' => null, 'longitude' => null]);
    }

    public function test_does_not_attach_cuisine_without_evidence(): void
    {
        $cuisine = Cuisine::factory()->create(['slug' => 'japanese', 'name' => 'Japanese']);

        $restaurant = $this->processFreeVenue([
            'name' => 'Corner Market',
            'lat' => 30.69,
            'lng' => -88.04,
            'source' => 'bizdata',
        ], $cuisine);

        $this->assertNotNull($restaurant);
        $this->assertDatabaseMissing('cuisine_restaurant', ['restaurant_id' => $restaurant->id]);
    }
}
