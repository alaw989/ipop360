<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Whether a photo URL actually loads. Shared by every path that stores a
 * photo_url from a source that can be stale: the photo verify sweep and the
 * cached-search backfill, whose SerpApi thumbnails can be a month old and
 * Google's gps-cs-s URLs expire within weeks.
 */
final class PhotoLiveness
{
    /** Timeout per request (seconds). */
    private const TIMEOUT = 8;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; iPop360-Verify/1.0)';

    /**
     * HEAD-check the URL, falling back to GET. A 403 gets the GET fallback
     * too: Google lh3 and other CDNs return a transient 403 that recovers on
     * a second hit, so a single 403 must never churn a valid row.
     */
    public static function isAlive(string $url): bool
    {
        return self::succeeds($url, 'HEAD') || self::succeeds($url, 'GET');
    }

    private static function succeeds(string $url, string $method): bool
    {
        try {
            $request = Http::timeout(self::TIMEOUT)
                ->withUserAgent(self::USER_AGENT)
                ->withOptions(['allow_redirects' => ['max' => 3]]);

            $response = $method === 'HEAD' ? $request->head($url) : $request->get($url);

            return $response->status() === 200;
        } catch (\Throwable) {
            return false;
        }
    }
}
