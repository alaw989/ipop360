<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * spec-115: tags pages that can never rank with a server-side noindex flag.
 *
 * Emitting the directive from the server (not JS) matters: crawlers that
 * don't execute JavaScript would otherwise index /search's parameter space
 * and the auth pages. The flag is also shared as an Inertia prop
 * (HandleInertiaRequests) so client-side SEO logic agrees with the markup.
 *
 * Paths live in config('restaurant-finder.seo.noindex_paths') and are the
 * same list ServeRobots turns into robots.txt Disallow lines.
 */
class SeoRobots
{
    /**
     * Request attribute read by the Blade root view (app.blade.php) and
     * HandleInertiaRequests.
     */
    public const ATTRIBUTE = 'seo.noindex';

    /**
     * Whether the given request was tagged noindex by this middleware. Used
     * by HandleInertiaRequests (same namespace) to avoid duplicating the
     * attribute name.
     */
    public static function isNoindex(Request $request): bool
    {
        return (bool) $request->attributes->get(self::ATTRIBUTE, false);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, $this->shouldNoindex($request));

        return $next($request);
    }

    private function shouldNoindex(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ((array) config('restaurant-finder.seo.noindex_paths', []) as $noindexPath) {
            $noindexPath = trim((string) $noindexPath, '/');

            if ($noindexPath !== '' && ($path === $noindexPath || str_starts_with($path, $noindexPath.'/'))) {
                return true;
            }
        }

        return false;
    }
}
