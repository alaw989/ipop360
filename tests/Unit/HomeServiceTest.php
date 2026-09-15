<?php

namespace Tests\Unit;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\HomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract for HomeService — the homepage data aggregation (trending
 * cascade, city-scoped categories) extracted out of HomeController.
 * Exercised directly here (no HTTP layer); see
 * tests/Feature/HomeControllerTest.php for the response-shape contract.
 */
class HomeServiceTest extends TestCase
{
    use RefreshDatabase;

    private HomeService $homeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeService = $this->app->make(HomeService::class);
    }

    public function test_trending_falls_back_to_qualified_global_when_city_candidate_fails_the_floor(): void
    {
        Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => null,
        ]);
        $global = Restaurant::factory()->create([
            'city' => 'NewYork',
            'state' => 'NewYork',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);

        $data = $this->homeService->getHomepageData('Austin', 'Texas');

        $this->assertNull($data['location']);
        $this->assertCount(1, $data['popularRestaurants']);
        $this->assertSame($global->id, $data['popularRestaurants'][0]['id']);
    }

    public function test_trending_falls_back_to_unfiltered_global_when_nothing_meets_the_floor(): void
    {
        $r = Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.1,
            'photo_url' => null,
        ]);

        $data = $this->homeService->getHomepageData('Austin', 'Texas');

        $this->assertNull($data['location']);
        $this->assertCount(1, $data['popularRestaurants']);
        $this->assertSame($r->id, $data['popularRestaurants'][0]['id']);
    }

    public function test_trending_shows_at_most_one_card_per_restaurant_name(): void
    {
        // Same chain, three distinct locations (different addresses in
        // practice, irrelevant here) — all high-scoring, all named the same
        // modulo casing, like the real "MISSION BBQ" / "Mission BBQ" case.
        $best = Restaurant::factory()->create([
            'name' => 'MISSION BBQ',
            'city' => 'Cleveland',
            'state' => 'OH',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        Restaurant::factory()->create([
            'name' => 'Mission BBQ',
            'city' => 'Cleveland',
            'state' => 'OH',
            'is_active' => true,
            'popularity_score' => 0.8,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        Restaurant::factory()->create([
            'name' => 'mission bbq',
            'city' => 'Cleveland',
            'state' => 'OH',
            'is_active' => true,
            'popularity_score' => 0.7,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);

        $data = $this->homeService->getHomepageData(null, null);

        $this->assertCount(1, $data['popularRestaurants']);
        $this->assertSame($best->id, $data['popularRestaurants'][0]['id']);
    }

    public function test_trending_cards_are_slim_plain_arrays(): void
    {
        // spec-114: the Trending payload must be plain arrays limited to the
        // fields the card renders — no Eloquent models, no column bloat.
        $r = Restaurant::factory()->create([
            'name' => 'Slim Pickings',
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);

        $data = $this->homeService->getHomepageData(null, null);

        $card = $data['popularRestaurants'][0];
        $this->assertIsArray($card);
        $this->assertSame(
            [
                'id', 'name', 'slug', 'photo_url', 'city', 'state',
                'price_range', 'google_rating', 'google_review_count',
                'yelp_rating', 'yelp_review_count', 'has_award',
                'popularity_score', 'cuisines',
            ],
            array_keys($card)
        );
        $this->assertSame($r->id, $card['id']);
        $this->assertIsArray($card['cuisines']);
    }

    public function test_categories_scope_to_city_and_fall_back_to_global_when_empty(): void
    {
        $inCity = CuisineCategory::factory()->create(['name' => 'In City', 'slug' => 'in-city']);
        $emptyCat = CuisineCategory::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
        $inCuisine = Cuisine::factory()->create(['category_id' => $inCity->id, 'slug' => 'in-cuisine']);
        Cuisine::factory()->create(['category_id' => $emptyCat->id, 'slug' => 'empty-cuisine']);

        $r = Restaurant::factory()->create(['city' => 'Miami', 'state' => 'FL', 'is_active' => true]);
        Restaurant::whereKey($r->id)->firstOrFail()->cuisines()->attach($inCuisine);

        $scoped = $this->homeService->getHomepageData('Miami', 'Florida');
        $this->assertCount(1, $scoped['categories']);
        $this->assertSame('in-city', $scoped['categories'][0]['slug']);

        $fallback = $this->homeService->getHomepageData('Nowhere', 'NoState');
        $this->assertCount(2, $fallback['categories']);
    }

    public function test_popular_cities_come_from_config_unscoped_by_location(): void
    {
        $configured = config('restaurant-finder.homepage.popular_cities');

        $data = $this->homeService->getHomepageData('Austin', 'Texas');

        $this->assertSame($configured, $data['popularCities']);
    }
}
