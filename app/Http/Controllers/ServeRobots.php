<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * spec-115: robots.txt served from config instead of a static file, so the
 * sitemap URL can never point at a hardcoded production domain (the old
 * public/robots.txt did) and the Disallow list stays in lockstep with the
 * server-side noindex paths.
 */
class ServeRobots extends Controller
{
    public function __invoke(): Response
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $lines = [
            'User-agent: *',
            'Disallow:',
        ];

        foreach ((array) config('restaurant-finder.seo.noindex_paths', []) as $path) {
            $path = trim((string) $path, '/');

            if ($path !== '') {
                $lines[] = 'Disallow: /'.$path;
            }
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.$baseUrl.'/sitemap.xml';

        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
