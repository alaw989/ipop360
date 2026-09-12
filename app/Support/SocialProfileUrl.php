<?php

namespace App\Support;

/**
 * Canonicalizes a candidate social-media URL into a real profile/page URL, or
 * rejects it.
 *
 * The old scraper regex-matched raw HTML and kept whatever it hit first, which
 * stored non-profiles as "social links" at scale: the `xmlns:fb` namespace URI
 * `facebook.com/2008/fbml` (1,929 prod rows), the Meta Pixel endpoint
 * `facebook.com/tr` (1,048), id-less `profile.php` (467), share/intent
 * endpoints, post permalinks, template placeholders, and the site builder's
 * own accounts linked from page footers. Those all answer HTTP 200, so the
 * reachability check "verified" them and they fed social_links_count.
 *
 * Only these shapes are accepted (canonical form on the right):
 *   facebook  /{handle}, /profile.php?id={digits}, /pages/{name}/{id} → https://www.facebook.com/…
 *   instagram /{handle}                                               → https://www.instagram.com/{handle}
 *   twitter   /{handle} on twitter.com or x.com                        → https://{host}/{handle}
 *   tiktok    /@{handle}                                              → https://www.tiktok.com/@{handle}
 *   youtube   /@{handle}, /channel/{id}, /c/{name}, /user/{name}       → https://www.youtube.com/…
 */
class SocialProfileUrl
{
    private const HOST_PLATFORMS = [
        'facebook.com' => 'facebook',
        'fb.com' => 'facebook',
        'instagram.com' => 'instagram',
        'instagr.am' => 'instagram',
        'twitter.com' => 'twitter',
        'x.com' => 'twitter',
        'tiktok.com' => 'tiktok',
        'youtube.com' => 'youtube',
    ];

    /** First path segments that are platform endpoints, never a venue's page. */
    private const RESERVED = [
        'facebook' => [
            'tr', 'plugins', 'dialog', 'sharer', 'sharer.php', 'share', 'share.php', 'login', 'login.php',
            'help', 'policies', 'policy.php', 'privacy', 'legal', 'terms', 'business', 'ads', 'groups',
            'events', 'watch', 'marketplace', 'gaming', 'hashtag', 'search', 'photo.php', 'photo',
            'permalink.php', 'story.php', 'home.php', 'people', 'l.php', 'recover', 'settings',
            'notifications', 'messages', 'reel', 'reels', 'stories', 'video.php', 'media', 'careers',
            'places', 'fbml', 'badges', 'directory', 'pg', 'profile', 'about', 'intern', 'offsite_event',
        ],
        'instagram' => [
            'p', 'reel', 'reels', 'tv', 'explore', 'accounts', 'stories', 'direct', 'about', 'legal',
            'developer', 'web', 'emails', 'challenge', 'oauth', 's', 'ar', 'static', 'api', 'graphql', 'embed.js',
        ],
        'twitter' => [
            'intent', 'share', 'i', 'home', 'search', 'hashtag', 'login', 'signup', 'widgets', 'widgets.js',
            'privacy', 'tos', 'en', 'settings', 'explore', 'notifications', 'messages', 'compose', 'oauth',
            'account', 'download', 'following', 'followers',
        ],
    ];

    /**
     * Handles that are placeholders or someone else's account (the platforms
     * themselves, website builders, ordering/aggregator vendors) — linked from
     * templates and footers, never the venue's own profile.
     */
    private const FOREIGN_HANDLES = [
        'facebook', 'instagram', 'twitter', 'x', 'tiktok', 'youtube', 'meta', 'google', 'googlemaps',
        'apple', 'wix', 'wixcom', 'squarespace', 'godaddy', 'wordpress', 'wordpressdotcom', 'weebly',
        'shopify', 'webflow', 'jimdo', 'mailchimp', 'hubspot', 'toasttab', 'toast', 'doordash', 'ubereats',
        'grubhub', 'opentable', 'yelp', 'tripadvisor', 'bentobox', 'popmenu', 'spothopper', 'chownow',
        'getownerapp', 'owner', 'menufy', 'slicelife', 'sliceapp', 'beyondmenu', 'resy', 'sevenrooms',
        'yourpage', 'yourname', 'yourusername', 'username', 'yourhandle', 'handle', 'example', 'company',
        'page', 'account', 'user', 'profile', 'yourcompany', 'yourbusiness', 'business', 'pages',
    ];

