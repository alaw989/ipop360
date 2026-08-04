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
}
