<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Models\User;
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
        $r = Restaurant::factory()->create([
            'city' => 'TestCity',
            'state' => 'TestState',
            'is_active' => true,
        ]);
        $restaurant = Restaurant::whereKey($r->id)->firstOrFail();
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'categories',
            'popularCuisines',
            'popularRestaurants',
            'location',
            'stats',
        ]);
        $response->assertJsonCount(1, 'categories');
        $response->assertJsonCount(1, 'popularRestaurants');
    }

    public function test_landing_page_passes_stats(): void
    {
        Cuisine::factory()->create();
        Restaurant::factory()->count(3)->create(['city' => 'Austin', 'state' => 'TX', 'is_active' => true]);
        Restaurant::factory()->create(['city' => 'Dallas', 'state' => 'TX', 'is_active' => true]);
        Restaurant::factory()->create(['city' => 'Dallas', 'state' => 'TX', 'is_active' => false]);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.restaurants', 4)
            ->where('stats.cuisines', 1)
            ->where('stats.cities', 2)
        );
    }

    public function test_api_data_returns_stats(): void
    {
        Cuisine::factory()->count(2)->create();
        foreach (['Austin', 'Dallas', 'Houston', 'Miami', 'Denver'] as $city) {
            Restaurant::factory()->create(['city' => $city, 'is_active' => true]);
        }

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJson([
            'stats' => [
                'restaurants' => 5,
                'cuisines' => 2,
                'cities' => 5,
            ],
        ]);
    }

    public function test_api_data_scopes_to_city(): void
    {
        $category = CuisineCategory::factory()->create(['name' => 'Scoped', 'slug' => 'scoped']);
        $cuisine = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'scoped-cuisine']);
        $r = Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'Texas',
            'is_active' => true,
        ]);
        $restaurant = Restaurant::whereKey($r->id)->firstOrFail();
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

        $r = Restaurant::factory()->create([
            'city' => 'KnownCity',
            'state' => 'KnownState',
            'is_active' => true,
            'popularity_score' => 0.9,
        ]);
        $restaurant = Restaurant::whereKey($r->id)->firstOrFail();
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

        $r = Restaurant::factory()->create([
            'city' => 'Miami',
            'state' => 'Florida',
            'is_active' => true,
        ]);
        $restaurant = Restaurant::whereKey($r->id)->firstOrFail();
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

        $r = Restaurant::factory()->create([
            'city' => 'Somewhere',
            'state' => 'SomeState',
            'is_active' => true,
        ]);
        Restaurant::whereKey($r->id)->firstOrFail()->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/homepage-data?city=Emptyville&state=EmptyState');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'categories');
    }

    public function test_landing_page_passes_latest_posts(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);
        $post = BlogPost::factory()->create([
            'title' => 'Best Tacos in Austin',
            'slug' => 'best-tacos-in-austin',
            'excerpt' => 'A guide to the top taco spots.',
            'category' => 'Food Guides',
            'is_featured' => true,
            'author_id' => $user->id,
        ]);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->has('latestPosts', 1)
            ->where('latestPosts.0.id', $post->id)
            ->where('latestPosts.0.title', 'Best Tacos in Austin')
            ->where('latestPosts.0.slug', 'best-tacos-in-austin')
            ->where('latestPosts.0.excerpt', 'A guide to the top taco spots.')
            ->where('latestPosts.0.category', 'Food Guides')
            ->where('latestPosts.0.is_featured', true)
            ->where('latestPosts.0.author.name', 'Alice')
        );
    }

    public function test_landing_page_excludes_draft_posts(): void
    {
        BlogPost::factory()->create(['title' => 'Visible Post']);
        BlogPost::factory()->draft()->create(['title' => 'Hidden Draft']);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->has('latestPosts', 1)
            ->where('latestPosts.0.title', 'Visible Post')
        );
    }

    public function test_landing_page_orders_featured_posts_first(): void
    {
        BlogPost::factory()->create([
            'title' => 'Regular Post',
            'is_featured' => false,
        ]);
        BlogPost::factory()->create([
            'title' => 'Featured Post',
            'is_featured' => true,
        ]);

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->has('latestPosts', 2)
            ->where('latestPosts.0.title', 'Featured Post')
            ->where('latestPosts.1.title', 'Regular Post')
        );
    }

    public function test_landing_page_limits_to_three_posts(): void
    {
        BlogPost::factory()->count(5)->create();

        $response = $this->get('/');

        $response->assertInertia(fn ($page) => $page
            ->has('latestPosts', 3)
        );
    }

    public function test_api_data_excludes_latest_posts(): void
    {
        $user = User::factory()->create(['name' => 'Bob']);
        BlogPost::factory()->create([
            'title' => 'API Post',
            'slug' => 'api-post',
            'author_id' => $user->id,
        ]);

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200)
            ->assertJsonMissing(['latestPosts']);
    }

    public function test_landing_page_handles_no_posts(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('latestPosts', 0)
        );
    }

    public function test_api_data_handles_no_posts(): void
    {
        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200)
            ->assertJsonMissing(['latestPosts']);
    }
}
