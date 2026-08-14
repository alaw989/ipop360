<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use Database\Seeders\CuisineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_page_loads_without_params(): void
    {
        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Search'));
    }

    public function test_search_with_cuisine_and_no_price_range_does_not_crash(): void
    {
        $category = CuisineCategory::factory()->create(['slug' => 'american']);
        $cuisine = Cuisine::factory()->create([
            'name' => 'Tex-Mex',
            'slug' => 'tex-mex',
            'category_id' => $category->id,
        ]);
        $restaurant = Restaurant::whereKey(
            Restaurant::factory()->create(['is_active' => true])->id
        )->firstOrFail();
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->get('/search?cuisine=tex-mex');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Search'));
    }

    public function test_search_with_price_range_param_works(): void
    {
        $response = $this->get('/search?price_range=$$');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Search'));
    }

    public function test_search_with_category_and_no_cuisine_works(): void
    {
        CuisineCategory::factory()->create(['slug' => 'asian']);

        $response = $this->get('/search?category=asian');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Search'));
    }

    public function test_has_coords_is_false_when_no_coords_and_no_fallback_configured(): void
    {
        Config::set('restaurant-finder.live_search.distance_fallback_lat', null);
        Config::set('restaurant-finder.live_search.distance_fallback_lng', null);

        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('hasCoords', false));
    }

    public function test_has_coords_is_false_when_no_coords_and_distance_no_fallback(): void
    {
        Config::set('restaurant-finder.live_search.distance_fallback_lat', null);
        Config::set('restaurant-finder.live_search.distance_fallback_lng', null);

        $response = $this->get('/search?distance=10');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('hasCoords', false));
    }

    public function test_has_coords_is_true_when_distance_and_fallback_configured(): void
    {
        Config::set('restaurant-finder.live_search.distance_fallback_lat', 30.6199);
        Config::set('restaurant-finder.live_search.distance_fallback_lng', -88.1967);

        $response = $this->get('/search?distance=10');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('hasCoords', true));
    }

    public function test_fallback_used_when_configured_even_without_distance_param(): void
    {
        Config::set('restaurant-finder.live_search.distance_fallback_lat', 30.6199);
        Config::set('restaurant-finder.live_search.distance_fallback_lng', -88.1967);

        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('hasCoords', true));
    }

    private function bindCoordsAndReverseGeocode(): void
    {
        $this->mock(GeolocationService::class, function ($mock) {
            $mock->shouldReceive('resolveCoordinates')->andReturn(['lat' => 30.0, 'lng' => -88.0]);
            $mock->shouldReceive('reverseGeocode')->andReturn(null);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function bindLiveSearchResults(array $results): void
    {
        $this->mock(LiveSearchService::class, function ($mock) use ($results) {
            $mock->shouldReceive('search')->andReturn($results);
        });
    }

    public function test_empty_db_runs_live_search_immediately_and_renders_results(): void
    {
        $this->bindCoordsAndReverseGeocode();
        $this->bindLiveSearchResults([
            [
                'name' => 'Live Bistro',
                'slug' => 'live-bistro',
                'lat' => 30.0,
                'lng' => -88.0,
                'source' => 'serpapi',
                'popularity_score' => 0.5,
            ],
        ]);

        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Search')
            ->has('restaurants.data', 1)
            ->where('restaurants.data.0.name', 'Live Bistro')
        );
    }

    public function test_empty_db_with_no_live_results_shows_honest_empty_state(): void
    {
        $this->bindCoordsAndReverseGeocode();
        $this->bindLiveSearchResults([]);

        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Search')
            ->has('restaurants.data', 0)
        );
    }

    public function test_scoped_live_search_attaches_searched_cuisine_so_results_are_found(): void
    {
        $this->seed(CuisineSeeder::class);

        $this->bindCoordsAndReverseGeocode();
        $this->bindLiveSearchResults([
            [
                'name' => 'Trattoria Roma',
                'slug' => 'trattoria-roma',
                'lat' => 30.0,
                'lng' => -88.0,
                'source' => 'serpapi',
                'popularity_score' => 0.5,
            ],
        ]);

        $response = $this->get('/search?cuisine=italian');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Search')
            ->has('restaurants.data', 1)
            ->where('restaurants.data.0.name', 'Trattoria Roma')
            ->where('cuisineName', 'Italian')
        );
    }
}