    /**
     * @return array{platform: string, url: string}|null
     */
    public static function canonicalize(string $url): ?array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $host = (string) preg_replace('/^(www|m|mobile|web|touch|business)\./', '', $host);
        $platform = self::HOST_PLATFORMS[$host] ?? null;
        if ($platform === null) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', rawurldecode((string) ($parts['path'] ?? ''))),
            fn (string $s) => $s !== ''
        ));
        if ($segments === []) {
            return null;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return match ($platform) {
            'facebook' => self::facebook($segments, $query),
            'instagram' => self::instagram($segments),
            'twitter' => self::twitter($segments, $host),
            'tiktok' => self::tiktok($segments),
            'youtube' => self::youtube($segments),
        };
    }

    /**
     * @param  list<string>  $segments
     * @param  array<mixed>  $query
     * @return array{platform: string, url: string}|null
     */
    private static function facebook(array $segments, array $query): ?array
    {
        $first = strtolower($segments[0]);

        if ($first === 'profile.php') {
            $id = is_string($query['id'] ?? null) ? $query['id'] : '';

            return preg_match('/^\d{6,}$/', $id) === 1
                ? ['platform' => 'facebook', 'url' => 'https://www.facebook.com/profile.php?id='.$id]
                : null;
        }

        if ($first === 'pg' && isset($segments[1])) {
            // Legacy page-tab URLs: /pg/{handle}/about, /pg/{handle}/photos …
            $segments = [$segments[1]];
            $first = strtolower($segments[0]);
        }

        if ($first === 'pages') {
            $name = $segments[1] ?? '';
            $id = $segments[2] ?? '';

            return $name !== '' && preg_match('/^\d{6,}$/', $id) === 1
                ? ['platform' => 'facebook', 'url' => 'https://www.facebook.com/pages/'.$name.'/'.$id]
                : null;
        }

        if (in_array($first, self::RESERVED['facebook'], true)) {
            return null;
        }

        $handle = $segments[0];
        if (ctype_digit($handle)) {
            // Numeric page ids are long (15+ digits); short numbers are
            // namespace years ("2008/fbml") or API versions.
            return strlen($handle) >= 6 ? ['platform' => 'facebook', 'url' => 'https://www.facebook.com/'.$handle] : null;
        }

        if (preg_match('/^[A-Za-z0-9.\-]{3,80}$/', $handle) !== 1 || self::isForeign($handle)) {
            return null;
        }

        return ['platform' => 'facebook', 'url' => 'https://www.facebook.com/'.$handle];
    }

    /**
     * @param  list<string>  $segments
     * @return array{platform: string, url: string}|null
     */
    private static function instagram(array $segments): ?array
    {
        $handle = $segments[0];
        if (in_array(strtolower($handle), self::RESERVED['instagram'], true)) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) !== 1 || preg_match('/[A-Za-z]/', $handle) !== 1 || self::isForeign($handle)) {
            return null;
        }

        return ['platform' => 'instagram', 'url' => 'https://www.instagram.com/'.$handle];
    }

    /**
     * @param  list<string>  $segments
     * @return array{platform: string, url: string}|null
     */
    private static function twitter(array $segments, string $host): ?array
    {
        $handle = ltrim($segments[0], '@');
        if (in_array(strtolower($handle), self::RESERVED['twitter'], true)) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle) !== 1 || preg_match('/[A-Za-z]/', $handle) !== 1 || self::isForeign($handle)) {
            return null;
        }

        return ['platform' => 'twitter', 'url' => 'https://'.$host.'/'.$handle];
    }

    /**
     * @param  list<string>  $segments
     * @return array{platform: string, url: string}|null
     */
    private static function tiktok(array $segments): ?array
    {
        $first = $segments[0];
        if (preg_match('/^@([A-Za-z0-9._]{2,24})$/', $first, $m) !== 1 || self::isForeign($m[1])) {
            return null;
        }

        return ['platform' => 'tiktok', 'url' => 'https://www.tiktok.com/@'.$m[1]];
    }

    /**
     * @param  list<string>  $segments
     * @return array{platform: string, url: string}|null
     */
    private static function youtube(array $segments): ?array
    {
        $first = $segments[0];

        if (preg_match('/^@([A-Za-z0-9._\-]{3,30})$/', $first, $m) === 1) {
            return self::isForeign($m[1]) ? null : ['platform' => 'youtube', 'url' => 'https://www.youtube.com/@'.$m[1]];
        }

        $kind = strtolower($first);
        $id = $segments[1] ?? '';
        if ($kind === 'channel' && preg_match('/^UC[A-Za-z0-9_\-]{20,}$/', $id) === 1) {
            return ['platform' => 'youtube', 'url' => 'https://www.youtube.com/channel/'.$id];
        }
        if (($kind === 'c' || $kind === 'user') && preg_match('/^[A-Za-z0-9._\-]{2,100}$/', $id) === 1 && ! self::isForeign($id)) {
            return ['platform' => 'youtube', 'url' => 'https://www.youtube.com/'.$kind.'/'.$id];
        }

        return null;
    }

    private static function isForeign(string $handle): bool
    {
        return in_array(strtolower(trim($handle, '.-_')), self::FOREIGN_HANDLES, true);
    }
}
