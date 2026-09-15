<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_root_view_is_no_js_by_default_and_swaps_to_js_for_progressive_enhancement(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('class="no-js"', false);
        $response->assertSee("document.documentElement.classList.replace('no-js', 'js')", false);
    }

    public function test_viewport_meta_opts_into_safe_area_insets(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('viewport-fit=cover', false);
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

    public function test_cuisine_categories_endpoint_lists_every_category_for_the_header_search(): void
    {
        $asian = CuisineCategory::factory()->create(['name' => 'Asian', 'slug' => 'asian', 'sort_order' => 2]);
        CuisineCategory::factory()->create(['name' => 'American', 'slug' => 'american', 'sort_order' => 1]);
        Cuisine::factory()->count(2)->create(['category_id' => $asian->id]);

        $response = $this->getJson('/api/cuisine-categories');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.slug', 'american')
            ->assertJsonPath('1.slug', 'asian')
            ->assertJsonCount(2, '1.cuisines')
            ->assertJsonStructure(['*' => ['id', 'name', 'slug', 'icon', 'cuisines']])
            ->assertJsonStructure(['1' => ['cuisines' => ['*' => ['id', 'name', 'slug', 'icon']]]]);
        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
    }

    public function test_cuisine_categories_endpoint_survives_a_serializing_cache_store(): void
    {
        // Prod caches /api/cuisine-categories under a serializing store
        // (database) with serializable_classes=false, so any Collection left in
        // the cached payload unserializes as __PHP_Incomplete_Class. The default
        // test store is `array` (serialize=false), which never exercises that
        // round-trip — force `file` (a serializing store) to reproduce it.
        config(['cache.default' => 'file']);
        Cache::flush();

        $asian = CuisineCategory::factory()->create(['name' => 'Asian', 'slug' => 'asian', 'sort_order' => 1]);
        Cuisine::factory()->count(2)->create(['category_id' => $asian->id]);

        // First request warms the cache (returns the raw, uncorrupted value).
        // The second reads back through the serializing store's unserialize —
        // the round-trip that turns any lingering Collection into
        // __PHP_Incomplete_Class.
        $this->getJson('/api/cuisine-categories')->assertOk();
        $response = $this->getJson('/api/cuisine-categories');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonCount(2, '0.cuisines')
            ->assertJsonStructure([
                '*' => ['id', 'name', 'slug', 'icon', 'cuisines' => ['*' => ['id', 'name', 'slug', 'icon']]],
            ]);
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
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
            'popularity_score' => 0.9,
        ]);
        $restaurant = Restaurant::whereKey($r->id)->firstOrFail();
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'categories',
            'popularCities',
            'popularRestaurants',
            'featuredRestaurant',
            'location',
        ]);
        $response->assertJsonCount(1, 'categories');
        $response->assertJsonCount(1, 'popularRestaurants');
    }

    public function test_api_data_does_not_ship_dead_homepage_props(): void
    {
        // spec-114: stats/popularCuisines were computed (three uncached COUNTs
        // for stats alone) and returned on every homepage request, but no
        // component consumed either. They must not come back.
        Restaurant::factory()->count(3)->create(['is_active' => true]);

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('stats');
        $response->assertJsonMissingPath('popularCuisines');
    }

    public function test_trending_restaurants_ship_a_slim_card_payload(): void
    {
        // The Trending card renders name/photo/rating/price/cuisine only —
        // shipping full models dragged photos, ai_metadata, score_breakdown
        // and every other column along for ~18 cards.
        $r = Restaurant::factory()->create([
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
            'city' => 'Austin',
            'state' => 'TX',
        ]);

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'popularRestaurants' => [[
                'id',
                'name',
                'slug',
                'photo_url',
                'city',
                'state',
                'price_range',
                'google_rating',
                'google_review_count',
                'yelp_rating',
                'yelp_review_count',
                'has_award',
                'popularity_score',
                'cuisines',
            ]],
        ]);

        foreach (['description', 'address', 'photos', 'score_breakdown', 'ai_metadata', 'opening_hours', 'social_links', 'menu_url'] as $bloat) {
            $response->assertJsonMissingPath("popularRestaurants.0.{$bloat}");
        }

        $response->assertJsonPath('popularRestaurants.0.id', $r->id);
    }

    public function test_api_data_scopes_to_city(): void
    {
        $category = CuisineCategory::factory()->create(['name' => 'Scoped', 'slug' => 'scoped']);
        $cuisine = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'scoped-cuisine']);
        $r = Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
            'popularity_score' => 0.9,
        ]);
        $restaurant = Restaurant::whereKey($r->id)->firstOrFail();
        $restaurant->cuisines()->attach($cuisine);

        Restaurant::factory()->create([
            'city' => 'Dallas',
            'state' => 'TX',
            'is_active' => true,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
            'popularity_score' => 0.9,
        ]);

        // Real rows are stored as the 2-letter abbreviation, but a request
        // can arrive with the spelled-out name (IP/GPS/city-search
        // geolocation all return full state names) — must still match.
        $response = $this->getJson('/api/homepage-data?city=Austin&state=Texas');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson([
            'location' => ['city' => 'Austin', 'state' => 'TX'],
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
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
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
            'state' => 'FL',
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

    public function test_trending_falls_back_to_qualified_global_when_city_candidate_fails_the_floor(): void
    {
        // City candidate fails the quality floor (no photo) — must not surface
        // for the city tier, but a qualified global candidate exists.
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

        $response = $this->getJson('/api/homepage-data?city=Austin&state=Texas');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson([
            'location' => null,
            'popularRestaurants' => [['id' => $global->id]],
        ]);
    }

    public function test_trending_falls_back_to_unfiltered_global_when_nothing_meets_the_floor(): void
    {
        // Nothing anywhere meets the quality floor — must still return the
        // unfiltered corpus rather than an empty Trending section.
        $r = Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.1,
            'photo_url' => null,
        ]);

        $response = $this->getJson('/api/homepage-data?city=Austin&state=Texas');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson([
            'location' => null,
            'popularRestaurants' => [['id' => $r->id]],
        ]);
    }

    public function test_trending_quality_floor_kill_switch_reverts_to_legacy_behavior(): void
    {
        config(['restaurant-finder.trending.require_quality_floor' => false]);

        $r = Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.1,
            'photo_url' => null,
        ]);

        $response = $this->getJson('/api/homepage-data?city=Austin&state=Texas');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson([
            'location' => ['city' => 'Austin', 'state' => 'TX'],
            'popularRestaurants' => [['id' => $r->id]],
        ]);
    }

    public function test_trending_velocity_disabled_by_default_ignores_recent_engagement(): void
    {
        config(['restaurant-finder.trending.velocity_weight' => 0]);

        $stale = Restaurant::factory()->create([
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        $engaged = Restaurant::factory()->create([
            'is_active' => true,
            'popularity_score' => 0.5,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        DB::table('restaurant_engagement')->insert([
            'restaurant_id' => $engaged->id,
            'action_type' => 'website_click',
            'created_at' => now(),
        ]);

        // Default (weight 0) must keep the decayed-score order — the higher
        // scorer wins even though the other has fresh engagement.
        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJson([
            'popularRestaurants' => [
                ['id' => $stale->id],
                ['id' => $engaged->id],
            ],
        ]);
    }

    public function test_trending_velocity_ranks_recently_engaged_above_stale_higher_scorer(): void
    {
        config(['restaurant-finder.trending.velocity_weight' => 5.0]);
        config(['restaurant-finder.trending.velocity_window_days' => 14]);

        $stale = Restaurant::factory()->create([
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        $engaged = Restaurant::factory()->create([
            'is_active' => true,
            'popularity_score' => 0.5,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        DB::table('restaurant_engagement')->insert([
            'restaurant_id' => $engaged->id,
            'action_type' => 'website_click',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJson([
            'popularRestaurants' => [
                ['id' => $engaged->id],
                ['id' => $stale->id],
            ],
        ]);
    }

    public function test_trending_velocity_ignores_engagement_outside_the_window(): void
    {
        config(['restaurant-finder.trending.velocity_weight' => 5.0]);
        config(['restaurant-finder.trending.velocity_window_days' => 14]);

        $stale = Restaurant::factory()->create([
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        $engaged = Restaurant::factory()->create([
            'is_active' => true,
            'popularity_score' => 0.5,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);
        DB::table('restaurant_engagement')->insert([
            'restaurant_id' => $engaged->id,
            'action_type' => 'website_click',
            'created_at' => now()->subDays(30),
        ]);

        // Engagement older than the window must not count — the higher scorer
        // stays on top.
        $response = $this->getJson('/api/homepage-data');

        $response->assertStatus(200);
        $response->assertJson([
            'popularRestaurants' => [
                ['id' => $stale->id],
                ['id' => $engaged->id],
            ],
        ]);
    }

    public function test_api_data_matches_full_state_name_against_abbreviation_stored_rows(): void
    {
        // Regression test: real DB rows store the 2-letter abbreviation, but
        // IP/GPS/city-search geolocation all hand back the full state name —
        // a request for "Texas" must still find rows stored as "TX".
        $r = Restaurant::factory()->create([
            'city' => 'Austin',
            'state' => 'TX',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);

        $response = $this->getJson('/api/homepage-data?city=Austin&state=Texas');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson([
            'location' => ['city' => 'Austin', 'state' => 'TX'],
            'popularRestaurants' => [['id' => $r->id]],
        ]);
    }

    public function test_api_data_matches_city_case_insensitively(): void
    {
        // Stored casing is inconsistent ("Atlanta" vs "atlanta") and GPS/IP
        // geolocation can hand back either — a lowercase request must still
        // find the Title-Case row (Restaurant::scopeInCity's contract), and
        // location must echo the stored casing (it's rendered in a heading).
        $r = Restaurant::factory()->create([
            'city' => 'Atlanta',
            'state' => 'GA',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ]);

        $response = $this->getJson('/api/homepage-data?city=atlanta&state=ga');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'popularRestaurants');
        $response->assertJson([
            'location' => ['city' => 'Atlanta', 'state' => 'GA'],
            'popularRestaurants' => [['id' => $r->id]],
        ]);
    }

    public function test_city_scoped_categories_match_city_case_insensitively(): void
    {
        $category = CuisineCategory::factory()->create(['name' => 'Case', 'slug' => 'case']);
        $cuisine = Cuisine::factory()->create(['category_id' => $category->id, 'slug' => 'case-cuisine']);

        Restaurant::whereKey(Restaurant::factory()->create([
            'city' => 'Atlanta',
            'state' => 'GA',
            'is_active' => true,
            'popularity_score' => 0.9,
            'photo_url' => 'https://example.com/photo.jpg',
            'photo_source' => 'website',
        ])->id)->firstOrFail()->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/homepage-data?city=atlanta&state=ga');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'categories');
        $response->assertJsonPath('categories.0.cuisines.0.slug', 'case-cuisine');
    }
}
