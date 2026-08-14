<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserRoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->when($request->query('role'), fn ($query, $role) => $query->where('role', $role))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filter' => $request->query('role'),
            'roles' => UserRoleService::validValues(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $targetRole = UserRole::from($data['role']);

        if (UserRoleService::wouldRemoveLastAdmin($user, $targetRole)) {
            throw ValidationException::withMessages([
                'role' => 'You cannot remove the last admin.',
            ]);
        }

        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role.',
            ]);
        }

        $user->role = $data['role'];
        $user->save();

        return back()->with('success', "Role for {$user->email} updated to {$data['role']}.");
    }
}
