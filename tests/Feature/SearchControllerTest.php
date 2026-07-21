<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
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
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
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

    public function test_search_with_category_and_coords_from_cycling_returns_results(): void
    {
        Config::set('restaurant-finder.live_search.distance_fallback_lat', null);
        Config::set('restaurant-finder.live_search.distance_fallback_lng', null);

        $category = CuisineCategory::factory()->create(['slug' => 'american']);
        $cuisine = Cuisine::factory()->create([
            'slug' => 'tex-mex',
            'category_id' => $category->id,
        ]);
        $restaurant = Restaurant::factory()->create([
            'latitude' => 30.6944,
            'longitude' => -88.0431,
            'is_active' => true,
        ]);
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->get('/search?category=american&lat=30.6944&lng=-88.0431&distance=25');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Search')
            ->where('hasCoords', true)
        );
    }

    public function test_search_with_cuisine_and_coords_from_cycling_returns_results(): void
    {
        Config::set('restaurant-finder.live_search.distance_fallback_lat', null);
        Config::set('restaurant-finder.live_search.distance_fallback_lng', null);

        $category = CuisineCategory::factory()->create(['slug' => 'asian']);
        $cuisine = Cuisine::factory()->create([
            'name' => 'Sushi',
            'slug' => 'sushi',
            'category_id' => $category->id,
        ]);
        $restaurant = Restaurant::factory()->create([
            'latitude' => 30.6944,
            'longitude' => -88.0431,
            'is_active' => true,
        ]);
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->get('/search?cuisine=sushi&lat=30.6944&lng=-88.0431&distance=25');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Search')
            ->where('hasCoords', true)
        );
    }
}
