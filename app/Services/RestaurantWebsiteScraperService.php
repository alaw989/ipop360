<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Clean own-website scraper for restaurants.
 *
 * Scrapes ONLY the venue's own website (via website_url), honors robots.txt,
 * uses per-domain Cache::lock, caches for 7 days, and stores opening_hours as JSON.
 * Uses PHP's native DOM extension for parsing (no external dependencies).
 *
 * NOTE: This service uses Laravel's Cache facade (Cache::remember/Cache::lock)
 * rather than ExternalApiCache because website scraping is NOT quota-bound
 * (no API limits) and has different invalidation needs (robots.txt changes
 * frequently, scraped data is opportunistic). This separation is intentional —
 * do NOT unify with ExternalApiCache, as cache misses here do NOT burn SerpApi
 * quota. See config/restaurant-finder.php cache section for the full explanation
 * of the two-store architecture.
 */
class RestaurantWebsiteScraperService
{
    /** Cache TTL for scraped data (7 days). */
    private const CACHE_TTL_DAYS = 7;

    /** Cache TTL for social link scrape (30 days — links change rarely). */
    private const SOCIAL_CACHE_TTL_DAYS = 30;

    /** Cache TTL for robots.txt (1 hour). */
    private const ROBOTS_CACHE_TTL_HOURS = 1;

    /** Timeout for HTTP requests (seconds). */
    private const REQUEST_TIMEOUT = 10;

    /** Maximum retry attempts for transient HTTP failures. */
    private const MAX_RETRIES = 3;

    /** Base delay for exponential backoff (milliseconds). */
    private const RETRY_BASE_DELAY_MS = 100;

    /** User agent for requests. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; iPop360-Bot/1.0; +https://ipop360.example.com/bot)';

    /** User agent for free APIs (Wikimedia, Google). Includes contact for rate-limit issues. */
    private const API_USER_AGENT = 'iPop360/1.0 (https://ipop360.com; alaw989@gmail.com)';

    /** Pages to check for social media links, in priority order. */
    private const SOCIAL_SCRAPE_PATHS = ['/', '/contact', '/about'];

    /** Stop early once this many distinct social platforms are found. */
    private const SOCIAL_STOP_EARLY_COUNT = 3;

    /** Domains that are NOT restaurant-owned websites — skip preemptively. */
    private const NON_RESTAURANT_DOMAINS = [
        'facebook.com',
        'www.facebook.com',
        'm.facebook.com',
        'fb.com',
        'instagram.com',
        'www.instagram.com',
        'twitter.com',
        'www.twitter.com',
        'x.com',
        'www.x.com',
        'tiktok.com',
        'www.tiktok.com',
        'youtube.com',
        'www.youtube.com',
        'youtu.be',
        'linkedin.com',
        'www.linkedin.com',
        'yelp.com',
        'www.yelp.com',
        'tripadvisor.com',
        'www.tripadvisor.com',
        'toasttab.com',
        'www.toasttab.com',
        'toast.site',
        'www.toast.site',
        'uorder.io',
        'www.uorder.io',
        'bentoobox.net',
        'www.bentoobox.net',
        'ordering.menubillet.com',
        'menu.ordering.com',
        'google.com',
        'www.google.com',
        'maps.google.com',
        'bing.com',
        'www.bing.com',
        'wikipedia.org',
        'www.wikipedia.org',
        'pinterest.com',
        'www.pinterest.com',
        'opentable.com',
        'www.opentable.com',
        'seamless.com',
        'www.seamless.com',
        'allmenus.com',
        'www.allmenus.com',
        'menupix.com',
        'www.menupix.com',
        'restaurantguru.com',
        'www.restaurantguru.com',
    ];

