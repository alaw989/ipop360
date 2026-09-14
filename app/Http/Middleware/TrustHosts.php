<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * spec-103: reject requests whose effective Host is not allow-listed.
 *
 * `next_page_url` and the Inertia/Ziggy `location` prop are built from the
 * request's scheme+host. Behind a misconfigured proxy or a cache-poisoning
 * front, an attacker-controlled Host / X-Forwarded-Host can therefore be
 * reflected into URLs the frontend follows. This runs globally (prepended) so
 * an untrusted host is rejected before any controller builds a URL from it.
 *
 * The allow-list is `config('app.trusted_hosts')` (from TRUSTED_HOSTS, else
 * derived from APP_URL + localhost). An empty list is a deliberate no-op so an
 * unconfigured app can never lock out all traffic.
 */
class TrustHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        $trusted = config('app.trusted_hosts', []);

        if (is_array($trusted) && $trusted !== []) {
            $allowed = [];
            foreach ($trusted as $host) {
                if (is_string($host) && $host !== '') {
                    $allowed[] = strtolower($host);
                }
            }

            if (! in_array(strtolower($request->getHost()), $allowed, true)) {
                abort(400, 'Untrusted host.');
            }
        }

        return $next($request);
    }
}
