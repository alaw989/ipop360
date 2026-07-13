<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillRestaurantWebsites extends Command
{
    protected $signature = 'restaurants:backfill-websites
        {--dry-run : Show what would be updated without making changes}
        {--limit=0 : Max restaurants to process (0 = unlimited)}';

    protected $description = 'Backfill missing website URLs from enriched cache, then scrape social links';

    private int $found = 0;

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
        $dryRun = $this->option('dry-run');

        $this->line('Building cache index from SerpApi data...');
        [$nameIndex, $phoneIndex] = $this->buildCacheIndex();

        $this->line('  Name index: '.count($nameIndex).' entries');
        $this->line('  Phone index: '.count($phoneIndex).' entries');

        // Phase A: Match by name (exact)
        $this->info('Phase A: Matching by name...');
        $this->matchFromIndex($nameIndex, 'name', $dryRun);

        // Phase B: Match remaining by phone
        $remaining = $this->countMissing();
        if ($remaining > 0) {
            $this->info("Phase B: Matching {$remaining} restaurant(s) by phone...");
            $this->matchFromIndex($phoneIndex, 'phone', $dryRun);
        }

        // Phase C: Scrape social links for newly found websites
        $newWebsites = $this->countNewWebsites($dryRun);
        if ($newWebsites > 0 && ! $dryRun) {
            $this->info("Phase C: Scraping social links for {$newWebsites} new website(s)...");
            $this->scrapeSocialLinks($scraper);
        }

        $this->newLine();
        $this->info("Done. {$this->found} website(s) found.");

        $left = $this->countMissing();
        if ($left > 0) {
            $this->warn("  {$left} restaurant(s) still missing website URLs (not found in cache).");
        }

        return self::SUCCESS;
    }

    private function buildCacheIndex(): array
    {
        $nameIndex = [];
        $phoneIndex = [];

        $cacheEntries = ExternalApiCache::where('source', 'serpapi')->get();

        foreach ($cacheEntries as $entry) {
            $venues = $entry->data;

            foreach ($venues as $venue) {
                $website = $venue['website'] ?? $venue['website_url'] ?? null;
                if (empty($website)) {
                    continue;
                }

                $nameKey = $this->normalizeName($venue['title'] ?? $venue['name'] ?? '');
                if ($nameKey !== '') {
                    $nameIndex[$nameKey] = $venue + ['_website' => $website];
                }

                $phone = $venue['phone'] ?? null;
                if (! empty($phone)) {
                    $phoneDigits = substr(preg_replace('/\D+/', '', $phone), -10);
                    if (strlen($phoneDigits) === 10) {
                        $phoneIndex[$phoneDigits] = $venue + ['_website' => $website];
                    }
                }
            }
        }

        return [$nameIndex, $phoneIndex];
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    private function matchFromIndex(array $index, string $matcher, bool $dryRun): void
    {
        $missing = $this->missingRestaurants(0)->get();

        /** @var Restaurant $restaurant */
        foreach ($missing as $restaurant) {
            if ($matcher === 'name') {
                $key = $this->normalizeName($restaurant->name);
                $entry = $index[$key] ?? null;
            } else {
                $entry = $this->findByPhone($restaurant, $index);
            }

            if ($entry !== null) {
                $website = $entry['_website'] ?? null;
                if ($website !== null) {
                    if (! $dryRun) {
                        $restaurant->update(['website_url' => $website]);
                    }
                    $this->found++;
                }
            }
        }
    }

    private function findByPhone(Restaurant $restaurant, array $phoneIndex): ?array
    {
        $restaurantDigits = substr(preg_replace('/\D+/', '', $restaurant->phone ?? ''), -10);
        if (strlen($restaurantDigits) !== 10) {
            return null;
        }

        return $phoneIndex[$restaurantDigits] ?? null;
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

    private function countNewWebsites(bool $dryRun): int
    {
        if ($dryRun) {
            return 0;
        }

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
