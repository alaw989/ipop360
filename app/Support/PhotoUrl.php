<?php

namespace App\Support;

/**
 * Classifies photo URLs that are a platform's own static asset rather than a
 * picture of a venue.
 *
 * Instagram and Facebook serve their logo/sprite from a shared `/rsrc.php`
 * path (e.g. `https://static.cdninstagram.com/rsrc.php/v4/yD/r/R0fBIMurK8v.png`
 * — a 760 KB sprite). Around 3,800 prod rows stored that sprite as their
 * photo, rendered at 96–176 px. It is not a transient signed CDN URL; it is a
 * permanent logo, so it must be rejected at every write path and cleaned up
 * where it already exists (restaurants:photo-junk).
 */
class PhotoUrl
{
    /**
     * Whether the URL is one of Meta's static `rsrc.php` assets (Instagram or
     * Facebook logo/sprite), never a venue photo.
     */
    public static function isPlatformAsset(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($host === '' || ! str_contains($path, 'rsrc.php')) {
            return false;
        }

        return self::isMetaHost($host);
    }

    private static function isMetaHost(string $host): bool
    {
        foreach (['cdninstagram.com', 'instagram.com', 'facebook.com', 'fbcdn.net'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
