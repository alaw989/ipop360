<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;

class ScrapeRestaurantSocialLinks extends Command
{
    protected $signature = 'restaurants:scrape-social {--force : Re-scrape all, even if already scraped}';

    protected $description = 'Scrape restaurant websites for social media links';

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
        $query = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '');

        if (! $this->option('force')) {
            $query->where('social_links_count', 0);
        }

        $total = $query->count();

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

        $query->chunkById(50, function ($restaurants) use ($scraper, &$scraped, &$skipped, &$errors, $bar) {
            foreach ($restaurants as $restaurant) {
                try {
                    $links = $scraper->scrapeSocial($restaurant->website_url);

                    if ($links !== null) {
                        $restaurant->socialLinks()->delete();

                        foreach ($links as $platform => $url) {
                            $restaurant->socialLinks()->create([
                                'platform' => $platform,
                                'url' => $url,
                            ]);
                        }

                        $restaurant->update(['social_links_count' => count($links)]);
                        $scraped++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$scraped} updated, {$skipped} skipped, {$errors} errors.");

        return self::SUCCESS;
    }
}
