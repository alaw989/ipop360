<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserRoleService;
use Illuminate\Console\Command;

class UserRoleCommand extends Command
{
    protected $signature = 'user:role
                            {email : The email address of the user}
                            {role  : The role to assign (admin|editor|user)}';

    protected $description = 'Assign an admin, editor, or user role to a user by email';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->argument('role');

        $userRole = UserRole::tryFrom($role);

        if ($userRole === null) {
            $valid = implode('|', UserRoleService::validValues());
            $this->error("Invalid role: \"{$role}\". Valid roles: {$valid}");

            return Command::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("User not found with email: {$email}");

            return Command::FAILURE;
        }

        if (UserRoleService::wouldRemoveLastAdmin($user, $userRole)) {
            $this->error("Cannot remove the last admin: {$user->email} is the only remaining administrator.");

            return Command::FAILURE;
        }

        $oldRole = $user->role;
        $user->role = $userRole->value;
        $user->save();

        $this->info("User {$user->email} assigned role: {$userRole->value}".($oldRole !== $userRole->value ? " (was: {$oldRole})" : ''));

        return Command::SUCCESS;
    }
}
