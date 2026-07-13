<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

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

        $newWebsites = $this->countNewWebsites();
        if ($newWebsites > 0 && ! $dryRun) {
            $this->info("Scraping social links for {$newWebsites} new website(s)...");
            $this->scrapeSocialLinks($scraper);
        }

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
                    $name = $this->normalize($venue['title'] ?? $venue['name'] ?? '');
                    if ($name !== '') {
                        $nameIndex[$name] = $website;
                    }
                    $phone = $venue['phone'] ?? null;
                    if (! empty($phone)) {
                        $digits = substr(preg_replace('/\D+/', '', $phone), -10);
                        if (strlen($digits) === 10) {
                            $phoneIndex[$digits] = $website;
                        }
                    }
                }
            }
        }

        $this->line('  Name index: '.count($nameIndex).', Phone index: '.count($phoneIndex));

        $missing = $this->missingRestaurants(0)->get();
        $hits = 0;

        foreach ($missing as $restaurant) {
            $website = null;

            // Try name match
            $nameKey = $this->normalize($restaurant->name);
            if (isset($nameIndex[$nameKey])) {
                $website = $nameIndex[$nameKey];
            }

            // Try phone match
            if ($website === null) {
                $phoneDigits = substr(preg_replace('/\D+/', '', $restaurant->phone ?? ''), -10);
                if (strlen($phoneDigits) === 10 && isset($phoneIndex[$phoneDigits])) {
                    $website = $phoneIndex[$phoneDigits];
                }
            }

            if ($website !== null) {
                if (! $dryRun) {
                    $restaurant->update(['website_url' => $website]);
                }
                $hits++;
                $this->found++;
            }
        }

        $this->line("  Cache matched {$hits} restaurant(s).");
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
            $url = $this->searchDuckDuckGo($restaurant->name, $restaurant->city, $restaurant->state);

            if ($url !== null) {
                if (! $dryRun) {
                    $restaurant->update(['website_url' => $url]);
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

    private function searchDuckDuckGo(string $name, ?string $city, ?string $state): ?string
    {
        $query = trim("{$name} {$city} {$state} official website");
        $query = substr($query, 0, 200);

        try {
            $response = Http::timeout(8)
                ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->withOptions(['allow_redirects' => false])
                ->get('https://html.duckduckgo.com/html/', ['q' => $query]);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Parse DDG HTML results: <a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=URL_ENCODED_URL">
            preg_match_all('/uddg=([^&]+)/i', $html, $matches);

            if (empty($matches[1])) {
                return null;
            }

            $skipPatterns = [
                '/facebook\.com/i', '/instagram\.com/i', '/twitter\.com/i', '/x\.com/i',
                '/yelp\.com/i', '/tripadvisor\.com/i', '/youtube\.com/i', '/tiktok\.com/i',
                '/linkedin\.com/i', '/pinterest\.com/i', '/duckduckgo\.com/i',
                '/google\.com/i', '/bing\.com/i', '/wikipedia\.org/i',
                '/menupix\.com/i', '/allmenus\.com/i', '/restaurantguru\.com/i',
                '/opentable\.com/i',
            ];

            foreach ($matches[1] as $encoded) {
                $url = urldecode($encoded);

                if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                    continue;
                }

                $skip = false;
                foreach ($skipPatterns as $pattern) {
                    if (preg_match($pattern, $url)) {
                        $skip = true;
                        break;
                    }
                }

                if (! $skip) {
                    return $url;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalize(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/i', '', $name))));
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
                    // Individual failure — skip
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }
}
