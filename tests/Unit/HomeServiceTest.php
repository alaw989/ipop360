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
 * cascade, always-global popular cuisines, city-scoped categories)
 * extracted out of HomeController. Exercised directly here (no HTTP layer);
 * see tests/Feature/HomeControllerTest.php for the response-shape contract.
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
        $this->assertSame($global->id, $data['popularRestaurants']->first()->id);
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
        $this->assertSame($r->id, $data['popularRestaurants']->first()->id);
    }

    public function test_popular_cuisines_are_global_across_cities(): void
    {
        $category = CuisineCategory::factory()->create(['name' => 'Global', 'slug' => 'global']);
        $cuisineA = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'cuisine-a']);
        $cuisineB = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'cuisine-b']);

        Restaurant::whereKey(Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ])->id)->firstOrFail()->cuisines()->attach($cuisineA);

        Restaurant::whereKey(Restaurant::factory()->create([
            'city' => 'Houston',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ])->id)->firstOrFail()->cuisines()->attach($cuisineA);

        Restaurant::whereKey(Restaurant::factory()->create([
            'city' => 'Dallas',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ])->id)->firstOrFail()->cuisines()->attach($cuisineB);

        // Request scoped to Austin — popular cuisines must still count the
        // Dallas + Houston restaurants' cuisines (always global, not city-scoped).
        $data = $this->homeService->getHomepageData('Austin', 'Texas');

        $this->assertCount(2, $data['popularCuisines']);
        $this->assertSame(['cuisine-a', 'cuisine-b'], array_column($data['popularCuisines'], 'slug'));
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
}
