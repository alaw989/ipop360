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
        return User::factory()->admin()->create();
    }

    private function editor(): User
    {
        return User::factory()->editor()->create();
    }

    public function test_non_admin_is_denied_admin_dashboard(): void
    {
        $user = User::factory()->user()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_non_admin_is_denied_blog_admin_pages(): void
    {
        $user = User::factory()->user()->create();

        $this->actingAs($user)->get('/admin/blog')->assertForbidden();
        $this->actingAs($user)->get('/admin/blog/create')->assertForbidden();
    }

    public function test_editor_is_denied_admin_dashboard(): void
    {
        $this->actingAs($this->editor())->get('/admin')->assertForbidden();
    }

    public function test_editor_is_allowed_blog_admin_pages(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get('/admin/blog')->assertOk();
        $this->actingAs($editor)->get('/admin/blog/create')->assertOk();
    }

    public function test_editor_can_create_own_post(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post('/admin/blog', [
            'title' => 'Editor Draft',
            'excerpt' => 'A draft by an editor.',
            'body' => '<p>Body</p>',
            'status' => 'draft',
        ])->assertRedirect(route('admin.blog.index'));

        $this->assertDatabaseHas('blog_posts', [
            'title' => 'Editor Draft',
            'author_id' => $editor->id,
        ]);
    }

    public function test_editor_index_shows_only_own_posts(): void
    {
        $editor = $this->editor();
        $other = $this->admin();
        BlogPost::factory()->create(['author_id' => $other->id, 'title' => 'Admin Post']);
        BlogPost::factory()->create(['author_id' => $editor->id, 'title' => 'Editor Post']);

        $response = $this->actingAs($editor)->get('/admin/blog')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Blog/Index')
            ->has('posts.data', 1)
            ->where('posts.data.0.title', 'Editor Post'));
    }

    public function test_editor_can_open_edit_page_for_own_post(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->create(['author_id' => $editor->id]);

        $this->actingAs($editor)->get("/admin/blog/{$post->id}/edit")->assertOk();
    }

    public function test_editor_can_update_own_post(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->create(['author_id' => $editor->id, 'title' => 'Original']);

        $this->actingAs($editor)->put("/admin/blog/{$post->id}", [
            'title' => 'Updated by Editor',
            'excerpt' => 'New excerpt.',
            'body' => '<p>New body</p>',
            'status' => 'draft',
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame('Updated by Editor', $post->title);
    }

    public function test_editor_can_publish_own_draft(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->draft()->create(['author_id' => $editor->id]);

        $this->actingAs($editor)->put("/admin/blog/{$post->id}", [
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'status' => 'published',
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_editor_can_delete_own_post(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->create(['author_id' => $editor->id]);

        $this->actingAs($editor)->delete("/admin/blog/{$post->id}")->assertRedirect();

        $this->assertDatabaseMissing('blog_posts', ['id' => $post->id]);
    }

    public function test_editor_is_denied_editing_another_users_post(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->create(['author_id' => $this->admin()->id]);

        $this->actingAs($editor)->get("/admin/blog/{$post->id}/edit")->assertForbidden();
    }

    public function test_editor_is_denied_updating_another_users_post(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->create(['author_id' => $this->admin()->id]);

        $this->actingAs($editor)->put("/admin/blog/{$post->id}", [
            'title' => 'Hijack',
            'excerpt' => 'Hijack excerpt.',
            'body' => '<p>Hijack</p>',
            'status' => 'published',
        ])->assertForbidden();
    }

    public function test_editor_is_denied_deleting_another_users_post(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->create(['author_id' => $this->admin()->id]);

        $this->actingAs($editor)->delete("/admin/blog/{$post->id}")->assertForbidden();

        $this->assertDatabaseHas('blog_posts', ['id' => $post->id]);
    }

    public function test_admin_can_edit_and_delete_any_users_post(): void
    {
        $admin = $this->admin();
        $post = BlogPost::factory()->create(['author_id' => $this->editor()->id]);

        $this->actingAs($admin)->get("/admin/blog/{$post->id}/edit")->assertOk();
        $this->actingAs($admin)->delete("/admin/blog/{$post->id}")->assertRedirect();
        $this->assertDatabaseMissing('blog_posts', ['id' => $post->id]);
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

    public function test_category_can_be_set_on_create(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/blog', [
            'title' => 'Categorized Post',
            'excerpt' => 'With category.',
            'body' => '<p>Body</p>',
            'category' => 'News',
            'status' => 'draft',
        ])->assertRedirect(route('admin.blog.index'));

        $this->assertDatabaseHas('blog_posts', [
            'title' => 'Categorized Post',
            'category' => 'News',
        ]);
    }

    public function test_category_is_nullable_on_create(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/blog', [
            'title' => 'Uncategorized Post',
            'excerpt' => 'No category.',
            'body' => '<p>Body</p>',
            'status' => 'draft',
        ])->assertRedirect(route('admin.blog.index'));

        $post = BlogPost::where('title', 'Uncategorized Post')->first();
        $this->assertNotNull($post);
        $this->assertNull($post->category);
    }

    public function test_category_can_be_updated(): void
    {
        $admin = $this->admin();
        $post = BlogPost::factory()->create([
            'author_id' => $admin->id,
            'category' => 'News',
        ]);

        $this->actingAs($admin)->put("/admin/blog/{$post->id}", [
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'category' => 'Guide',
            'status' => $post->status,
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame('Guide', $post->category);
    }

    public function test_category_validation_enforces_max_length(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/blog', [
            'title' => 'Too Long Category',
            'excerpt' => 'Testing validation.',
            'body' => '<p>Body</p>',
            'category' => str_repeat('x', 101),
            'status' => 'draft',
        ])->assertSessionHasErrors(['category']);
    }

    public function test_admin_can_delete_post(): void
    {
        $admin = $this->admin();
        $post = BlogPost::factory()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)->delete("/admin/blog/{$post->id}")->assertRedirect();

        $this->assertDatabaseMissing('blog_posts', ['id' => $post->id]);
    }
}
