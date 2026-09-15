<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * spec-113: applies the `verified` gate only when a named config flag is true,
 * checked per-request. Baking the flag into route registration would freeze it
 * at `route:cache` time — a kill-switch you can't flip without a redeploy.
 *
 * Usage: `->middleware('verified.gate:auth.require_verified_for_favorites')`
 */
class VerifiedWhenConfigured
{
    public function handle(Request $request, Closure $next, string $configKey): Response
    {
        if (! config()->boolean($configKey)) {
            return $next($request);
        }

        $response = app(EnsureEmailIsVerified::class)->handle($request, $next);

        abort_if($response === null, 403);

        return $response;
    }
}
