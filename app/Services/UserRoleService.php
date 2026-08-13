<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Shared user-role guard logic used by both the admin UI and the `user:role`
 * CLI command, so the "last admin" rule lives in exactly one place.
 */
class UserRoleService
{
    /**
     * The list of assignable role values (e.g. `['admin', 'editor', 'user']`).
     *
     * @return array<int, string>
     */
    public static function validValues(): array
    {
        return array_map(fn (UserRole $role) => $role->value, UserRole::cases());
    }

    /**
     * Determine whether assigning the given role would remove the last
     * remaining administrator (i.e. demote the only admin to a non-admin role).
     */
    public static function wouldRemoveLastAdmin(User $user, UserRole $role): bool
    {
        return $user->role === UserRole::Admin->value
            && $role !== UserRole::Admin
            && User::query()->where('role', UserRole::Admin->value)->count() <= 1;
    }
}
