<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Services\LiveSearchService;
use App\Services\UnifiedSearchService;
use App\Services\VenuePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestaurantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_index_loads_successfully(): void
    {
        $response = $this->get('/restaurants');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Restaurants/Index'));
    }

    public function test_restaurant_index_returns_active_restaurants(): void
    {
        Restaurant::factory()->create(['name' => 'Active Place', 'is_active' => true, 'popularity_score' => 0.8]);
        Restaurant::factory()->create(['name' => 'Inactive Place', 'is_active' => false, 'popularity_score' => 0.9]);

        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page->has('restaurants.data', 1));
    }

    public function test_restaurant_index_filters_by_cuisine(): void
    {
        $category = CuisineCategory::factory()->create(['slug' => 'asian']);
        $japanese = Cuisine::factory()->create([
            'name' => 'Japanese',
            'slug' => 'japanese',
            'category_id' => $category->id,
        ]);
        $italian = Cuisine::factory()->create([
            'name' => 'Italian',
            'slug' => 'italian',
            'category_id' => $category->id,
        ]);

        $sushi = Restaurant::whereKey(
            Restaurant::factory()->create(['name' => 'Sushi Place', 'is_active' => true])->id
        )->firstOrFail();
        $pasta = Restaurant::whereKey(
            Restaurant::factory()->create(['name' => 'Pasta Place', 'is_active' => true])->id
        )->firstOrFail();

        $sushi->cuisines()->attach($japanese);
        $pasta->cuisines()->attach($italian);

        $response = $this->get('/restaurants?cuisine=japanese');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 1)
            ->where('restaurants.data.0.name', 'Sushi Place')
            ->where('cuisineName', 'Japanese')
            ->where('categorySlug', 'asian')
        );
    }

    public function test_restaurant_index_filters_by_city_and_state(): void
    {
        Restaurant::factory()->create(['name' => 'Deep Dish Spot', 'city' => 'Chicago', 'state' => 'IL', 'is_active' => true]);
        Restaurant::factory()->create(['name' => 'Elsewhere Spot', 'city' => 'Austin', 'state' => 'TX', 'is_active' => true]);

        // Lowercase input proves the match is case-insensitive against the
        // stored "Chicago"/"IL" casing.
        $response = $this->get('/restaurants?city=chicago&state=il');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 1)
            ->where('restaurants.data.0.name', 'Deep Dish Spot')
            ->where('cityName', 'chicago')
        );
    }

    public function test_restaurant_index_city_filter_disambiguates_same_named_city_in_different_states(): void
    {
        $az = Restaurant::factory()->create(['name' => 'Desert Grill', 'city' => 'Phoenix', 'state' => 'AZ', 'is_active' => true]);
        Restaurant::factory()->create(['name' => 'Harbor Diner', 'city' => 'Phoenix', 'state' => 'MD', 'is_active' => true]);

        $response = $this->get('/restaurants?city=Phoenix&state=AZ');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 1)
            ->where('restaurants.data.0.name', $az->name)
        );
    }

    public function test_restaurant_index_city_filter_never_triggers_live_search(): void
    {
        // The whole point of the city-scoped browse path is that it never
        // risks a live SerpApi call, even if IP geolocation would otherwise
        // resolve coordinates for this request.
        $this->mock(UnifiedSearchService::class, function ($mock) {
            $mock->shouldNotReceive('search');
        });

        Restaurant::factory()->create(['name' => 'Windy City Eats', 'city' => 'Chicago', 'state' => 'IL', 'is_active' => true]);

        $response = $this->get('/restaurants?city=Chicago&state=IL&lat=41.8781&lng=-87.6298');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('restaurants.data', 1));
    }

    public function test_restaurant_index_orders_by_popularity_desc(): void
    {
        Restaurant::factory()->create(['name' => 'Low Score', 'is_active' => true, 'popularity_score' => 0.3]);
        Restaurant::factory()->create(['name' => 'High Score', 'is_active' => true, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Mid Score', 'is_active' => true, 'popularity_score' => 0.6]);

        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Score')
            ->where('restaurants.data.1.name', 'Mid Score')
            ->where('restaurants.data.2.name', 'Low Score')
        );
    }

    public function test_restaurant_index_with_location_accepts_coords(): void
    {
        // Coords route through the unified merged search — stub it so the test
        // doesn't fire real outbound HTTP.
        $this->bindUnifiedSearchResults([]);

        $response = $this->get('/restaurants?lat=37.7749&lng=-122.4194');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Restaurants/Index')
            ->where('filters.lat', '37.7749')
            ->where('filters.lng', '-122.4194')
        );
    }

    public function test_restaurant_index_coords_path_renders_merged_union_in_order(): void
    {
        // The unified merged search owns the ranking; the controller must render
        // the union in the order it is given (no re-sort).
        $this->bindUnifiedSearchResults([
            $this->unionRow(['name' => 'Far but Popular', 'slug' => 'far-but-popular']),
            $this->unionRow(['name' => 'Close but Unpopular', 'slug' => 'close-but-unpopular']),
        ]);

        $response = $this->get('/restaurants?lat=37.7749&lng=-122.4194');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Far but Popular')
            ->where('restaurants.data.1.name', 'Close but Unpopular')
        );
    }

    public function test_restaurant_index_paginates(): void
    {
        Restaurant::factory()->count(25)->create(['is_active' => true, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 20)
            ->where('restaurants.last_page', 2)
        );
    }

    public function test_restaurant_show_page_loads(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
            'is_active' => true,
        ]);

        $response = $this->get("/restaurants/{$restaurant->slug}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Restaurants/Show')
            ->where('restaurant.name', 'Test Restaurant')
        );
    }

    public function test_restaurant_show_includes_cuisines_with_category(): void
    {
        $category = CuisineCategory::factory()->create(['slug' => 'asian']);
        $cuisine = Cuisine::factory()->create(['slug' => 'japanese', 'category_id' => $category->id]);

        $restaurant = Restaurant::whereKey(
            Restaurant::factory()->create(['slug' => 'test-spot'])->id
        )->firstOrFail();
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->get("/restaurants/{$restaurant->slug}");

        $response->assertInertia(fn ($page) => $page
            ->has('restaurant.cuisines', 1)
            ->where('categorySlug', 'asian')
        );
    }

    public function test_restaurant_show_returns_404_for_invalid_slug(): void
    {
        $response = $this->get('/restaurants/nonexistent-restaurant');

        $response->assertStatus(404);
    }

    public function test_restaurant_show_returns_404_for_inactive_restaurant(): void
    {
        // A quarantined (is_active=false) row must not stay publicly viewable via
        // its detail page — route-model binding resolves by slug alone with no
        // active-scope check, so this must be enforced explicitly in show().
        $restaurant = Restaurant::factory()->create([
            'name' => 'Quarantined Place',
            'slug' => 'quarantined-place',
            'is_active' => false,
        ]);

        $response = $this->get("/restaurants/{$restaurant->slug}");

        $response->assertStatus(404);
    }

    public function test_restaurant_index_empty_state(): void
    {
        $response = $this->get('/restaurants?cuisine=mars-colony');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('restaurants.data', 0));
    }

    public function test_restaurant_index_sort_by_rating(): void
    {
        Restaurant::factory()->create(['name' => 'High Rating', 'is_active' => true, 'google_rating' => 4.8, 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Low Rating', 'is_active' => true, 'google_rating' => 3.2, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Mid Rating', 'is_active' => true, 'google_rating' => 4.0, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=rating');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Rating')
            ->where('restaurants.data.1.name', 'Mid Rating')
            ->where('restaurants.data.2.name', 'Low Rating')
        );
    }

    public function test_restaurant_index_sort_by_rating_credibility_sinks_low_review_ratings(): void
    {
        Restaurant::factory()->create(['name' => 'Shaky', 'is_active' => true, 'google_rating' => 4.9, 'google_review_count' => 5, 'popularity_score' => 0.5]);
        Restaurant::factory()->create(['name' => 'Solid', 'is_active' => true, 'google_rating' => 4.7, 'google_review_count' => 500, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=rating');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Solid')
            ->where('restaurants.data.1.name', 'Shaky')
        );
    }

    public function test_restaurant_index_sort_by_rating_credibility_kill_switch_restores_naive_order(): void
    {
        config(['restaurant-finder.ranking.rating_sort_credibility' => false]);

        Restaurant::factory()->create(['name' => 'Shaky', 'is_active' => true, 'google_rating' => 4.9, 'google_review_count' => 5, 'popularity_score' => 0.5]);
        Restaurant::factory()->create(['name' => 'Solid', 'is_active' => true, 'google_rating' => 4.7, 'google_review_count' => 500, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=rating');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Shaky')
            ->where('restaurants.data.1.name', 'Solid')
        );
    }

    public function test_restaurant_index_sort_by_reviews(): void
    {
        Restaurant::factory()->create(['name' => 'Many Reviews', 'is_active' => true, 'google_review_count' => 500, 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Few Reviews', 'is_active' => true, 'google_review_count' => 10, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Some Reviews', 'is_active' => true, 'google_review_count' => 100, 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=reviews');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Many Reviews')
            ->where('restaurants.data.1.name', 'Some Reviews')
            ->where('restaurants.data.2.name', 'Few Reviews')
        );
    }

    public function test_restaurant_index_sort_by_price(): void
    {
        Restaurant::factory()->create(['name' => 'Cheap', 'is_active' => true, 'price_range' => '$', 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Expensive', 'is_active' => true, 'price_range' => '$$$$', 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Mid Price', 'is_active' => true, 'price_range' => '$$', 'popularity_score' => 0.5]);

        $response = $this->get('/restaurants?sort=price');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Cheap')
            ->where('restaurants.data.1.name', 'Mid Price')
            ->where('restaurants.data.2.name', 'Expensive')
        );
    }

    public function test_restaurant_index_sort_by_nearest(): void
    {
        // The unified merged search owns nearest sorting; the controller renders
        // the union as given.
        $this->bindUnifiedSearchResults([
            $this->unionRow(['name' => 'Close', 'slug' => 'close', 'distance' => 0.1]),
            $this->unionRow(['name' => 'Far', 'slug' => 'far', 'distance' => 5.0]),
        ]);

        $response = $this->get('/restaurants?lat=37.7749&lng=-122.4194&sort=nearest');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'Close')
            ->where('restaurants.data.1.name', 'Far')
        );
    }

    public function test_restaurant_index_sort_nearest_without_coords_falls_back_to_best_match(): void
    {
        Restaurant::factory()->create(['name' => 'High Score', 'is_active' => true, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Low Score', 'is_active' => true, 'popularity_score' => 0.1]);

        // Without coords, nearest should fall back to best_match
        $response = $this->get('/restaurants?sort=nearest');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Score')
            ->where('restaurants.data.1.name', 'Low Score')
        );
    }

    public function test_restaurant_index_coords_path_forwards_sort_and_distance_to_unified_search(): void
    {
        $this->mock(UnifiedSearchService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->with(37.7749, -122.4194, null, null, 'nearest', 16.0934)
                ->andReturn([]);
        });

        $this->get('/restaurants?lat=37.7749&lng=-122.4194&sort=nearest&distance=10')->assertStatus(200);
    }

    public function test_restaurant_index_coords_path_paginates_union(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->unionRow(['name' => "Venue {$i}", 'slug' => "venue-{$i}"]);
        }
        $this->bindUnifiedSearchResults($rows);

        $response = $this->get('/restaurants?lat=37.7749&lng=-122.4194');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 20)
            ->where('restaurants.current_page', 1)
            ->where('restaurants.last_page', 2)
        );
    }

    public function test_restaurant_index_coords_path_page2_uses_snapshot_not_search(): void
    {
        $rows = array_map(fn ($i) => $this->unionRow(['name' => "V{$i}", 'slug' => "v{$i}"]), range(1, 25));
        $this->mock(UnifiedSearchService::class, function ($mock) use ($rows) {
            $mock->shouldReceive('search')->once()->andReturn($rows);
        });

        $this->get('/restaurants?lat=37.7749&lng=-122.4194')->assertStatus(200);
        $this->get('/restaurants?lat=37.7749&lng=-122.4194&page=2')->assertStatus(200);
        // Mockery's once() is verified at teardown — page 2 must not call search().
    }

    public function test_restaurant_index_sort_best_match_is_default(): void
    {
        Restaurant::factory()->create(['name' => 'High Score', 'is_active' => true, 'popularity_score' => 0.9]);
        Restaurant::factory()->create(['name' => 'Low Score', 'is_active' => true, 'popularity_score' => 0.1]);

        // Without sort parameter, should use best_match (popularity_score)
        $response = $this->get('/restaurants');

        $response->assertInertia(fn ($page) => $page
            ->where('restaurants.data.0.name', 'High Score')
            ->where('restaurants.data.1.name', 'Low Score')
        );
    }

    public function test_restaurant_index_invalid_sort_is_rejected(): void
    {
        $response = $this->get('/restaurants?sort=invalid_mode');

        $response->assertStatus(302); // Redirect back with validation error
    }

    public function test_restaurant_index_sort_included_in_filters(): void
    {
        $response = $this->get('/restaurants?sort=rating');

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'rating')
        );
    }

    public function test_restaurant_api_sort_by_rating(): void
    {
        Restaurant::factory()->create(['name' => 'High Rating', 'is_active' => true, 'google_rating' => 4.8, 'popularity_score' => 0.1]);
        Restaurant::factory()->create(['name' => 'Low Rating', 'is_active' => true, 'google_rating' => 3.2, 'popularity_score' => 0.9]);

        $response = $this->get('/api/restaurants?sort=rating');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame('High Rating', $data[0]['name']);
        $this->assertSame('Low Rating', $data[1]['name']);
    }

    public function test_restaurant_api_sort_by_nearest(): void
    {
        // Unified merged search always fires the live search on the coords path;
        // stub it to contribute nothing so the union is the two DB rows.
        $this->bindLiveSearchResults([]);

        Restaurant::factory()->create([
            'name' => 'Close',
            'is_active' => true,
            'latitude' => 37.7750,
            'longitude' => -122.4195,
            'popularity_score' => 0.1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Far',
            'is_active' => true,
            'latitude' => 37.7900,
            'longitude' => -122.4000,
            'popularity_score' => 0.9,
        ]);

        $response = $this->get('/api/restaurants?lat=37.7749&lng=-122.4194&sort=nearest');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame('Close', $data[0]['name']);
        $this->assertSame('Far', $data[1]['name']);
    }

    /**
     * Bind a LiveSearchService mock that returns the given result array, so the
     * empty-DB + coords request falls into apiIndex's live-search branch.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    private function bindLiveSearchResults(array $results): void
    {
        $this->mock(LiveSearchService::class, function ($mock) use ($results) {
            $mock->shouldReceive('search')->andReturn($results);
        });
    }

    /**
     * Bind a UnifiedSearchService mock that returns the given merged union, so
     * coords-bearing browse/api requests don't fire real outbound HTTP.
     *
     * @param  array<int, array<string, mixed>>  $union
     */
    private function bindUnifiedSearchResults(array $union): void
    {
        $this->mock(UnifiedSearchService::class, function ($mock) use ($union) {
            $mock->shouldReceive('search')->andReturn($union);
        });
    }

    /**
     * Minimal merged-union row shape for the coords path.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unionRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Venue',
            'slug' => 'venue',
            'lat' => 37.7749,
            'lng' => -122.4194,
            'distance' => null,
            'google_rating' => null,
            'google_review_count' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'price_range' => null,
            'popularity_score' => 0.5,
            'cuisines' => [],
            'source' => 'serpapi',
        ], $overrides);
    }

    /**
     * Minimal live-search row shape with sensible defaults.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function liveRow(array $overrides = []): array
    {
        return array_merge([
            'id' => null,
            'name' => 'Venue',
            'slug' => 'venue',
            'lat' => 30.0,
            'lng' => -88.0,
            'distance' => null,
            'google_rating' => null,
            'google_review_count' => null,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'price_range' => null,
            'popularity_score' => 0.0,
            'source' => 'serpapi',
        ], $overrides);
    }

    // spec-069: sort logic lives in VenuePipeline::sortVenues (called inside
    // LiveSearchService::search before bounding). These unit-test it directly;
    // the controller wiring is covered by test_api_live_sort_preserves_response_shape.

    public function test_sort_venues_best_match_preserves_score_order(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'High', 'popularity_score' => 0.9],
            ['name' => 'Mid', 'popularity_score' => 0.5],
            ['name' => 'Low', 'popularity_score' => 0.1],
        ];

        $this->assertSame(['High', 'Mid', 'Low'], array_column($pipeline->sortVenues($rows, 'best_match', true), 'name'));
    }

    public function test_sort_venues_nearest_orders_by_distance_asc(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Far', 'distance' => 5.0, 'popularity_score' => 0.9],
            ['name' => 'Close', 'distance' => 0.5, 'popularity_score' => 0.1],
            ['name' => 'Mid', 'distance' => 2.0, 'popularity_score' => 0.5],
        ];

        $this->assertSame(['Close', 'Mid', 'Far'], array_column($pipeline->sortVenues($rows, 'nearest', true), 'name'));
    }

    public function test_sort_venues_rating_orders_desc_with_nulls_last(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'RatedLow', 'google_rating' => 3.5, 'google_review_count' => 500, 'popularity_score' => 0.3],
            ['name' => 'RatedHigh', 'google_rating' => 4.8, 'google_review_count' => 500, 'popularity_score' => 0.2],
            ['name' => 'UnratedPopular', 'popularity_score' => 0.99],
            ['name' => 'UnratedOther', 'popularity_score' => 0.4],
        ];

        $this->assertSame(
            ['RatedHigh', 'RatedLow', 'UnratedPopular', 'UnratedOther'],
            array_column($pipeline->sortVenues($rows, 'rating', true), 'name')
        );
    }

    public function test_sort_venues_rating_credibility_sinks_low_review_ratings(): void
    {
        // spec-069 4C: a 4.9★/5-review venue sinks below a 4.7★/500-review venue.
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Shaky', 'google_rating' => 4.9, 'google_review_count' => 5, 'popularity_score' => 0.5],
            ['name' => 'Solid', 'google_rating' => 4.7, 'google_review_count' => 500, 'popularity_score' => 0.5],
        ];

        $this->assertSame(['Solid', 'Shaky'], array_column($pipeline->sortVenues($rows, 'rating', true), 'name'));
    }

    public function test_sort_venues_reviews_orders_desc_with_nulls_last(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Tens', 'google_review_count' => 100, 'popularity_score' => 0.3],
            ['name' => 'Hundreds', 'google_review_count' => 500, 'popularity_score' => 0.3],
            ['name' => 'Zero', 'google_review_count' => 0, 'popularity_score' => 0.3],
            ['name' => 'Missing', 'popularity_score' => 0.99],
        ];

        $this->assertSame(
            ['Hundreds', 'Tens', 'Zero', 'Missing'],
            array_column($pipeline->sortVenues($rows, 'reviews', true), 'name')
        );
    }

    public function test_sort_venues_price_orders_asc_using_normalizer(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Single', 'price_range' => '$', 'popularity_score' => 0.1],
            ['name' => 'FiveDollar', 'price_range' => '$5', 'popularity_score' => 0.9],
            ['name' => 'Four', 'price_range' => '$$$$', 'popularity_score' => 0.5],
            ['name' => 'Unknown', 'price_range' => null, 'popularity_score' => 0.3],
        ];

        $this->assertSame(
            ['FiveDollar', 'Single', 'Four', 'Unknown'],
            array_column($pipeline->sortVenues($rows, 'price', true), 'name')
        );
    }

    public function test_sort_venues_tiebreak_by_popularity_then_name(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Zeta', 'google_rating' => 4.5, 'google_review_count' => 500, 'popularity_score' => 0.5],
            ['name' => 'Alpha', 'google_rating' => 4.5, 'google_review_count' => 500, 'popularity_score' => 0.5],
            ['name' => 'Beta', 'google_rating' => 4.5, 'google_review_count' => 500, 'popularity_score' => 0.9],
        ];

        $this->assertSame(['Beta', 'Alpha', 'Zeta'], array_column($pipeline->sortVenues($rows, 'rating', true), 'name'));
    }

    public function test_sort_venues_social_presence_puts_venues_with_links_first(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'NoLinks', 'social_links_count' => 0, 'popularity_score' => 0.9],
            ['name' => 'Linked', 'social_links_count' => 3, 'popularity_score' => 0.2],
            ['name' => 'MissingLinks', 'popularity_score' => 0.5],
        ];

        $this->assertSame(
            ['Linked', 'NoLinks', 'MissingLinks'],
            array_column($pipeline->sortVenues($rows, 'social_presence', true), 'name')
        );
    }

    public function test_sort_venues_website_traffic_orders_clicks_desc_with_nulls_last(): void
    {
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Few', 'website_clicks_count' => 10, 'popularity_score' => 0.9],
            ['name' => 'Many', 'website_clicks_count' => 500, 'popularity_score' => 0.1],
            ['name' => 'None', 'website_clicks_count' => 0, 'popularity_score' => 0.9],
            ['name' => 'Missing', 'popularity_score' => 0.5],
        ];

        $this->assertSame(
            ['Many', 'Few', 'None', 'Missing'],
            array_column($pipeline->sortVenues($rows, 'website_traffic', true), 'name')
        );
    }

    public function test_api_live_sort_preserves_response_shape(): void
    {
        $this->bindLiveSearchResults([
            $this->liveRow(['name' => 'A', 'distance' => 2.0]),
            $this->liveRow(['name' => 'B', 'distance' => 1.0]),
            $this->liveRow(['name' => 'C', 'distance' => 3.0]),
        ]);

        $response = $this->get('/api/restaurants?lat=30.0&lng=-88.0&sort=nearest');

        $response->assertStatus(200);
        $response->assertJsonPath('is_live', true);
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('next_page_url', null);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_api_live_pagination_slices_pages_with_next_url(): void
    {
        // spec-068: 25 results → page 1 = 20 rows + next_page_url(page=2); page 2 = 5, next null.
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->liveRow(['name' => "Venue {$i}", 'slug' => "venue-{$i}"]);
        }
        $this->bindLiveSearchResults($rows);

        $r1 = $this->get('/api/restaurants?lat=30.0&lng=-88.0');
        $r1->assertStatus(200);
        $this->assertCount(20, $r1->json('data'));
        $this->assertSame(25, $r1->json('total'));
        $this->assertSame(1, $r1->json('current_page'));
        $this->assertSame(2, $r1->json('last_page'));
        $this->assertNotNull($r1->json('next_page_url'));
        $this->assertStringContainsString('page=2', $r1->json('next_page_url'));

        // Page 2 serves from the page-1 snapshot.
        $r2 = $this->get('/api/restaurants?lat=30.0&lng=-88.0&page=2');
        $r2->assertStatus(200);
        $this->assertCount(5, $r2->json('data'));
        $this->assertSame(25, $r2->json('total'));
        $this->assertNull($r2->json('next_page_url'));
    }

    public function test_api_live_pagination_page2_uses_snapshot_not_search(): void
    {
        // search() runs ONCE (page 1); page 2 must slice the snapshot, not re-search.
        $rows = array_map(fn ($i) => $this->liveRow(['name' => "V{$i}", 'slug' => "v{$i}"]), range(1, 25));
        $this->mock(LiveSearchService::class, function ($mock) use ($rows) {
            $mock->shouldReceive('search')->once()->andReturn($rows);
        });

        $this->get('/api/restaurants?lat=30.0&lng=-88.0')->assertStatus(200);
        $this->get('/api/restaurants?lat=30.0&lng=-88.0&page=2')->assertStatus(200);
        // Mockery's `once()` is verified at teardown — page 2 must not call search().
    }

    public function test_api_live_pagination_kill_switch_returns_all_on_one_page(): void
    {
        Config::set('restaurant-finder.live_search.paginate', false);
        $rows = array_map(fn ($i) => $this->liveRow(['name' => "V{$i}", 'slug' => "v{$i}"]), range(1, 25));
        $this->bindLiveSearchResults($rows);

        $r = $this->get('/api/restaurants?lat=30.0&lng=-88.0');
        $r->assertStatus(200);
        $this->assertCount(25, $r->json('data'));
        $this->assertNull($r->json('next_page_url'));
    }

    public function test_api_live_results_are_snapshotted_by_slug_for_preview(): void
    {
        // apiIndex writes each live result under preview:{slug} so the detail page
        // (preview()) can render it from a direct lookup instead of reconstructing
        // it via a cache-only re-search (spec-040).
        $this->bindLiveSearchResults([
            $this->liveRow(['name' => 'Alpha', 'slug' => 'alpha-aaaaaa']),
            $this->liveRow(['name' => 'Beta', 'slug' => 'beta-bbbbbb']),
        ]);

        $response = $this->get('/api/restaurants?lat=30.0&lng=-88.0');

        $response->assertStatus(200);
        $previewAlpha = ExternalApiCache::findByKey('preview:alpha-aaaaaa');
        $previewBeta = ExternalApiCache::findByKey('preview:beta-bbbbbb');
        $this->assertNotNull($previewAlpha);
        $this->assertNotNull($previewBeta);
        $this->assertSame('Alpha', $previewAlpha['name']);
        $this->assertSame('Beta', $previewBeta['name']);
    }

    public function test_api_live_snapshot_writes_run_in_a_single_transaction(): void
    {
        // snapshotLiveResults() loops up to 20 live results calling
        // ExternalApiCache::storeByKey() (an updateOrCreate = SELECT+UPSERT) per
        // venue. spec-095: the loop must be wrapped in one DB::transaction so N
        // per-row commits collapse to a single commit (round-trip batching win,
        // not a query-shape change). Assert every preview write happens inside a
        // DB transaction (depth >= 1), not in autocommit (depth 0).
        $this->bindLiveSearchResults([
            $this->liveRow(['name' => 'Alpha', 'slug' => 'alpha-aaaaaa']),
            $this->liveRow(['name' => 'Beta', 'slug' => 'beta-bbbbbb']),
            $this->liveRow(['name' => 'Gamma', 'slug' => 'gamma-cccccc']),
        ]);

        $depth = 0;
        $this->app['events']->listen('Illuminate\Database\Events\TransactionBeginning', function () use (&$depth): void {
            $depth++;
        });
        $this->app['events']->listen('Illuminate\Database\Events\TransactionCommitted', function () use (&$depth): void {
            $depth--;
        });
        $this->app['events']->listen('Illuminate\Database\Events\TransactionRolledBack', function () use (&$depth): void {
            $depth--;
        });

        $previewDepths = [];
        DB::listen(function ($query) use (&$depth, &$previewDepths): void {
            foreach ($query->bindings as $binding) {
                if (is_string($binding) && str_starts_with($binding, 'preview:')) {
                    $previewDepths[] = $depth;
                    break;
                }
            }
        });

        $response = $this->get('/api/restaurants?lat=30.0&lng=-88.0');

        $response->assertStatus(200);
        $this->assertNotEmpty($previewDepths, 'expected preview: snapshot writes');
        foreach ($previewDepths as $d) {
            $this->assertGreaterThan(0, $d, 'preview writes should be inside a transaction');
        }
    }

    public function test_sort_venues_nearest_without_coords_falls_back_to_best_match(): void
    {
        // 'nearest' without coords must NOT reorder by distance (falls back to
        // best_match = score order, unchanged).
        $pipeline = $this->app->make(VenuePipeline::class);
        $rows = [
            ['name' => 'Far', 'distance' => 5.0, 'popularity_score' => 0.9],
            ['name' => 'Close', 'distance' => 0.5, 'popularity_score' => 0.1],
        ];

        $this->assertSame(['Far', 'Close'], array_column($pipeline->sortVenues($rows, 'nearest', false), 'name'));
    }
}
