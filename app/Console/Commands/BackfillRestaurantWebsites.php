<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

class BackfillRestaurantWebsites extends Command
{
    protected $signature = 'restaurants:backfill-websites
        {--dry-run : Show what would be updated without making changes}
        {--limit=0 : Max restaurants to process (0 = unlimited)}';

    protected $description = 'Backfill missing website URLs from cache and web search, then scrape social links';

    private int $found = 0;

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Phase A: Match from enrichment cache
        $this->info('Phase A: Checking enrichment cache...');
        $this->matchFromCache($dryRun);

        // Phase B: Web search for remaining
        $remaining = $this->missingRestaurants($limit)->get();

        if ($remaining->isEmpty()) {
            $this->info('All restaurants have website URLs.');

            return self::SUCCESS;
        }

        $this->info('Phase B: Web searching for '.$remaining->count().' restaurants...');
        $bar = $this->output->createProgressBar($remaining->count());
        $bar->start();

        foreach ($remaining as $restaurant) {
            try {
                $url = $this->searchWebsite($restaurant->name, $restaurant->city, $restaurant->state);

                if ($url !== null) {
                    if (! $dryRun) {
                        $restaurant->update(['website_url' => $url]);

                        $links = $scraper->scrapeSocial($url);
                        if ($links !== null) {
                            $restaurant->socialLinks()->delete();
                            foreach ($links as $platform => $linkUrl) {
                                $restaurant->socialLinks()->create([
                                    'platform' => $platform,
                                    'url' => $linkUrl,
                                ]);
                            }
                            $restaurant->update(['social_links_count' => count($links)]);
                        }
                    }
                    $this->found++;
                }
            } catch (\Throwable $e) {
                $this->warn("  Error: {$restaurant->name} - {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$this->found} website(s) found.");

        return self::SUCCESS;
    }

    private function missingRestaurants(int $limit): Restaurant|Builder
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

    private function matchFromCache(bool $dryRun): void
    {
        $cacheEntries = ExternalApiCache::where('source', 'serpapi')->get();

        // Build index of missing restaurants by name+city
        $missing = $this->missingRestaurants(0)->get();
        $index = [];
        foreach ($missing as $r) {
            $key = strtolower(trim($r->name)).'|'.strtolower(trim($r->city ?? ''));
            $index[$key] = $r;
        }

        if (empty($index)) {
            return;
        }

        $hits = 0;

        foreach ($cacheEntries as $entry) {
            $venues = json_decode($entry->data, true);
            if (! is_array($venues)) {
                continue;
            }

            foreach ($venues as $venue) {
                $websiteUrl = $venue['website_url'] ?? $venue['website'] ?? null;
                if (empty($websiteUrl)) {
                    continue;
                }

                $venueName = $venue['title'] ?? $venue['name'] ?? null;
                $venueCity = $venue['city'] ?? null;
                if (empty($venueName)) {
                    continue;
                }

                $key = strtolower(trim($venueName)).'|'.strtolower(trim($venueCity ?? ''));
                $restaurant = $index[$key] ?? null;

                if ($restaurant !== null) {
                    if (! $dryRun) {
                        $restaurant->update(['website_url' => $websiteUrl]);
                    }
                    $hits++;
                    $this->found++;
                    unset($index[$key]);
                }
            }
        }

        $this->line("  Cache matched {$hits} restaurant(s).");
    }

    private function searchWebsite(string $name, ?string $city, ?string $state): ?string
    {
        $query = trim("{$name} {$city} {$state} official website");
        $query = substr($query, 0, 200);

        try {
            $response = Http::timeout(8)
                ->withUserAgent('Mozilla/5.0 (compatible; iPop360-Bot/1.0)')
                ->get('https://lite.duckduckgo.com/lite/', ['q' => $query]);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            preg_match_all('/<a[^>]+class="result-link"[^>]*href="([^"]+)"[^>]*>/i', $html, $matches);

            if (empty($matches[1])) {
                return null;
            }

            foreach ($matches[1] as $url) {
                $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                    continue;
                }

                $host = parse_url($url, PHP_URL_HOST);
                if ($host === false || $host === null) {
                    continue;
                }

                $skipDomains = [
                    'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
                    'yelp.com', 'tripadvisor.com', 'google.com', 'youtube.com',
                    'linkedin.com', 'pinterest.com', 'tiktok.com',
                    'duckduckgo.com', 'bing.com',
                ];

                $hostLower = strtolower($host);
                foreach ($skipDomains as $skip) {
                    if ($hostLower === $skip || str_ends_with($hostLower, '.'.$skip)) {
                        continue 2;
                    }
                }

                return $url;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
