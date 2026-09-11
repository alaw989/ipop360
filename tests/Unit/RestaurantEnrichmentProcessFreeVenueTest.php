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
use Mockery\MockInterface;
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
        /** @var OverpassService&MockInterface $overpass */
        $overpass = Mockery::mock(OverpassService::class)->shouldIgnoreMissing();
        /** @var BizDataApiService&MockInterface $bizData */
        $bizData = Mockery::mock(BizDataApiService::class)->shouldIgnoreMissing();
        /** @var SerpApiService&MockInterface $serpApiService */
        $serpApiService = Mockery::mock(SerpApiService::class)->shouldIgnoreMissing();
        /** @var SocrataOpenDataService&MockInterface $socrataService */
        $socrataService = Mockery::mock(SocrataOpenDataService::class)->shouldIgnoreMissing();
        /** @var WikidataService&MockInterface $wikidata */
        $wikidata = Mockery::mock(WikidataService::class)->shouldIgnoreMissing();
        /** @var PopularityScoreService&MockInterface $popularityScore */
        $popularityScore = Mockery::mock(PopularityScoreService::class)->shouldIgnoreMissing();
        /** @var RestaurantWebsiteScraperService&MockInterface $websiteScraper */
        $websiteScraper = Mockery::mock(RestaurantWebsiteScraperService::class)->shouldIgnoreMissing();
        /** @var AiEnrichmentService&MockInterface $aiEnrichment */
        $aiEnrichment = Mockery::mock(AiEnrichmentService::class)->shouldIgnoreMissing();
        $cuisineMatcher = new CuisineMatcher;
        $venuePipeline = new VenuePipeline(new PriceLevelNormalizer);
        $restaurantValidation = new RestaurantValidationService;

        return new RestaurantEnrichmentService(
            $overpass, $bizData, $serpApiService, $socrataService, $wikidata,
            $popularityScore, $websiteScraper, $aiEnrichment, $cuisineMatcher,
            $venuePipeline, $restaurantValidation
        );
    }

    /**
     * @param  array<string, mixed>  $venue
     *                                       Invoke the private, real-DB persisting path.
     */
    private function processFreeVenue(array $venue, Cuisine $cuisine): ?Restaurant
    {
        $method = new ReflectionMethod(RestaurantEnrichmentService::class, 'processFreeVenue');
        $method->setAccessible(true);

        return $method->invoke($this->makeService(), $venue, $cuisine);
    }

    public function test_creates_new_venue_and_attaches_evidence_cuisine(): void
    {
        /** @var Cuisine $cuisine */
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
        /** @var Cuisine $cuisine */
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
        /** @var Cuisine $cuisine */
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

        $this->assertNotNull($restaurant);
        $this->assertSame($existing->id, $restaurant->id);
        $this->assertDatabaseCount('restaurants', 1);
        // A matched row keeps its stored name (source records only fill blanks).
        $this->assertDatabaseHas('restaurants', ['id' => $existing->id, 'name' => 'Old Name']);
    }

    public function test_sparse_venue_does_not_erase_a_matched_rows_data(): void
    {
        /** @var Cuisine $cuisine */
        $cuisine = Cuisine::factory()->create(['slug' => 'mexican', 'name' => 'Mexican']);
        $existing = Restaurant::factory()->create([
            'name' => 'Rio on Wazee',
            'latitude' => 39.7527,
            'longitude' => -104.9990,
            'address' => '1745 Wazee St',
            'phone' => '3036235432',
            'website_url' => 'https://riograndemexican.com/locations/rio-on-wazee/',
            'photo_url' => 'https://riograndemexican.com/front.jpg',
            'price_range' => '$$',
            'description' => 'Mexican restaurant',
            'google_rating' => 4.2,
            'google_review_count' => 2782,
            'is_active' => false,
        ]);

        // What BizData actually sends for this row every day: a name and a point.
        $this->processFreeVenue([
            'name' => 'Rio on Wazee',
            'lat' => 39.7528,
            'lng' => -104.9991,
            'address' => null,
            'phone' => null,
            'website_url' => null,
            'price_range' => null,
            'photo_url' => null,
            'source' => 'bizdata',
        ], $cuisine);

        $fresh = $existing->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('1745 Wazee St', $fresh->address);
        $this->assertSame('3036235432', $fresh->phone);
        $this->assertSame('https://riograndemexican.com/locations/rio-on-wazee/', $fresh->website_url);
        $this->assertSame('https://riograndemexican.com/front.jpg', $fresh->photo_url);
        $this->assertSame('$$', $fresh->price_range);
        $this->assertSame('Mexican restaurant', $fresh->description);
        $this->assertSame(4.2, $fresh->google_rating);
        $this->assertSame(2782, $fresh->google_review_count);
        $this->assertFalse($fresh->is_active, 'a closed restaurant must not be reactivated by a source match');
    }

    public function test_matched_row_takes_a_fresh_rating_and_fills_blanks(): void
    {
        /** @var Cuisine $cuisine */
        $cuisine = Cuisine::factory()->create(['slug' => 'mexican', 'name' => 'Mexican']);
        $existing = Restaurant::factory()->create([
            'name' => 'Rio on Wazee',
            'latitude' => 39.7527,
            'longitude' => -104.9990,
            'phone' => null,
            'website_url' => 'https://riograndemexican.com/locations/rio-on-wazee/',
            'google_rating' => 4.1,
            'google_review_count' => 2700,
        ]);

        $this->processFreeVenue([
            'name' => 'Rio on Wazee',
            'lat' => 39.7527,
            'lng' => -104.9990,
            'phone' => '(303) 623-5432',
            'website_url' => 'https://another-site.test',
            'google_rating' => 4.2,
            'google_review_count' => 2782,
            'source' => 'serpapi',
        ], $cuisine);

        $fresh = $existing->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('3036235432', $fresh->phone);
        $this->assertSame('https://riograndemexican.com/locations/rio-on-wazee/', $fresh->website_url);
        $this->assertSame(4.2, $fresh->google_rating);
        $this->assertSame(2782, $fresh->google_review_count);
    }

    public function test_persists_venue_without_coordinates(): void
    {
        /** @var Cuisine $cuisine */
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
        /** @var Cuisine $cuisine */
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
