<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_loads_successfully(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Welcome'));
    }

    public function test_landing_page_passes_categories_to_view(): void
    {
        $category = CuisineCategory::factory()->create([
            'name' => 'Asian',
            'slug' => 'asian',
            'icon' => '🍜',
            'sort_order' => 1,
        ]);
        Cuisine::factory()->count(3)->create(['category_id' => $category->id]);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('categories.0.name', 'Asian')
            ->where('categories.0.slug', 'asian')
            ->has('categories.0.cuisines', 3)
        );
    }

    public function test_categories_are_ordered_by_sort_order(): void
    {
        CuisineCategory::factory()->create(['name' => 'Zebra', 'slug' => 'zebra', 'sort_order' => 2]);
        CuisineCategory::factory()->create(['name' => 'Alpha', 'slug' => 'alpha', 'sort_order' => 1]);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('categories.0.name', 'Alpha')
            ->where('categories.1.name', 'Zebra')
        );
    }

    public function test_landing_page_works_with_no_categories(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('categories', 0));
    }

    public function test_api_data_returns_expected_structure(): void
    {
        $category = CuisineCategory::factory()->create(['name' => 'TestCat', 'slug' => 'test-cat']);
        $cuisine = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'test-cuisine']);
        $restaurant = Restaurant::factory()->create([
            'city' => 'TestCity',
            'state' => 'TestState',
            'is_active' => true,
        ]);
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'categories',
            'popularCuisines',
            'popularRestaurants',
            'location',
        ]);
        $response->assertJsonCount(1, 'categories');
        $response->assertJsonCount(1, 'popularRestaurants');
    }

    public function test_api_data_scopes_to_city(): void
    {
        $category = CuisineCategory::factory()->create(['name' => 'Scoped', 'slug' => 'scoped']);
        $cuisine = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'scoped-cuisine']);
        $restaurant = Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'Texas',
            'is_active' => true,
        ]);
        $restaurant->cuisines()->attach($cuisine);

        Restaurant::factory()->create([
            'city' => 'Dallas',
            'state' => 'Texas',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/homepage-data?city=Austin&state=Texas');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson([
            'location' => ['city' => 'Austin', 'state' => 'Texas'],
        ]);
    }

    public function test_api_data_returns_global_when_no_city_data(): void
    {
        $category = CuisineCategory::factory()->create(['name' => 'Global', 'slug' => 'global']);
        $cuisine = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'global-cuisine']);

        $restaurant = Restaurant::factory()->create([
            'city' => 'KnownCity',
            'state' => 'KnownState',
            'is_active' => true,
            'popularity_score' => 0.9,
        ]);
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/homepage-data?city=Unknown&state=Nowhere');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson(['location' => null]);
    }

    public function test_api_data_scopes_categories_to_city(): void
    {
        $inCity = CuisineCategory::factory()->create(['name' => 'In City', 'slug' => 'in-city']);
        $emptyCat = CuisineCategory::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
        $inCuisine = Cuisine::factory()->create(['category_id' => $inCity->id, 'slug' => 'in-cuisine']);
        Cuisine::factory()->create(['category_id' => $emptyCat->id, 'slug' => 'empty-cuisine']);

        $restaurant = Restaurant::factory()->create([
            'city' => 'Miami',
            'state' => 'Florida',
            'is_active' => true,
        ]);
        $restaurant->cuisines()->attach($inCuisine);

        $response = $this->getJson('/api/homepage-data?city=Miami&state=Florida');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'categories');
        $response->assertJson(['categories' => [['slug' => 'in-city']]]);
    }

    public function test_api_data_falls_back_to_global_categories_when_none_in_city(): void
    {
        $cat = CuisineCategory::factory()->create(['name' => 'Fallback', 'slug' => 'fallback']);
        $cuisine = Cuisine::factory()->create(['category_id' => $cat->id, 'slug' => 'fallback-cuisine']);

        Restaurant::factory()->create([
            'city' => 'Somewhere',
            'state' => 'SomeState',
            'is_active' => true,
        ])->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/homepage-data?city=Emptyville&state=EmptyState');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'categories');
    }
}
