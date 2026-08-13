<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_non_admin_is_denied_users_page(): void
    {
        $this->actingAs(User::factory()->user()->create())
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_editor_is_denied_users_page(): void
    {
        $this->actingAs(User::factory()->editor()->create())
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_users_page(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_admin_can_view_users_index(): void
    {
        $admin = $this->admin();
        $editor = User::factory()->editor()->create(['email' => 'editor@example.com']);

        $response = $this->actingAs($admin)->get('/admin/users')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data', 2)
            ->where('users.data.0.id', $editor->id)
            ->where('users.data.0.email', 'editor@example.com')
            ->where('users.data.0.role', 'editor')
            ->where('roles', ['admin', 'editor', 'user']));
    }

    public function test_admin_can_filter_users_by_role(): void
    {
        User::factory()->editor()->create(['email' => 'editor@example.com']);
        User::factory()->user()->create(['email' => 'user@example.com']);

        $response = $this->actingAs($this->admin())
            ->get('/admin/users?role=editor')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data', 1)
            ->where('users.data.0.email', 'editor@example.com'));
    }

    public function test_admin_can_update_user_role(): void
    {
        $target = User::factory()->user()->create(['email' => 'target@example.com']);

        $this->actingAs($this->admin())
            ->patch("/admin/users/{$target->id}", ['role' => 'editor'])
            ->assertRedirect();

        $this->assertSame('editor', $target->fresh()?->role);
        $this->assertSame('Role for target@example.com updated to editor.', session('success'));
    }

    public function test_update_rejects_invalid_role(): void
    {
        $target = User::factory()->user()->create();

        $this->actingAs($this->admin())
            ->patch("/admin/users/{$target->id}", ['role' => 'superadmin'])
            ->assertSessionHasErrors('role');

        $this->assertSame('user', $target->fresh()?->role);
    }

    public function test_update_rejects_missing_role(): void
    {
        $target = User::factory()->user()->create();

        $this->actingAs($this->admin())
            ->patch("/admin/users/{$target->id}", [])
            ->assertSessionHasErrors('role');
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create(['email' => 'other@example.com']);

        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->id}", ['role' => 'editor'])
            ->assertSessionHasErrors(['role' => 'You cannot change your own role.']);

        $this->assertSame('admin', $admin->fresh()?->role);
    }

    public function test_last_admin_cannot_be_removed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->id}", ['role' => 'editor'])
            ->assertSessionHasErrors(['role' => 'You cannot remove the last admin.']);

        $this->assertSame('admin', $admin->fresh()?->role);
    }

    public function test_admin_can_demote_another_admin(): void
    {
        $admin = $this->admin();
        $other = User::factory()->admin()->create(['email' => 'other@example.com']);

        $this->actingAs($admin)
            ->patch("/admin/users/{$other->id}", ['role' => 'editor'])
            ->assertRedirect();

        $this->assertSame('editor', $other->fresh()?->role);
    }
}
