<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