    /**
     * Scrape a restaurant's own website for opening hours and optional data.
     *
     * @param  string  $websiteUrl  The restaurant's own website URL
     * @return array|null Returns array with 'opening_hours' and optional 'menu_url'/'photo_url', or null if scrape failed/disallowed
     */
    public function scrape(string $websiteUrl): ?array
    {
        if (empty($websiteUrl)) {
            return null;
        }

        // Ensure URL has a scheme before parsing
        if (! str_starts_with($websiteUrl, 'http://') && ! str_starts_with($websiteUrl, 'https://')) {
            $websiteUrl = 'https://'.$websiteUrl;
        }

        // Parse domain for lock and robots.txt
        $domain = $this->parseDomain($websiteUrl);
        if ($domain === null) {
            Log::warning('Failed to parse domain from website URL', ['url' => $websiteUrl]);

            return null;
        }

        // Skip known non-restaurant domains preemptively
        if ($this->isSkipDomain($domain)) {
            Log::info('Website scrape skipped — not a restaurant-owned domain', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        // spec-075 SSRF guard: the website_url is user-controllable (via
        // favorites), so resolve the host and reject private/loopback/link-local/
        // metadata IPs + non-http(s) schemes BEFORE any fetch — including the
        // robots.txt fetch below, which would otherwise itself be the SSRF call.
        // Fail-closed.
        if (config('restaurant-finder.website_scraper.ssrf_guard', true) && ! $this->isSafeUrl($websiteUrl)) {
            Log::warning('Website scrape blocked by SSRF guard', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        // Check robots.txt before scraping
        if (! $this->isAllowedByRobotsTxt($websiteUrl, $domain)) {
            Log::info('Website scraping disallowed by robots.txt', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        // Check cache first
        $cacheKey = 'website_scrape:'.md5($websiteUrl);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::info('Cache hit for website scrape', ['url' => $websiteUrl]);

            return $cached;
        }

        // Acquire per-domain lock to prevent concurrent hits
        $lock = Cache::lock("website_scraper:lock:{$domain}", 10);

        try {
            if (! $lock->get()) {
                Log::debug('Concurrent scrape in progress for domain', ['domain' => $domain]);

                return null;
            }

            $result = $this->performScrape($websiteUrl);

            if ($result !== null) {
                Cache::put($cacheKey, $result, now()->addDays(self::CACHE_TTL_DAYS));
            }

            return $result;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Scrape a restaurant's own website for social media links.
     *
     * Checks multiple pages (homepage, /contact, /about) and stops early
     * once SOCIAL_STOP_EARLY_COUNT distinct platforms are found.
     * Uses its own cache key prefix and 30-day TTL.
     *
     * @param  string  $websiteUrl  The restaurant's own website URL
     * @return array<string,string>|null Platform => URL mapping, or null if scrape failed/disallowed
     */
    public function scrapeSocial(string $websiteUrl): ?array
    {
        if (empty($websiteUrl)) {
            return null;
        }

        if (! str_starts_with($websiteUrl, 'http://') && ! str_starts_with($websiteUrl, 'https://')) {
            $websiteUrl = 'https://'.$websiteUrl;
        }

        $domain = $this->parseDomain($websiteUrl);
        if ($domain === null) {
            Log::warning('Failed to parse domain for social scrape', ['url' => $websiteUrl]);

            return null;
        }

        // Skip known non-restaurant domains preemptively
        if ($this->isSkipDomain($domain)) {
            Log::info('Social scrape skipped — not a restaurant-owned domain', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        // spec-075 SSRF guard — same as scrape()
        if (config('restaurant-finder.website_scraper.ssrf_guard', true) && ! $this->isSafeUrl($websiteUrl)) {
            Log::warning('Social scrape blocked by SSRF guard', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        if (! $this->isAllowedByRobotsTxt($websiteUrl, $domain)) {
            Log::info('Social scrape disallowed by robots.txt', ['url' => $websiteUrl, 'domain' => $domain]);

            return null;
        }

        $cacheKey = 'social_scrape:'.md5($websiteUrl);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock("social_scraper:lock:{$domain}", 15);

        try {
            if (! $lock->get()) {
                Log::debug('Concurrent social scrape in progress for domain', ['domain' => $domain]);

                return null;
            }

            $scheme = parse_url($websiteUrl, PHP_URL_SCHEME) ?: 'https';
            $allPlatforms = [];

            foreach (self::SOCIAL_SCRAPE_PATHS as $path) {
                $pageUrl = "{$scheme}://{$domain}{$path}";
                $platforms = $this->fetchPageForSocial($pageUrl);

                if ($platforms !== null) {
                    $allPlatforms = array_merge($allPlatforms, $platforms);
                }

                if (count($allPlatforms) >= self::SOCIAL_STOP_EARLY_COUNT) {
                    break;
                }
            }

            $result = ! empty($allPlatforms) ? $allPlatforms : null;

            if ($result !== null) {
                Cache::put($cacheKey, $result, now()->addDays(self::SOCIAL_CACHE_TTL_DAYS));
            }

            return $result;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Fetch a single page URL and extract social platform links from its HTML.
     *
     * @return array|null Array of platform keys found on this page, or null on failure
     */
    private function fetchPageForSocial(string $url): ?array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withUserAgent(self::USER_AGENT)
                    ->withOptions(['allow_redirects' => $this->redirectOptions()])
                    ->get($url);

                if (! $response->successful()) {
                    if ($response->clientError()) {
                        return null; // 4xx is permanent — no retry
                    }

                    if ($attempt < self::MAX_RETRIES) {
                        $this->backoff($attempt);

                        continue;
                    }

                    return null;
                }

                $html = $response->body();
                if (empty($html)) {
                    return null;
                }

                return $this->extractSocialLinks($html);
            } catch (ConnectionException $e) {
                $lastException = $e;
                if ($attempt < self::MAX_RETRIES) {
                    $this->backoff($attempt);
                }
            } catch (\Throwable $e) {
                Log::warning('Error during social page fetch', ['url' => $url, 'error' => $e->getMessage()]);

                return null;
            }
        }

        Log::warning('All retries exhausted for social page fetch', ['url' => $url]);

        return null;
    }

    /**
     * Extract social media links from HTML content.
     *
     * Returns an associative array of platform => URL pairs for each
     * distinct social network found on the page.
     *
     * @return array<string,string> Platform keys mapped to their full URLs
     */
    private function extractSocialLinks(string $html): array
    {
        $platforms = [];

        $patterns = [
            'instagram' => '/https?:\/\/(www\.)?instagram\.com\/[a-zA-Z0-9_.]+\/?/i',
            'facebook' => '/https?:\/\/(www\.)?(facebook\.com|fb\.com)\/(?!sharer\/|share\.php)[a-zA-Z0-9.]+\/?/i',
            'tiktok' => '/https?:\/\/(www\.)?tiktok\.com\/@[a-zA-Z0-9_.]+\/?/i',
            'twitter' => '/https?:\/\/(www\.)?(twitter\.com|x\.com)\/(?!share)[a-zA-Z0-9_]+\/?/i',
            'youtube' => '/https?:\/\/(www\.)?(youtube\.com\/(@|channel\/)|youtu\.be\/)[a-zA-Z0-9_-]+\/?/i',
        ];

        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $platforms[$platform] = rtrim($matches[0], '/');
            }
        }

        return $platforms;
    }

    /**
     * Parse the domain from a URL.
     */
    private function parseDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'])) {
            return null;
        }

        return $parsed['host'];
    }

    /**
     * Check if a domain belongs to a known non-restaurant platform (social
     * media, ordering services, aggregators, etc.).
     */
    private function isSkipDomain(string $domain): bool
    {
        return in_array(strtolower($domain), self::NON_RESTAURANT_DOMAINS, true);
    }

    /**
     * spec-075: the guarded allow_redirects config — capped at 3, http(s)-only,
     * and each hop re-validated by isSafeUrl so a public host can't redirect
     * into a private/loopback/metadata endpoint. Honors the SSRF kill-switch
     * (returns a plain cap when the guard is off). Shared by the robots.txt
     * fetch and the main page fetch — BOTH must re-validate hops (the robots.txt
     * fetch is otherwise an SSRF bypass, being the first outbound call).
     *
     * @return array<string,mixed>
     */
    private function redirectOptions(): array
    {
        if (! config('restaurant-finder.website_scraper.ssrf_guard', true)) {
            return ['max' => 3];
        }

        return [
            'max' => 3,
            'strict' => true,
            'protocols' => ['https', 'http'],
            'on_redirect' => function ($request, $response, $uri): void {
                if (! $this->isSafeUrl((string) $uri)) {
                    throw new \RuntimeException('SSRF guard blocked unsafe redirect target: '.$uri);
                }
            },
        ];
    }

    /**
     * spec-075 SSRF guard: is this URL safe for the server to fetch?
     *
     * Allows only http(s), resolves the host, and rejects any resolved IP in a
     * private/loopback/link-local/reserved range — including 169.254.169.254
     * (cloud instance metadata), 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, ::1,
     * and fc00::/7. Fail-closed: an unparseable URL, a non-http(s) scheme, or a
     * DNS resolution failure → unsafe (return false).
     */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false; // rejects file://, gopher://, ftp://, etc.
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }

        // Host may already be an IP literal (e.g. http://127.0.0.1 or an IPv6
        // [::1]); otherwise resolve it. gethostbynamel is IPv4-only, so IPv6-only
        // hostnames fail closed — but bracketed IPv6 literals are validated here.
        $hostLiteral = str_starts_with($host, '[') ? trim($host, '[]') : $host;
        $ips = filter_var($hostLiteral, FILTER_VALIDATE_IP) !== false
            ? [$hostLiteral]
            : gethostbynamel($host);

        if ($ips === false || $ips === []) {
            return false; // DNS failure → fail closed
        }

        foreach ($ips as $ip) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                return false; // private / reserved / loopback / link-local
            }
        }

        return true;
    }

    /**
     * Check if scraping is allowed by robots.txt.
     */
    private function isAllowedByRobotsTxt(string $url, string $domain): bool
    {
        $robotsCacheKey = 'robots_txt:'.$domain;

        // Check cache for robots.txt content
        $robotsTxt = Cache::remember($robotsCacheKey, now()->addHours(self::ROBOTS_CACHE_TTL_HOURS), function () use ($domain, $url) {
            try {
                $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
                $robotsUrl = "{$scheme}://{$domain}/robots.txt";

                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withUserAgent(self::USER_AGENT)
                    ->withOptions(['allow_redirects' => $this->redirectOptions()])
                    ->get($robotsUrl);

                if ($response->successful()) {
                    return $response->body();
                }

                // If robots.txt doesn't exist, assume allowed
                return $response->status() === 404 ? '' : null;
            } catch (\Throwable $e) {
                Log::debug('Failed to fetch robots.txt', ['domain' => $domain, 'error' => $e->getMessage()]);

                // On error, assume allowed (fail open for free-first)
                return null;
            }
        });

        // If robots.txt is missing or empty, allow
        if ($robotsTxt === null || $robotsTxt === '') {
            return true;
        }

        // Parse robots.txt for our user agent
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '/';

        return $this->checkRobotsTxtAllowed($robotsTxt, $path);
    }

    /**
     * Check if a path is allowed by robots.txt content.
     */
    private function checkRobotsTxtAllowed(string $robotsTxt, string $path): bool
    {
        $lines = explode("\n", $robotsTxt);
        $userAgentMatches = false;
        $disallowedPaths = [];
        $allowedPaths = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Check for user-agent lines
            if (preg_match('/^User-agent:\s*(\*|.+)$/i', $line, $matches)) {
                $agent = trim($matches[1]);
                if ($agent === '*' || stripos($agent, 'ipop360') !== false || stripos($agent, 'bot') !== false) {
                    $userAgentMatches = true;
                } else {
                    $userAgentMatches = false;
                }

                continue;
            }

            // Only process disallow/allow if our user agent matches
            if ($userAgentMatches) {
                if (preg_match('/^Disallow:\s*(.+)$/i', $line, $matches)) {
                    $disallowedPaths[] = trim($matches[1]);
                } elseif (preg_match('/^Allow:\s*(.+)$/i', $line, $matches)) {
                    $allowedPaths[] = trim($matches[1]);
                }
            }
        }

        // Check explicit allows first
        foreach ($allowedPaths as $allowPattern) {
            if ($this->pathMatchesPattern($path, $allowPattern)) {
                return true;
            }
        }

        // Check disallows
        foreach ($disallowedPaths as $disallowPattern) {
            if ($this->pathMatchesPattern($path, $disallowPattern)) {
                return false;
            }
        }

        // Default to allowed
        return true;
    }

    /**
     * Check if a path matches a robots.txt pattern.
     */
    private function pathMatchesPattern(string $path, string $pattern): bool
    {
        // Normalize paths
        $path = '/'.ltrim($path, '/');
        $pattern = '/'.ltrim($pattern, '/');

        // Exact match
        if ($pattern === $path) {
            return true;
        }

        // Prefix match
        if (str_starts_with($path, $pattern)) {
            return true;
        }

        // Wildcard match (*) support
        if (str_contains($pattern, '*')) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'#';

            return (bool) preg_match($regex, $path);
        }

        return false;
    }

