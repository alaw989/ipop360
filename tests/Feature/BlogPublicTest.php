<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_published_posts(): void
    {
        $published = BlogPost::factory()->create();
        BlogPost::factory()->draft()->create(['title' => 'Hidden Draft']);

        $this->get('/blog')
            ->assertOk()
            ->assertSee($published->title)
            ->assertDontSee('Hidden Draft');
    }

    public function test_show_renders_published_post(): void
    {
        $post = BlogPost::factory()->create(['title' => 'Visible Article']);

        $this->get("/blog/{$post->slug}")
            ->assertOk()
            ->assertSee('Visible Article')
            ->assertSee($post->excerpt);
    }

    public function test_show_returns_404_for_draft(): void
    {
        $post = BlogPost::factory()->draft()->create();

        $this->get("/blog/{$post->slug}")->assertNotFound();
    }

    public function test_show_returns_404_for_scheduled_future_post(): void
    {
        $post = BlogPost::factory()->create([
            'status' => 'published',
            'published_at' => now()->addDay(),
        ]);

        $this->get("/blog/{$post->slug}")->assertNotFound();
    }

    public function test_homepage_includes_latest_posts(): void
    {
        Restaurant::factory()->count(3)->create();

        $old = BlogPost::factory()->create(['title' => 'Older Post']);
        $newer = BlogPost::factory()->create(['title' => 'Newer Post']);
        BlogPost::factory()->draft()->create(['title' => 'Not On Homepage']);

        $this->get('/')
            ->assertOk()
            ->assertSee($newer->title)
            ->assertSee($old->title)
            ->assertDontSee('Not On Homepage');
    }

    public function test_index_can_filter_by_category(): void
    {
        BlogPost::factory()->create(['title' => 'Tech Article', 'category' => 'tech']);
        BlogPost::factory()->create(['title' => 'Food Article', 'category' => 'food']);

        $this->get('/blog?category=tech')
            ->assertOk()
            ->assertSee('Tech Article')
            ->assertDontSee('Food Article');
    }

    public function test_index_ignores_case_in_category_filter(): void
    {
        BlogPost::factory()->create(['title' => 'Case Sensitive', 'category' => 'MyCategory']);

        $this->get('/blog?category=mycategory')
            ->assertOk()
            ->assertSee('Case Sensitive');
    }

    public function test_index_passes_distinct_categories_to_view(): void
    {
        BlogPost::factory()->create(['category' => 'alpha']);
        BlogPost::factory()->create(['category' => 'beta']);
        BlogPost::factory()->create(['category' => 'alpha']); // duplicate

        $response = $this->get('/blog')->assertOk();
        $page = $response->inertiaProps();

        $this->assertIsArray($page['categories']);
        $this->assertCount(2, $page['categories']);
        $this->assertContains('alpha', $page['categories']);
        $this->assertContains('beta', $page['categories']);
    }

    public function test_index_excludes_null_categories_from_list(): void
    {
        BlogPost::factory()->create(['category' => 'only']);
        BlogPost::factory()->create(['category' => null]);

        $response = $this->get('/blog')->assertOk();
        $page = $response->inertiaProps();

        $this->assertCount(1, $page['categories']);
        $this->assertSame('only', $page['categories'][0]);
    }

    public function test_index_can_search_by_title(): void
    {
        BlogPost::factory()->create(['title' => 'The Best Pizza in Town', 'excerpt' => 'Something else']);
        BlogPost::factory()->create(['title' => 'Sushi Guide 2025', 'excerpt' => 'Another topic']);

        $this->get('/blog?search=pizza')
            ->assertOk()
            ->assertSee('The Best Pizza in Town')
            ->assertDontSee('Sushi Guide 2025');
    }

    public function test_index_can_search_by_excerpt(): void
    {
        BlogPost::factory()->create(['title' => 'Title One', 'excerpt' => 'Learn about tacos here']);
        BlogPost::factory()->create(['title' => 'Title Two', 'excerpt' => 'Burger reviews']);

        $this->get('/blog?search=tacos')
            ->assertOk()
            ->assertSee('Title One')
            ->assertDontSee('Title Two');
    }

    public function test_index_combines_search_and_category_filters(): void
    {
        BlogPost::factory()->create(['title' => 'Ramen Shop', 'category' => 'reviews', 'excerpt' => 'Best ramen']);
        BlogPost::factory()->create(['title' => 'Ramen Recipe', 'category' => 'recipes', 'excerpt' => 'Make ramen']);
        BlogPost::factory()->create(['title' => 'Sushi Bar', 'category' => 'reviews', 'excerpt' => 'Great sushi']);

        $this->get('/blog?search=ramen&category=reviews')
            ->assertOk()
            ->assertSee('Ramen Shop')
            ->assertDontSee('Ramen Recipe')
            ->assertDontSee('Sushi Bar');
    }

    public function test_search_query_passed_to_view(): void
    {
        BlogPost::factory()->create(['title' => 'Any Post']);

        $response = $this->get('/blog?search=pizza')->assertOk();
        $page = $response->inertiaProps();

        $this->assertSame('pizza', $page['filters']['search']);
    }

    public function test_homepage_prioritizes_featured_posts(): void
    {
        Restaurant::factory()->count(3)->create();

        $regular = BlogPost::factory()->create(['title' => 'Regular Post', 'is_featured' => false, 'published_at' => now()->subDay()]);
        $newerRegular = BlogPost::factory()->create(['title' => 'Newer Regular', 'is_featured' => false, 'published_at' => now()]);
        $featured = BlogPost::factory()->create(['title' => 'Featured Post', 'is_featured' => true, 'published_at' => now()->subDays(2)]);

        $response = $this->get('/')->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);

        $featuredPos = strpos($html, 'Featured Post');
        $newerPos = strpos($html, 'Newer Regular');
        $regularPos = strpos($html, 'Regular Post');

        $this->assertNotFalse($featuredPos, 'Featured post should be visible');
        $this->assertNotFalse($newerPos, 'Newer regular should be visible');
        $this->assertNotFalse($regularPos, 'Regular post should be visible');

        $this->assertLessThan($newerPos, $featuredPos, 'Featured post should appear before newer regular post');
        $this->assertLessThan($regularPos, $featuredPos, 'Featured post should appear before older regular post');
    }
}
