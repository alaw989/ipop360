<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admin_is_denied_admin_dashboard(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_non_admin_is_denied_blog_admin_pages(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/blog')->assertForbidden();
        $this->actingAs($user)->get('/admin/blog/create')->assertForbidden();
    }

    public function test_guest_is_redirected_from_admin(): void
    {
        $this->get('/admin/blog')->assertRedirect('/login');
    }

    public function test_admin_can_create_published_post(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/blog', [
            'title' => 'My First Post',
            'excerpt' => 'A short excerpt.',
            'body' => '<p>Hello world</p>',
            'status' => 'published',
        ])->assertRedirect(route('admin.blog.index'));

        $this->assertDatabaseHas('blog_posts', [
            'title' => 'My First Post',
            'status' => 'published',
            'author_id' => $admin->id,
        ]);

        $post = BlogPost::where('title', 'My First Post')->first();
        $this->assertNotNull($post);
        $this->assertNotNull($post->published_at);
        $this->assertSame('my-first-post', $post->slug);
        $this->assertSame('Blog post created.', session('success'));
    }

    public function test_admin_can_create_draft(): void
    {
        $this->actingAs($this->admin())->post('/admin/blog', [
            'title' => 'Draft Post',
            'excerpt' => 'In progress.',
            'body' => '<p>Draft body</p>',
            'status' => 'draft',
        ])->assertRedirect(route('admin.blog.index'));

        $post = BlogPost::where('title', 'Draft Post')->first();
        $this->assertNotNull($post);
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->published_at);
        $this->assertSame('Blog post created.', session('success'));
    }

    public function test_validation_requires_fields(): void
    {
        $this->actingAs($this->admin())->post('/admin/blog', [
            'title' => '',
            'excerpt' => '',
            'body' => '',
            'status' => 'invalid',
        ])->assertSessionHasErrors(['title', 'excerpt', 'body', 'status']);
    }

    public function test_admin_can_update_post(): void
    {
        $admin = $this->admin();
        $post = BlogPost::factory()->draft()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)->put("/admin/blog/{$post->id}", [
            'title' => 'Updated Title',
            'excerpt' => 'Updated excerpt.',
            'body' => '<p>Updated body</p>',
            'status' => 'published',
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame('Updated Title', $post->title);
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_editing_published_post_preserves_publish_date(): void
    {
        $admin = $this->admin();
        $post = BlogPost::factory()->create([
            'author_id' => $admin->id,
            'status' => 'published',
            'published_at' => now()->subWeek(),
        ]);

        $this->actingAs($admin)->put("/admin/blog/{$post->id}", [
            'title' => 'Edited After Publish',
            'excerpt' => 'Still excerpted.',
            'body' => '<p>Still body</p>',
            'status' => 'published',
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertTrue($post->published_at->isLastWeek());
    }

    public function test_body_is_sanitized_on_save(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/blog', [
            'title' => 'Sanitized Post',
            'excerpt' => 'An excerpt.',
            'body' => '<p>Hello <script>alert(1)</script><img src="https://evil.test/x.png" onerror="alert(2)"></p>',
            'status' => 'draft',
        ])->assertRedirect(route('admin.blog.index'));

        $post = BlogPost::where('title', 'Sanitized Post')->first();
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('<script>', $post->body);
        $this->assertStringNotContainsString('onerror', $post->body);
        $this->assertStringContainsString('<p>Hello', $post->body);
    }

    public function test_admin_can_delete_post(): void
    {
        $admin = $this->admin();
        $post = BlogPost::factory()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)->delete("/admin/blog/{$post->id}")->assertRedirect();

        $this->assertDatabaseMissing('blog_posts', ['id' => $post->id]);
    }
}
