<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\UnifiedSearchService;
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

        // Coords now route through the merged (always-live) path — stub it so the
        // test doesn't fire real outbound HTTP.
        $this->bindUnifiedSearchResults([]);

        $response = $this->get('/search?distance=10');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('hasCoords', true));
    }

    public function test_fallback_used_when_configured_even_without_distance_param(): void
    {
        Config::set('restaurant-finder.live_search.distance_fallback_lat', 30.6199);
        Config::set('restaurant-finder.live_search.distance_fallback_lng', -88.1967);

        $this->bindUnifiedSearchResults([]);

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
            'lat' => 30.0,
            'lng' => -88.0,
            'source' => 'serpapi',
            'popularity_score' => 0.5,
            'cuisines' => [],
        ], $overrides);
    }

    public function test_coords_path_renders_merged_union_results(): void
    {
        $this->bindCoordsAndReverseGeocode();
        $this->bindUnifiedSearchResults([
            $this->unionRow(['name' => 'Live Bistro', 'slug' => 'live-bistro']),
        ]);

        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Search')
            ->has('restaurants.data', 1)
            ->where('restaurants.data.0.name', 'Live Bistro')
        );
    }

    public function test_coords_path_with_empty_union_shows_honest_empty_state(): void
    {
        $this->bindCoordsAndReverseGeocode();
        $this->bindUnifiedSearchResults([]);

        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Search')
            ->has('restaurants.data', 0)
        );
    }

    public function test_scoped_search_renders_merged_union_and_resolves_cuisine_name(): void
    {
        $this->seed(CuisineSeeder::class);

        $this->bindCoordsAndReverseGeocode();
        $this->bindUnifiedSearchResults([
            $this->unionRow(['name' => 'Trattoria Roma', 'slug' => 'trattoria-roma']),
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

    public function test_coords_path_forwards_filters_and_sort_to_unified_search(): void
    {
        $this->bindCoordsAndReverseGeocode();
        $this->mock(UnifiedSearchService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->with(30.0, -88.0, null, null, 'nearest', 10.0, '$$')
                ->andReturn([]);
        });

        $response = $this->get('/search?distance=10&sort=nearest&price_range=$$');

        $response->assertStatus(200);
    }

    public function test_coords_path_page2_uses_snapshot_not_search(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->unionRow(['name' => "Venue {$i}", 'slug' => "venue-{$i}"]);
        }

        $this->bindCoordsAndReverseGeocode();
        $this->mock(UnifiedSearchService::class, function ($mock) use ($rows) {
            $mock->shouldReceive('search')->once()->andReturn($rows);
        });

        $this->get('/search')->assertStatus(200);
        $this->get('/search?page=2')->assertStatus(200);
        // Mockery's once() is verified at teardown — page 2 must not call search().
    }

    public function test_coords_path_paginates_union(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->unionRow(['name' => "Venue {$i}", 'slug' => "venue-{$i}"]);
        }

        $this->bindCoordsAndReverseGeocode();
        $this->bindUnifiedSearchResults($rows);

        $response = $this->get('/search');

        $response->assertInertia(fn ($page) => $page
            ->has('restaurants.data', 20)
            ->where('restaurants.current_page', 1)
            ->where('restaurants.last_page', 2)
        );
    }
}
