<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    /**
     * Handle an incoming request.
     *
     * Logs API requests with an is_live tag indicating whether the request
     * used the live search path (external APIs) vs. the database path.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log JSON API responses
        if ($response->headers->get('content-type') === 'application/json') {
            Log::info('API Request', [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'is_live' => $request->attributes->get('is_live', false),
                'query_params' => $request->query->all(),
            ]);
        }

        return $response;
    }
}
