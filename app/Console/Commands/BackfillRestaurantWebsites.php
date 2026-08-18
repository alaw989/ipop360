<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackfillRestaurantWebsites extends Command
{
    protected $signature = 'restaurants:backfill-websites
        {--dry-run : Show what would be updated without making changes}
        {--limit=0 : Max restaurants to process (0 = unlimited)}
        {--skip-cache : Skip the cache lookup phase}
        {--skip-search : Skip the web search phase}';

    protected $description = 'Backfill missing website URLs from cache and web search, then scrape social links';

    private int $found = 0;

    private const CACHE_SOURCES = ['serpapi', 'preview', 'bizdata'];

    /**
     * Max restaurants whose menu URL + opening hours are scraped per run.
     * Bounded so a single daily sweep never hammers every website at once;
     * the scrape result is cached per-domain (7d TTL) so the next run picks up
     * where this one left off cheaply.
     */
    private const MENU_SCRAPE_DAILY_LIMIT = 200;

    private const DOMAIN_SKIP_PATTERNS = [
        '/facebook\.com/i', '/instagram\.com/i', '/twitter\.com/i', '/x\.com/i',
        '/yelp\.com/i', '/tripadvisor\.com/i', '/youtube\.com/i', '/tiktok\.com/i',
        '/linkedin\.com/i', '/pinterest\.com/i',
        '/google\.com/i', '/bing\.com/i', '/microsoft\.com/i',
        '/wikipedia\.org/i', '/menupix\.com/i', '/allmenus\.com/i',
        '/restaurantguru\.com/i', '/opentable\.com/i', '/seamless\.com/i',
        '/toasttab\.com/i', '/toast\.site/i', '/uorder\.io/i', '/bentoobox\.net/i',
    ];

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
        $dryRun = $this->option('dry-run');
        $skipCache = $this->option('skip-cache');
        $skipSearch = $this->option('skip-search');

        if (! $skipCache) {
            $this->matchFromCache($dryRun);
        }

        $remaining = $this->countMissing();
        if ($remaining > 0 && ! $skipSearch) {
            $this->info("Web searching for {$remaining} restaurant(s)...");
            $this->webSearch($dryRun);
        }

        $remaining = $this->countMissing();
        if ($remaining > 0 && ! $skipSearch) {
            $this->info("Guessing domains for {$remaining} restaurant(s)...");
            $this->guessFromTitle($dryRun);
        }

        $newWebsites = $this->countNewWebsites();
        if ($newWebsites > 0 && ! $dryRun) {
            $this->info("Scraping social links for {$newWebsites} new website(s)...");
            $this->scrapeSocialLinks($scraper);
        }

        $this->scrapeMenuData($scraper, $dryRun);

        $this->newLine();
        $this->info("Done. {$this->found} website(s) found.");
        $left = $this->countMissing();
        if ($left > 0) {
            $this->warn("  {$left} restaurant(s) still missing website URLs.");
        }

        return self::SUCCESS;
    }

    private function matchFromCache(bool $dryRun): void
    {
        $this->line('Building cache index...');
        $phoneIndex = [];
        $nameIndex = [];

        foreach (self::CACHE_SOURCES as $source) {
            $entries = ExternalApiCache::where('source', $source)->get();
            foreach ($entries as $entry) {
                $venues = $entry->data;
                foreach ($venues as $venue) {
                    $website = $venue['website'] ?? $venue['website_url'] ?? null;
                    if (empty($website)) {
                        continue;
                    }
                    $parsedPrice = $this->parseExtractedPrice($venue);
                    $entryData = [
                        '_website' => $website,
                        '_price_range' => $parsedPrice,
                        '_phone' => $venue['phone'] ?? null,
                        '_description' => $this->sanitizeDescription($venue['description'] ?? null),
                    ];
                    $name = $this->normalize($venue['title'] ?? $venue['name'] ?? '');
                    if ($name !== '') {
                        $nameIndex[$name] = $entryData;
                    }
                    $phone = $venue['phone'] ?? null;
                    if (! empty($phone)) {
                        $digits = substr(preg_replace('/\D+/', '', (string) $phone) ?? '', -10);
                        if (strlen($digits) === 10) {
                            $phoneIndex[$digits] = $entryData;
                        }
                    }
                }
            }
        }

        $this->line('  Name index: '.count($nameIndex).', Phone index: '.count($phoneIndex));

        $missing = $this->cacheCandidates()->get();
        $hits = 0;
        $phonesBackfilled = 0;
        $pricesBackfilled = 0;
        $descriptionsBackfilled = 0;

        foreach ($missing as $restaurant) {
            $entryData = null;

            // Try name match
            $nameKey = $this->normalize($restaurant->name);
            if (isset($nameIndex[$nameKey])) {
                $entryData = $nameIndex[$nameKey];
            }

            // Try phone match
            if ($entryData === null) {
                $phoneDigits = substr((string) preg_replace('/\D+/', '', $restaurant->phone ?? ''), -10);
                if (strlen($phoneDigits) === 10 && isset($phoneIndex[$phoneDigits])) {
                    $entryData = $phoneIndex[$phoneDigits];
                }
            }

            if ($entryData !== null) {
                $updates = [];
                if (empty($restaurant->website_url) && is_string($entryData['_website'])) {
                    if (! $this->isSkipDomainUrl($entryData['_website'])) {
                        $updates['website_url'] = $entryData['_website'];
                    } else {
                        $this->line("  Skipped cache URL (non-restaurant domain): {$entryData['_website']}");
                    }
                }
                if (empty($restaurant->price_range) && is_string($entryData['_price_range'])) {
                    $updates['price_range'] = $entryData['_price_range'];
                }
                $phone = $this->normalizeCachePhone($entryData['_phone'] ?? null);
                if (empty($restaurant->phone) && $phone !== null) {
                    $updates['phone'] = $phone;
                }
                if (empty($restaurant->description) && is_string($entryData['_description'])) {
                    $updates['description'] = $entryData['_description'];
                }
                if (! empty($updates) && ! $dryRun) {
                    $restaurant->update($updates);
                    Log::channel('enrichment')->info('Cache backfill from cached search data', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $updates['website_url'] ?? null,
                        'price_range' => $updates['price_range'] ?? null,
                        'phone' => $updates['phone'] ?? null,
                        'description' => $updates['description'] ?? null,
                    ]);
                }
                if (isset($updates['phone'])) {
                    $phonesBackfilled++;
                }
                if (isset($updates['price_range'])) {
                    $pricesBackfilled++;
                }
                if (isset($updates['description'])) {
                    $descriptionsBackfilled++;
                }
                $hits++;
                if (isset($updates['website_url'])) {
                    $this->found++;
                }
            }
        }

        $this->line("  Cache matched {$hits} restaurant(s).");
        $this->line("  Phone backfilled for {$phonesBackfilled} restaurant(s).");
        $this->line("  Price backfilled for {$pricesBackfilled} restaurant(s).");
        $this->line("  Description backfilled for {$descriptionsBackfilled} restaurant(s).");
    }

    /**
     * Restaurants the cache phase may enrich: those missing a website URL, a
     * phone number, a price range or a description (fill-empty all from the
     * cached live-search venue data, free of charge — no web search, no AI quota).
     *
     * @return Builder<Restaurant>
     */
    private function cacheCandidates(): Builder
    {
        return Restaurant::query()
            ->active()
            ->where(function ($q) {
                $q->where(function ($website) {
                    $website->whereNull('website_url')->orWhere('website_url', '');
                })->orWhere(function ($phone) {
                    $phone->whereNull('phone')->orWhere('phone', '');
                })->orWhere(function ($price) {
                    $price->whereNull('price_range')->orWhere('price_range', '');
                })->orWhere(function ($description) {
                    $description->whereNull('description')->orWhere('description', '');
                });
            });
    }

    /**
     * A cached venue description worth storing, or null. Rejects missing,
     * non-string and implausibly short values so no junk blurb is persisted.
     */
    private function sanitizeDescription(mixed $description): ?string
    {
        if (! is_string($description)) {
            return null;
        }

        $trimmed = trim($description);

        return strlen($trimmed) >= 20 ? $trimmed : null;
    }

    /**
     * Normalize a cached venue phone to the corpus's 10-digit convention
     * (digits only, last 10). Returns null for missing or implausibly short
     * values so nothing bogus is stored.
     */
    private function normalizeCachePhone(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_numeric($phone)) {
            return null;
        }

        $digits = substr((string) preg_replace('/\D+/', '', (string) $phone), -10);

        return strlen($digits) === 10 ? $digits : null;
    }

    /** @param array<string, mixed> $venue */
    private function parseExtractedPrice(array $venue): ?string
    {
        $raw = $venue['extracted_price'] ?? null;
        if (is_numeric($raw)) {
            $val = (int) $raw;

            return match (true) {
                $val < 15 => '$',
                $val < 30 => '$$',
                $val < 50 => '$$$',
                default => '$$$$',
            };
        }

        $price = $venue['price'] ?? null;
        if ($price !== null && preg_match('/(\d+)/', $price, $m)) {
            $val = (int) $m[1];

            return match (true) {
                $val < 15 => '$',
                $val < 30 => '$$',
                $val < 50 => '$$$',
                default => '$$$$',
            };
        }

        return null;
    }

    private function webSearch(bool $dryRun): void
    {
        $limit = (int) $this->option('limit');
        $restaurants = $this->missingRestaurants($limit)->get();
        $total = $restaurants->count();

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $found = 0;

        foreach ($restaurants as $restaurant) {
            $url = $this->searchWeb($restaurant->name, $restaurant->city, $restaurant->state);

            if ($url !== null) {
                if (! $dryRun) {
                    $restaurant->update(['website_url' => $url]);
                    Log::channel('enrichment')->info('Website backfilled from web search', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $url,
                    ]);
                }
                $found++;
                $this->found++;
            }

            // Small delay to avoid rate limiting
            if (! $dryRun) {
                usleep(200_000);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  Web search found {$found} website(s).");
    }

    /**
     * Try Bing first, fall back to DuckDuckGo if Bing fails or returns nothing.
     */
    private function searchWeb(string $name, ?string $city, ?string $state): ?string
    {
        $url = $this->searchBing($name, $city, $state);

        if ($url !== null) {
            return $url;
        }

        return $this->searchDuckDuckGoHtml($name, $city, $state);
    }

    /**
     * Search Bing HTML results for the restaurant website.
     * Parses base64-encoded redirect URLs from Bing's search result links.
     */
    private function searchBing(string $name, ?string $city, ?string $state): ?string
    {
        $query = trim("{$name} {$city} {$state} official website");
        $query = substr($query, 0, 200);

        try {
            $response = Http::timeout(8)
                ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->withOptions(['allow_redirects' => true])
                ->get('https://www.bing.com/search', ['q' => $query]);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Bing stores the real URL in the u= parameter of ck/a redirect links
            preg_match_all('~u=a1([a-zA-Z0-9+/=]+)~i', $html, $matches);

            if (empty($matches[1])) {
                return null;
            }

            foreach ($matches[1] as $encoded) {
                $decoded = base64_decode($encoded, true);
                if ($decoded === false || empty($decoded)) {
                    continue;
                }

                $url = urldecode($decoded);

                if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                    continue;
                }

                if (! $this->isSkipDomainUrl($url)) {
                    return $url;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fallback search using DuckDuckGo HTML.
     * Parses redirect URLs from DuckDuckGo's search result links (uddg parameter).
     */
    private function searchDuckDuckGoHtml(string $name, ?string $city, ?string $state): ?string
    {
        $query = trim("{$name} {$city} {$state} official website");
        $query = substr($query, 0, 200);

        try {
            $response = Http::timeout(8)
                ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->withOptions(['allow_redirects' => true])
                ->get('https://html.duckduckgo.com/html/', ['q' => $query]);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // DuckDuckGo wraps result links in <a> with class result__a
            // They redirect via the uddg parameter (base64-encoded URL)
            preg_match_all('#uddg=([a-zA-Z0-9+/=%]+)#i', $html, $matches);

            if (empty($matches[1])) {
                // Fallback: try scraping direct href from result links
                libxml_use_internal_errors(true);
                $dom = new \DOMDocument;
                $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();

                $xpath = new \DOMXPath($dom);
                $links = $xpath->query("//a[contains(@class, 'result__a')]");

                if ($links === false) {
                    return null;
                }

                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if (empty($href)) {
                        continue;
                    }
                    // Direct URL (no DDG redirect wrapper)
                    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                        if (! $this->isSkipDomainUrl($href)) {
                            return $href;
                        }

                        continue;
                    }
                    // Maybe a DDG-style redirect URL
                    $url = $this->extractUrlFromDdgRedirect($href);
                    if ($url !== null && ! $this->isSkipDomainUrl($url)) {
                        return $url;
                    }
                }

                return null;
            }

            foreach ($matches[1] as $encoded) {
                $url = $this->extractUrlFromDdgRedirect($encoded);
                if ($url !== null && ! $this->isSkipDomainUrl($url)) {
                    return $url;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract a real URL from a DuckDuckGo redirect parameter.
     */
    private function extractUrlFromDdgRedirect(string $encoded): ?string
    {
        $decoded = urldecode($encoded);
        $decoded = base64_decode($decoded, true);
        if ($decoded === false || empty($decoded)) {
            return null;
        }

        if (! str_starts_with($decoded, 'http://') && ! str_starts_with($decoded, 'https://')) {
            return null;
        }

        return $decoded;
    }

    private function normalize(string $name): string
    {
        $name = preg_replace('/[^a-z0-9\s]/i', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';

        return strtolower(trim($name));
    }

    /**
     * Check if a URL belongs to a known non-restaurant domain via skip patterns.
     */
    private function isSkipDomainUrl(string $url): bool
    {
        foreach (self::DOMAIN_SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    private function countMissing(): int
    {
        return Restaurant::query()
            ->active()
            ->where(function ($q) {
                $q->whereNull('website_url')->orWhere('website_url', '');
            })
            ->count();
    }

    private function countNewWebsites(): int
    {
        return Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->where('social_links_count', 0)
            ->count();
    }

    /** @return Builder<Restaurant> */
    private function missingRestaurants(int $limit): Builder
    {
        $q = Restaurant::query()
            ->active()
            ->where(function ($q) {
                $q->whereNull('website_url')->orWhere('website_url', '');
            });

        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q;
    }

    private function scrapeSocialLinks(RestaurantWebsiteScraperService $scraper): void
    {
        $query = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->where('social_links_count', 0);

        $total = $query->count();
        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(50, function ($restaurants) use ($scraper, $bar) {
            foreach ($restaurants as $restaurant) {
                try {
                    if ($restaurant->website_url === null || $restaurant->website_url === '') {
                        $bar->advance();

                        continue;
                    }
                    $links = $scraper->scrapeSocial($restaurant->website_url);

                    if ($links !== null) {
                        RestaurantSocialLink::where('restaurant_id', $restaurant->id)->delete();
                        foreach ($links as $platform => $url) {
                            RestaurantSocialLink::create([
                                'restaurant_id' => $restaurant->id,
                                'platform' => $platform,
                                'url' => $url,
                            ]);
                        }
                        $restaurant->update(['social_links_count' => count($links)]);
                    }
                } catch (\Throwable $e) {
                    Log::channel('enrichment')->warning('Social scrape failed during website backfill', [
                        'restaurant_id' => $restaurant->id,
                        'website_url' => $restaurant->website_url ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Scrape menu URLs + opening hours from the websites of restaurants that
     * have a website_url but are missing menu_url or opening_hours (menu_url
     * was the corpus's biggest gap: 92% of active rows). Fill-empty only —
     * existing menu_url/opening_hours are never clobbered, and rows missing
     * only opening_hours are revisited until both fields are filled. Bounded
     * per run by {@see self::MENU_SCRAPE_DAILY_LIMIT} and driven by the same
     * per-domain cache as the rest of the scraper.
     */
    private function scrapeMenuData(RestaurantWebsiteScraperService $scraper, bool $dryRun): void
    {
        $query = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->where(function ($q) {
                $q->whereNull('menu_url')->orWhere('menu_url', '')
                    ->orWhereNull('opening_hours')->orWhere('opening_hours', '');
            })
            ->orderByRaw('COALESCE(popularity_score, 0) DESC')
            ->orderBy('id')
            ->limit(self::MENU_SCRAPE_DAILY_LIMIT);

        $restaurants = $query->get();
        $total = $restaurants->count();

        if ($total === 0) {
            return;
        }

        $this->info("Scraping menu URLs + hours for {$total} restaurant(s)...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $found = 0;

        foreach ($restaurants as $restaurant) {
            try {
                if ($restaurant->website_url === null || $restaurant->website_url === '') {
                    $bar->advance();

                    continue;
                }

                $scraped = $scraper->scrape($restaurant->website_url);

                if ($scraped !== null && (! empty($scraped['menu_url']) || ! empty($scraped['opening_hours']))) {
                    $updates = [];

                    if (! empty($scraped['menu_url']) && empty($restaurant->menu_url)) {
                        $updates['menu_url'] = $scraped['menu_url'];
                    }
                    if (! empty($scraped['opening_hours']) && empty($restaurant->opening_hours)) {
                        $updates['opening_hours'] = $scraped['opening_hours'];
                    }

                    if (! $dryRun && ! empty($updates)) {
                        $restaurant->update($updates);
                        Log::channel('enrichment')->info('Menu URL + hours backfilled from website scrape', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'website_url' => $restaurant->website_url,
                            'menu_url' => $scraped['menu_url'] ?? null,
                            'opening_hours' => $scraped['opening_hours'] ?? null,
                        ]);
                    }

                    $found++;
                }
            } catch (\Throwable $e) {
                Log::channel('enrichment')->warning('Menu scrape failed during website backfill', [
                    'restaurant_id' => $restaurant->id,
                    'website_url' => $restaurant->website_url ?? null,
                    'message' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  Menu/hours scraped for {$found} restaurant(s).");
    }

    private function guessFromTitle(bool $dryRun): void
    {
        $limit = (int) $this->option('limit');
        $restaurants = $this->missingRestaurants($limit)->get();
        $total = $restaurants->count();

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $found = 0;

        foreach ($restaurants as $restaurant) {
            $candidates = $this->candidateDomains($restaurant->name, $restaurant->city);
            $url = null;

            foreach ($candidates as $domain) {
                try {
                    $response = Http::timeout(5)
                        ->withUserAgent('Mozilla/5.0')
                        ->head($domain);

                    if ($response->successful()) {
                        $url = $domain;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if ($url !== null) {
                if ($this->isSkipDomainUrl($url)) {
                    $this->line("  Skipped guessed URL (non-restaurant domain): {$url}");
                    $bar->advance();

                    continue;
                }
                if (! $dryRun) {
                    $restaurant->update(['website_url' => $url]);
                    Log::channel('enrichment')->info('Website backfilled from domain guess', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $url,
                    ]);
                }
                $found++;
                $this->found++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  Domain guessing found {$found} website(s).");
    }

    /** @return array<string> */
    private function candidateDomains(string $name, ?string $city): array
    {
        $slug = $this->toSlug($name);
        $citySlug = $city ? $this->toSlug($city) : '';

        $domains = [
            "https://www.{$slug}.com",
            "https://{$slug}.com",
        ];

        if ($citySlug !== '') {
            $domains[] = "https://www.{$slug}{$citySlug}.com";
            $domains[] = "https://{$slug}{$citySlug}.com";
        }

        // Try with hyphens between words if multi-word
        $words = explode('-', $slug);
        if (count($words) > 1) {
            $joined = implode('', $words);
            $domains[] = "https://www.{$joined}.com";
            $domains[] = "https://{$joined}.com";
        }

        return array_unique($domains);
    }

    private function toSlug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text) ?? '';
        $text = preg_replace('/\s+/', '-', $text) ?? '';
        $text = preg_replace('/-+/', '-', $text) ?? '';

        return trim($text, '-');
    }
}
