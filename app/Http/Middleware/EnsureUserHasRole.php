<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $allowed = array_map(fn (string $role): ?string => UserRole::tryFrom($role)?->value, $roles);

        if ($user === null || ! in_array($user->role, $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
