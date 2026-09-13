<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\FeaturedRestaurant;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\HomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The home page spotlight: an admin's pick (with an optional published story)
 * while it lasts, otherwise the top-ranked restaurant the visitor already
 * sees that has a photo and a rating.
 */
class FeaturedRestaurantTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  Collection<int, Restaurant>|null  $popular
     * @return array<string, mixed>
     */
    private function spotlight(?Collection $popular = null): array
    {
        $spotlight = app(HomeService::class)->featuredRestaurant($popular ?? new Collection);
        $this->assertNotNull($spotlight);

        return $spotlight;
    }

    /** @param  array<string, mixed>  $attributes */
    private function restaurant(array $attributes = []): Restaurant
    {
        return Restaurant::factory()->create($attributes + [
            'is_active' => true,
            'photo_url' => 'https://example.com/photo.jpg',
            'google_rating' => 4.7,
            'google_review_count' => 500,
        ]);
    }

    public function test_an_admin_features_a_restaurant_with_a_story(): void
    {
        $restaurant = $this->restaurant(['name' => 'Moose Pizza']);
        $story = BlogPost::factory()->create(['status' => 'published', 'published_at' => now()->subDay(), 'excerpt' => 'Pies worth the wait.']);

        $this->actingAs($this->admin())
            ->post('/admin/featured-restaurant', ['restaurant_id' => $restaurant->id, 'blog_post_id' => $story->id])
            ->assertRedirect();

        $spotlight = $this->spotlight();
        $this->assertSame('Moose Pizza', $spotlight['name']);
        $this->assertTrue($spotlight['picked']);
        $this->assertSame($story->slug, $spotlight['story']['slug']);
        $this->assertSame('Pies worth the wait.', $spotlight['quote']);
    }

    public function test_the_story_image_leads_and_the_restaurant_photo_is_the_fallback(): void
    {
        $restaurant = $this->restaurant(['photo_url' => 'https://example.com/restaurant.jpg']);
        $story = BlogPost::factory()->create(['status' => 'published', 'published_at' => now()->subDay(), 'featured_image' => 'https://example.com/story.jpg']);
        FeaturedRestaurant::create(['restaurant_id' => $restaurant->id, 'blog_post_id' => $story->id, 'starts_at' => now()->subMinute()]);

        $this->assertSame('https://example.com/story.jpg', $this->spotlight()['image']);

        $story->update(['featured_image' => null]);
        $this->assertSame('https://example.com/restaurant.jpg', $this->spotlight()['image']);
    }

    public function test_a_photo_picked_for_the_spotlight_leads_and_carries_its_credit(): void
    {
        $restaurant = $this->restaurant(['photo_url' => 'https://example.com/restaurant.jpg']);

        $this->actingAs($this->admin())->post('/admin/featured-restaurant', [
            'restaurant_id' => $restaurant->id,
            'image_url' => 'https://upload.wikimedia.org/photo.jpg',
            'image_credit' => 'Photo: A. Person, CC BY-SA 4.0, via Wikimedia Commons',
        ])->assertSessionHasNoErrors();

        $spotlight = $this->spotlight();
        $this->assertSame('https://upload.wikimedia.org/photo.jpg', $spotlight['image']);
        $this->assertSame('Photo: A. Person, CC BY-SA 4.0, via Wikimedia Commons', $spotlight['image_credit']);
    }

    public function test_a_picked_photo_needs_a_credit(): void
    {
        $restaurant = $this->restaurant();

        $this->actingAs($this->admin())
            ->post('/admin/featured-restaurant', ['restaurant_id' => $restaurant->id, 'image_url' => 'https://example.com/p.jpg'])
            ->assertSessionHasErrors('image_credit');
    }

    public function test_a_new_pick_ends_the_current_one(): void
    {
        $first = $this->restaurant();
        $second = $this->restaurant();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/featured-restaurant', ['restaurant_id' => $first->id]);
        $this->actingAs($admin)->post('/admin/featured-restaurant', ['restaurant_id' => $second->id]);

        $this->assertSame(1, FeaturedRestaurant::current()->count());
        $this->assertSame($second->id, FeaturedRestaurant::current()->first()?->restaurant_id);
        $this->assertNotNull(FeaturedRestaurant::where('restaurant_id', $first->id)->first()?->ends_at);
    }

    public function test_stopping_returns_the_spotlight_to_the_top_ranked_restaurant(): void
    {
        $picked = $this->restaurant(['name' => 'Picked Place']);
        $top = $this->restaurant(['name' => 'Top Place']);
        FeaturedRestaurant::create(['restaurant_id' => $picked->id, 'starts_at' => now()->subHour()]);

        $this->actingAs($this->admin())->delete('/admin/featured-restaurant')->assertRedirect();

        $spotlight = $this->spotlight(new Collection([$top]));
        $this->assertSame('Top Place', $spotlight['name']);
        $this->assertFalse($spotlight['picked']);
    }

    public function test_the_fallback_skips_restaurants_without_a_photo_or_a_rating(): void
    {
        $noPhoto = $this->restaurant(['photo_url' => null]);
        $noRating = $this->restaurant(['google_rating' => null]);
        $good = $this->restaurant(['name' => 'Has Both']);

        $spotlight = $this->spotlight(new Collection([$noPhoto, $noRating, $good]));

        $this->assertSame('Has Both', $spotlight['name']);
    }

    public function test_a_pick_whose_restaurant_closed_is_ignored(): void
    {
        $closed = $this->restaurant(['name' => 'Closed Place']);
        FeaturedRestaurant::create(['restaurant_id' => $closed->id, 'starts_at' => now()->subHour()]);
        $closed->update(['is_active' => false]);

        $this->assertNull(app(HomeService::class)->featuredRestaurant(new Collection));
    }

    public function test_an_expired_pick_is_not_current(): void
    {
        $restaurant = $this->restaurant();
        FeaturedRestaurant::create(['restaurant_id' => $restaurant->id, 'starts_at' => now()->subWeek(), 'ends_at' => now()->subDay()]);

        $this->assertSame(0, FeaturedRestaurant::current()->count());
    }

    public function test_a_draft_story_is_not_linked(): void
    {
        $restaurant = $this->restaurant();
        $draft = BlogPost::factory()->create(['status' => 'draft', 'published_at' => null]);
        FeaturedRestaurant::create(['restaurant_id' => $restaurant->id, 'blog_post_id' => $draft->id, 'starts_at' => now()->subMinute()]);

        $this->assertNull($this->spotlight()['story']);
    }

    public function test_only_published_stories_and_open_restaurants_can_be_picked(): void
    {
        $closed = $this->restaurant(['is_active' => false]);
        $draft = BlogPost::factory()->create(['status' => 'draft', 'published_at' => null]);

        $this->actingAs($this->admin())
            ->post('/admin/featured-restaurant', ['restaurant_id' => $closed->id, 'blog_post_id' => $draft->id])
            ->assertSessionHasErrors(['restaurant_id', 'blog_post_id']);
    }

    public function test_only_admins_can_pick(): void
    {
        $restaurant = $this->restaurant();

        $this->post('/admin/featured-restaurant', ['restaurant_id' => $restaurant->id])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())
            ->post('/admin/featured-restaurant', ['restaurant_id' => $restaurant->id])
            ->assertForbidden();
        $this->assertSame(0, FeaturedRestaurant::count());
    }

    public function test_search_finds_open_restaurants_by_name_most_reviewed_first(): void
    {
        $this->restaurant(['name' => "Moose's Tooth Pub", 'google_review_count' => 12000]);
        $this->restaurant(['name' => 'Tooth Fairy Diner', 'google_review_count' => 50]);
        $this->restaurant(['name' => 'Closed Tooth', 'is_active' => false]);

        $response = $this->actingAs($this->admin())->getJson('/admin/featured-restaurant/search?q=tooth');

        $response->assertOk()->assertJsonCount(2)->assertJsonPath('0.name', "Moose's Tooth Pub");
    }

    public function test_the_home_page_and_its_city_data_carry_the_spotlight(): void
    {
        $restaurant = $this->restaurant(['name' => 'Spotlit']);
        FeaturedRestaurant::create(['restaurant_id' => $restaurant->id, 'starts_at' => now()->subMinute()]);

        $this->get('/')->assertInertia(fn ($page) => $page->where('featuredRestaurant.name', 'Spotlit'));
        $this->getJson('/api/homepage-data')->assertJsonPath('featuredRestaurant.name', 'Spotlit');
    }

    public function test_the_dashboard_shows_the_current_pick_and_the_stories_to_link(): void
    {
        $restaurant = $this->restaurant(['name' => 'Spotlit']);
        BlogPost::factory()->create(['status' => 'published', 'published_at' => now()->subDay(), 'title' => 'A story']);
        FeaturedRestaurant::create(['restaurant_id' => $restaurant->id, 'starts_at' => now()->subMinute()]);

        $this->actingAs($this->admin())->get('/admin')->assertInertia(fn ($page) => $page
            ->where('featured.current.restaurant.name', 'Spotlit')
            ->where('featured.stories.0.title', 'A story'));
    }
}