    /**
     * Perform the actual scraping of the website.
     */
    private function performScrape(string $url): ?array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                // spec-075: cap redirects + re-validate each hop's host so a
                // public initial URL can't redirect into an internal/metadata
                // endpoint. An unsafe hop throws → caught below → null (no retry).
                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withUserAgent(self::USER_AGENT)
                    ->withOptions(['allow_redirects' => $this->redirectOptions()])
                    ->get($url);

                if (! $response->successful()) {
                    Log::warning('Failed to fetch website for scraping', [
                        'url' => $url,
                        'status' => $response->status(),
                        'attempt' => $attempt,
                        'max_retries' => self::MAX_RETRIES,
                    ]);

                    // Retry on transient errors (5xx) or if we have retries left
                    if ($response->serverError() && $attempt < self::MAX_RETRIES) {
                        $this->backoff($attempt);

                        continue;
                    }

                    return null;
                }

                $html = $response->body();
                if (empty($html)) {
                    return null;
                }

                // Use DOMDocument to parse HTML
                libxml_use_internal_errors(true);
                $dom = new DOMDocument;
                $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();

                $xpath = new DOMXPath($dom);

                $result = [
                    'opening_hours' => $this->extractOpeningHours($dom, $xpath, $url),
                    'menu_url' => $this->extractMenuUrl($dom, $xpath, $url),
                    'photo_url' => $this->extractPhotoUrl($dom, $xpath, $url),
                    'photos' => $this->extractPhotos($dom, $xpath, $url),
                ];

