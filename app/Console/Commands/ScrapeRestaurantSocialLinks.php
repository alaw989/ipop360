<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeRestaurantSocialLinks extends Command
{
    protected $signature = 'restaurants:scrape-social {--force : Re-scrape all, even if already scraped}
                            {--limit=0 : Max restaurants to scrape (0 = unlimited)}';

    protected $description = 'Scrape restaurant websites for social media links';

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
        $query = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '');

        $totalWithWebsites = $query->count();
        $force = $this->option('force');

        if (! $force) {
            $query->where('social_links_count', 0);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();

        Log::channel('enrichment')->info('Starting social scrape', [
            'total_with_websites' => $totalWithWebsites,
            'total_to_scrape' => $total,
            'force' => $force,
        ]);

        if ($total === 0) {
            $this->warn('No restaurants to scrape.');

            return self::SUCCESS;
        }

        $this->info("Scraping social links for {$total} restaurants...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $scraped = 0;
        $skipped = 0;
        $errors = 0;
        $scrapedRestaurants = [];

        $query->chunkById(50, function ($restaurants) use ($scraper, &$scraped, &$skipped, &$errors, &$scrapedRestaurants, $bar) {
            foreach ($restaurants as $restaurant) {
                $startTime = microtime(true);

                try {
                    $links = $scraper->scrapeSocial((string) $restaurant->website_url);

                    $elapsed = (microtime(true) - $startTime) * 1000;

                    if ($links !== null) {
                        $restaurant->socialLinks()->delete();

                        $platforms = [];
                        foreach ($links as $platform => $url) {
                            $restaurant->socialLinks()->create([
                                'platform' => $platform,
                                'url' => $url,
                            ]);
                            $platforms[] = $platform;
                        }

                        $restaurant->update(['social_links_count' => count($links)]);
                        $scraped++;

                        $scrapedRestaurants[] = [
                            'id' => $restaurant->id,
                            'name' => $restaurant->name,
                            'platforms' => implode(',', $platforms),
                        ];

                        Log::channel('enrichment')->info('Social scrape found links', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'website_url' => $restaurant->website_url,
                            'platforms' => $platforms,
                            'elapsed_ms' => round($elapsed, 1),
                        ]);
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::channel('enrichment')->warning('Social scrape error', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $restaurant->website_url,
                        'error' => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$scraped} updated, {$skipped} skipped, {$errors} errors.");

        Log::channel('enrichment')->info('Social scrape completed', [
            'updated' => $scraped,
            'skipped' => $skipped,
            'errors' => $errors,
            'total_processed' => $total,
            'restaurants_with_links' => $scrapedRestaurants,
        ]);

        return self::SUCCESS;
    }
}
