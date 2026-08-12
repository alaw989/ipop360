<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class UserRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_admin_role_to_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'role' => 'user']);

        /** @var PendingCommand $command */
        $command = $this->artisan('user:role', [
            'email' => 'test@example.com',
            'role' => 'admin',
        ]);
        $command->assertExitCode(0)
            ->expectsOutputToContain("User {$user->email} assigned role: admin");
        $command->run();

        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_assigns_editor_role_to_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'editor@example.com', 'role' => 'user']);

        /** @var PendingCommand $command */
        $command = $this->artisan('user:role', [
            'email' => 'editor@example.com',
            'role' => 'editor',
        ]);
        $command->assertExitCode(0)
            ->expectsOutputToContain("User {$user->email} assigned role: editor");
        $command->run();

        $this->assertSame('editor', $user->fresh()->role);
    }

    public function test_assigns_user_role_to_existing_user(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@example.com']);

        /** @var PendingCommand $command */
        $command = $this->artisan('user:role', [
            'email' => 'admin@example.com',
            'role' => 'user',
        ]);
        $command->assertExitCode(0)
            ->expectsOutputToContain("User {$user->email} assigned role: user");
        $command->run();

        $this->assertSame('user', $user->fresh()->role);
    }

    public function test_rejects_invalid_role(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        /** @var PendingCommand $command */
        $command = $this->artisan('user:role', [
            'email' => 'test@example.com',
            'role' => 'superadmin',
        ]);
        $command->assertExitCode(1)
            ->expectsOutputToContain('Invalid role');
        $command->run();
    }

    public function test_rejects_nonexistent_email(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('user:role', [
            'email' => 'nobody@example.com',
            'role' => 'admin',
        ]);
        $command->assertExitCode(1)
            ->expectsOutputToContain('User not found');
        $command->run();
    }

    public function test_reassigns_role_when_user_already_has_different_role(): void
    {
        $user = User::factory()->admin()->create(['email' => 'multi@example.com']);

        /** @var PendingCommand $command */
        $command = $this->artisan('user:role', [
            'email' => 'multi@example.com',
            'role' => 'editor',
        ]);
        $command->assertExitCode(0)
            ->expectsOutputToContain("User {$user->email} assigned role: editor");
        $command->run();

        $this->assertSame('editor', $user->fresh()->role);

        // Reassign again to user
        /** @var PendingCommand $command2 */
        $command2 = $this->artisan('user:role', [
            'email' => 'multi@example.com',
            'role' => 'user',
        ]);
        $command2->assertExitCode(0)
            ->expectsOutputToContain("User {$user->email} assigned role: user");
        $command2->run();

        $this->assertSame('user', $user->fresh()->role);
    }
}