                // Only return result if we found something useful
                if ($result['opening_hours'] !== null || $result['menu_url'] !== null || $result['photo_url'] !== null || ! empty($result['photos'])) {
                    return $result;
                }

                return null;
            } catch (ConnectionException $e) {
                $lastException = $e;
                Log::warning('Transient connection error during website scrape', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    $this->backoff($attempt);
                }
            } catch (\Throwable $e) {
                Log::warning('Error during website scrape', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // All retries exhausted
        Log::warning('All retry attempts exhausted for website scrape', [
            'url' => $url,
        ]);

        return null;
    }

    /**
     * Exponential backoff delay between retries.
     */
    private function backoff(int $attempt): void
    {
        $delayMs = self::RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1));
        usleep($delayMs * 1000); // Convert to microseconds
    }

    /**
     * Extract opening hours from the page HTML.
     *
     * Looks for common patterns: JSON-LD, microdata, text patterns like "Mon-Fri 9am-5pm"
     */
    private function extractOpeningHours(DOMDocument $dom, DOMXPath $xpath, string $url): ?array
    {
        // Try JSON-LD structured data first
        $jsonLdHours = $this->extractHoursFromJsonLd($xpath);
        if ($jsonLdHours !== null) {
            return $jsonLdHours;
        }

        // Try microdata / schema.org
        $microdataHours = $this->extractHoursFromMicrodata($xpath);
        if ($microdataHours !== null) {
            return $microdataHours;
        }

        // Try text-based patterns as fallback
        return $this->extractHoursFromText($xpath);
    }

    /**
     * Extract opening hours from JSON-LD structured data.
     */
    private function extractHoursFromJsonLd(DOMXPath $xpath): ?array
    {
        // Find script tags with type="application/ld+json"
        $scripts = $xpath->query("//script[@type='application/ld+json']");

        foreach ($scripts as $script) {
            $json = trim($script->textContent);
            if (empty($json)) {
                continue;
            }

            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                // Handle both single object and array of objects
                $objects = is_array($data) && (isset($data[0]['@type']) || (isset($data[0]) && is_array($data[0])))
                    ? $data
                    : [$data];

                foreach ($objects as $object) {
                    if (! is_array($object)) {
                        continue;
                    }

                    $hours = $object['openingHoursSpecification']
                        ?? $object['openingHours']
                        ?? null;

                    if ($hours !== null) {
                        return $this->normalizeOpeningHours($hours);
                    }
                }
            } catch (\Throwable $e) {
                // Invalid JSON, skip this script tag
                continue;
            }
        }

        return null;
    }

    /**
     * Extract opening hours from microdata/schema.org.
     */
    private function extractHoursFromMicrodata(DOMXPath $xpath): ?array
    {
        // Look for elements with itemprop="openingHours"
        $elements = $xpath->query("//*[@itemprop='openingHours']");

        if ($elements->length > 0) {
            $hours = [];
            foreach ($elements as $element) {
                $content = trim($element->textContent);
                if (! empty($content)) {
                    $hours[] = $content;
                }
            }

            if (! empty($hours)) {
                return $this->normalizeOpeningHours($hours);
            }
        }

        // Also check for time elements with datetime attribute
        $timeElements = $xpath->query('//time[@datetime]');
        if ($timeElements->length > 0) {
            $hours = [];
            foreach ($timeElements as $element) {
                $datetime = $element->getAttribute('datetime');
                if (! empty($datetime)) {
                    $hours[] = $datetime;
                }
            }

            if (! empty($hours)) {
                return $this->normalizeOpeningHours($hours);
            }
        }

        return null;
    }

    /**
     * Extract opening hours from text patterns.
     */
    private function extractHoursFromText(DOMXPath $xpath): ?array
    {
        $selectors = [
            "//div[contains(@class, 'hour')]",
            "//span[contains(@class, 'hour')]",
            "//p[contains(@class, 'hour')]",
            "//*[contains(@id, 'hour')]",
            "//div[contains(@class, 'info')]",
            "//div[contains(@class, 'schedule')]",
            "//div[contains(@class, 'time')]",
            "//section[contains(@class, 'hours')]",
        ];

        foreach ($selectors as $selector) {
            try {
                $elements = $xpath->query($selector);
                foreach ($elements as $element) {
                    $text = trim($element->textContent);
                    if ($this->looksLikeHoursText($text)) {
                        return $this->parseHoursText($text);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Fallback: scan all visible text blocks for hour patterns
        try {
            $body = $xpath->query('//body');
            if ($body->length > 0) {
                $bodyText = $body->item(0)->textContent;
                $blocks = preg_split('/\n\s*\n/', $bodyText);
                foreach ($blocks as $block) {
                    $block = trim($block);
                    if (strlen($block) > 20 && strlen($block) < 500 && $this->looksLikeHoursText($block)) {
                        return $this->parseHoursText($block);
                    }
                }
            }
        } catch (\Throwable $e) {
            // fallback failed silently
        }

        return null;
    }

    /**
     * Check if text looks like it contains opening hours.
     */
    private function looksLikeHoursText(string $text): bool
    {
        $patterns = [
            // Day name followed by time range (e.g., "Mon-Fri 11am-9pm", "Monday - Friday 11:00 AM - 9:00 PM")
            '/(?:mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday).*?\d{1,2}(?::\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)/i',
            // "Open daily 11am-9pm" or "Hours: 11am-9pm"
            '/(?:open|hours?|hrs?)\s*:?.+?\d{1,2}(?::\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)/i',
            // Standalone time range with AM/PM (e.g., "11:00 AM - 9:00 PM")
            '/\d{1,2}(?::\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)\s*(?:-|–|—|to)\s*\d{1,2}(?::\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)/i',
            // 24h format (e.g., "11:00-21:00", "09:00 - 17:00")
            '/\b\d{1,2}:\d{2}\s*(?:-|–|—|to)\s*\d{1,2}:\d{2}\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse hours text into structured format.
     */
    private function parseHoursText(string $text): array
    {
        // Simple parse - return the raw text for now, could be enhanced
        // with more sophisticated pattern matching
        return [
            'raw_text' => $text,
            'structured' => false,
        ];
    }

    /**
     * Normalize opening hours to a consistent format.
     */
    private function normalizeOpeningHours($hours): ?array
    {
        if ($hours === null) {
            return null;
        }

        // If already an array, ensure proper structure
        if (is_array($hours)) {
            // Handle JSON-LD format with dayOfWeek/open/close
            if (isset($hours[0]) && isset($hours[0]['dayOfWeek'])) {
                $structured = [];
                foreach ($hours as $spec) {
                    if (isset($spec['dayOfWeek'], $spec['opens'], $spec['closes'])) {
                        $day = $this->normalizeDayName($spec['dayOfWeek']);
                        if ($day) {
                            $structured[] = [
                                'day' => $day,
                                'open' => $spec['opens'],
                                'close' => $spec['closes'],
                            ];
                        }
                    }
                }

                if (! empty($structured)) {
                    return ['structured' => true, 'hours' => $structured];
                }
            }

            // Handle simple string array
            $strings = array_filter($hours, 'is_string');
            if (! empty($strings)) {
                return ['structured' => false, 'raw_text' => implode("\n", $strings)];
            }
        }

        // Handle single string
        if (is_string($hours)) {
            return ['structured' => false, 'raw_text' => $hours];
        }

        return null;
    }

    /**
     * Normalize day names to standard format.
     */
    private function normalizeDayName(string $day): ?string
    {
        $day = strtolower(trim($day));

        $map = [
            'mon' => 'Monday',
            'monday' => 'Monday',
            'tue' => 'Tuesday',
            'tuesday' => 'Tuesday',
            'wed' => 'Wednesday',
            'wednesday' => 'Wednesday',
            'thu' => 'Thursday',
            'thursday' => 'Thursday',
            'fri' => 'Friday',
            'friday' => 'Friday',
            'sat' => 'Saturday',
            'saturday' => 'Saturday',
            'sun' => 'Sunday',
            'sunday' => 'Sunday',
            // Schema.org URIs
            'http://schema.org/monday' => 'Monday',
            'http://schema.org/tuesday' => 'Tuesday',
            'http://schema.org/wednesday' => 'Wednesday',
            'http://schema.org/thursday' => 'Thursday',
            'http://schema.org/friday' => 'Friday',
            'http://schema.org/saturday' => 'Saturday',
            'http://schema.org/sunday' => 'Sunday',
        ];

        return $map[$day] ?? null;
    }

    /**
     * Extract menu URL from the page.
     */
    private function extractMenuUrl(DOMDocument $dom, DOMXPath $xpath, string $baseUrl): ?string
    {
        // Look for links with text containing "menu"
        $links = $xpath->query('//a');

        foreach ($links as $link) {
            $text = strtolower(trim($link->textContent));
            $href = $link->getAttribute('href');

            if (empty($href)) {
                continue;
            }

            // Check if link text indicates it's a menu
            if (str_contains($text, 'menu') || str_contains($text, 'food') || str_contains($text, 'order')) {
                // Convert relative URL to absolute
                if (! str_starts_with($href, 'http')) {
                    $href = $this->resolveUrl($href, $baseUrl);
                }

                if (! empty($href)) {
                    return $href;
                }
            }
        }

        return null;
    }

    /**
     * Extract photo URL from og:image or twitter:image meta tags.
     */
    private function extractPhotoUrl(DOMDocument $dom, DOMXPath $xpath, string $baseUrl): ?string
    {
        $patterns = [
            "//meta[@property='og:image']",
            "//meta[@name='twitter:image']",
            "//meta[@property='og:image:secure_url']",
        ];

        foreach ($patterns as $pattern) {
            $nodes = $xpath->query($pattern);
            if ($nodes !== false && $nodes->length > 0) {
                $content = $nodes->item(0)->getAttribute('content');
                if (! empty($content)) {
                    if (str_starts_with($content, 'http://') || str_starts_with($content, 'https://')) {
                        return $content;
                    }
                    if (str_starts_with($content, '//')) {
                        return 'https:'.$content;
                    }

                    return $this->resolveUrl($content, $baseUrl);
                }
            }
        }

        return null;
    }

    /**
     * Collect MULTIPLE photo URLs for the card gallery: og:image /
     * og:image:secure_url / twitter:image meta tags, plus a bounded set of
     * <img> srcs from the page (deduped, absolute, capped at MAX_GALLERY_PHOTOS).
     * Free — purely the venue's own website, no third-party image service.
     *
     * @return string[] Absolute, deduplicated photo URLs (0..MAX_GALLERY_PHOTOS)
     */
    public function extractPhotos(DOMDocument $dom, DOMXPath $xpath, string $baseUrl): array
    {
        $max = (int) config('restaurant-finder.live_search.gallery_photos_max', 6);

        $photos = [];

        // 1) Meta-tag photos (og:image + twitter:image), in priority order.
        foreach ([
            "//meta[@property='og:image']",
            "//meta[@property='og:image:secure_url']",
            "//meta[@name='twitter:image']",
        ] as $pattern) {
            $nodes = $xpath->query($pattern);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }
            foreach ($nodes as $node) {
                $content = $node->getAttribute('content');
                if (empty($content)) {
                    continue;
                }
                $url = $this->normalizePhotoUrl($content, $baseUrl);
                if ($url !== null && ! in_array($url, $photos, true)) {
                    $photos[] = $url;
                    if (count($photos) >= $max) {
                        return $photos;
                    }
                }
            }
        }

        // 2) Fall back to page <img> elements (e.g. hero/gallery markup without
        // og:image). Skipped once the meta photos already fill the gallery.
        $imgNodes = $xpath->query('//img[@src]');
        if ($imgNodes !== false) {
            foreach ($imgNodes as $img) {
                $src = $img->getAttribute('src');
                if (empty($src) || str_contains($src, 'data:image')) {
                    continue;
                }
                $url = $this->normalizePhotoUrl($src, $baseUrl);
                if ($url === null || in_array($url, $photos, true)) {
                    continue;
                }
                // Skip tracking/icon/sprite images (tiny or clearly non-photo).
                if (preg_match('/\.(svg|gif|ico|png)(\?|$)/i', $url) === 1) {
                    continue;
                }
                $photos[] = $url;
                if (count($photos) >= $max) {
                    break;
                }
            }
        }

        return array_values(array_slice($photos, 0, $max));
    }

    /**
     * Resolve a photo URL to an absolute https URL, or null if unusable.
     */
    private function normalizePhotoUrl(string $content, string $baseUrl): ?string
    {
        if (str_starts_with($content, 'http://') || str_starts_with($content, 'https://')) {
            return $content;
        }
        if (str_starts_with($content, '//')) {
            return 'https:'.$content;
        }

        return $this->resolveUrl($content, $baseUrl);
    }

    /**
     * Search Wikimedia Commons for a restaurant photo by name + location.
     * Free, no API key required. Used as fallback when og:image is missing.
     *
     * @return string|null URL of the first matching image, or null
     */
    public function searchWikimediaCommons(string $restaurantName, ?string $city = null, ?string $state = null): ?string
    {
        $queries = [$restaurantName];
        if ($city) {
            $queries[] = "{$restaurantName} {$city}";
        }
        if ($state) {
            $queries[] = "{$restaurantName} {$state}";
        }

        foreach ($queries as $query) {
            try {
                $response = Http::timeout(8)
                    ->withUserAgent(self::API_USER_AGENT)
                    ->get('https://commons.wikimedia.org/w/api.php', [
                        'action' => 'query',
                        'list' => 'search',
                        'srsearch' => $query,
                        'srnamespace' => 6,
                        'srlimit' => 3,
                        'format' => 'json',
                        'origin' => '*',
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $data = $response->json();
                $pages = $data['query']['search'] ?? [];

                foreach ($pages as $page) {
                    $title = $page['title'] ?? '';
                    if (empty($title)) {
                        continue;
                    }

                    $imageUrl = $this->resolveCommonsImageUrl($title);
                    if ($imageUrl !== null) {
                        return $imageUrl;
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('Wikimedia Commons search failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Resolve a Wikimedia Commons file title to its full image URL.
     */
    private function resolveCommonsImageUrl(string $title): ?string
    {
        try {
            $response = Http::timeout(5)
                ->withUserAgent(self::API_USER_AGENT)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'titles' => $title,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url',
                    'iiurlwidth' => 800,
                    'format' => 'json',
                    'origin' => '*',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $pages = $data['query']['pages'] ?? [];

            foreach ($pages as $page) {
                $imageInfo = $page['imageinfo'][0] ?? null;
                if ($imageInfo !== null && ! empty($imageInfo['url'])) {
                    $url = $imageInfo['url'];
                    if (str_ends_with($url, '.jpg') || str_ends_with($url, '.png') || str_ends_with($url, '.jpeg')) {
                        return $url;
                    }

                    return $imageInfo['thumburl'] ?? $url;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Wikimedia Commons image resolution failed', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Search Wikipedia for a restaurant photo via the page's infobox image.
     * Free, unlimited, no API key required.
     *
     * @return string|null URL of the article's page image, or null
     */
    public function searchWikipediaImage(string $restaurantName, ?string $city = null, ?string $state = null): ?string
    {
        $queries = [
            trim("{$restaurantName} {$city} {$state} restaurant"),
            trim("{$restaurantName} {$city} (restaurant)"),
            trim("{$restaurantName} restaurant"),
        ];

        foreach ($queries as $query) {
            if (empty($query)) {
                continue;
            }

            try {
                $response = Http::timeout(5)
                    ->withUserAgent(self::API_USER_AGENT)
                    ->get('https://en.wikipedia.org/w/api.php', [
                        'action' => 'query',
                        'list' => 'search',
                        'srsearch' => $query,
                        'srlimit' => 3,
                        'format' => 'json',
                        'origin' => '*',
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $pages = $response->json('query.search', []);

                foreach ($pages as $page) {
                    $title = $page['title'] ?? '';
                    if (empty($title)) {
                        continue;
                    }

                    $imageUrl = $this->resolveWikipediaPageImage($title);
                    if ($imageUrl !== null) {
                        return $imageUrl;
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('Wikipedia image search failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Get the page image (infobox photo) for a Wikipedia article.
     */
    private function resolveWikipediaPageImage(string $title): ?string
    {
        try {
            $response = Http::timeout(5)
                ->withUserAgent(self::API_USER_AGENT)
                ->get('https://en.wikipedia.org/w/api.php', [
                    'action' => 'query',
                    'titles' => $title,
                    'prop' => 'pageimages',
                    'pithumbsize' => 500,
                    'format' => 'json',
                    'origin' => '*',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $pages = $response->json('query.pages', []);

            foreach ($pages as $page) {
                $thumb = $page['thumbnail']['source'] ?? null;
                if ($thumb !== null) {
                    return $thumb;
                }

                $pageImage = $page['pageimage'] ?? null;
                if ($pageImage !== null) {
                    return "https://en.wikipedia.org/wiki/Special:Redirect/file/{$pageImage}?width=500";
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Wikipedia page image resolution failed', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Search Google Custom Search for a restaurant image via review/menu sites.
     * Requires a Google Custom Search API key + engine ID (cx).
     * Free tier: 100 queries/day.
     *
     * Searches configured sites (yelp, tripadvisor, etc.) for the restaurant
     * name and returns the first image result.
     */
    public function searchGoogleImages(string $name, ?string $city = null, ?string $state = null): ?string
    {
        $apiKey = config('services.google_custom_search.api_key');
        $cx = config('services.google_custom_search.cx');

        if (empty($apiKey) || empty($cx)) {
            return null;
        }

        $query = trim("{$name} {$city} {$state} restaurant");
        if (empty($query)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://www.googleapis.com/customsearch/v1', [
                'key' => $apiKey,
                'cx' => $cx,
                'q' => $query,
                'searchType' => 'image',
                'num' => 1,
                'safe' => 'active',
            ]);

            if ($response->failed()) {
                Log::debug('Google Custom Search failed', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $items = $response->json('items', []);

            return $items[0]['link'] ?? null;
        } catch (\Throwable $e) {
            Log::debug('Google Custom Search threw exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Chain all image sources in order, returning the first match.
     * Website og:image → Twitter:image → Wikimedia Commons → Wikipedia → Google Custom Search
     */
    public function searchAnyImage(string $name, ?string $city = null, ?string $state = null, ?string $websiteUrl = null): ?string
    {
        if (! empty($websiteUrl)) {
            $scraped = $this->scrape($websiteUrl);
            if ($scraped !== null && ! empty($scraped['photo_url'])) {
                return $scraped['photo_url'];
            }
        }

        $wikimedia = $this->searchWikimediaCommons($name, $city, $state);
        if ($wikimedia !== null) {
            return $wikimedia;
        }

        $wikipedia = $this->searchWikipediaImage($name, $city, $state);
        if ($wikipedia !== null) {
            return $wikipedia;
        }

        return $this->searchGoogleImages($name, $city, $state);
    }

    /**
     * Resolve a relative URL against a base URL.
     */
    private function resolveUrl(string $relative, string $base): string
    {
        $parsed = parse_url($base);

        if ($parsed === false) {
            return $relative;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';

        // Absolute path
        if (str_starts_with($relative, '/')) {
            return "{$scheme}://{$host}{$relative}";
        }

        // Relative path
        $basePath = dirname($path);

        return "{$scheme}://{$host}{$basePath}/".ltrim($relative, '/');
    }
}
