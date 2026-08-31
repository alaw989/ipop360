<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Tests\TestCase;

/**
 * Regression test for the P0 production bug found in the 2026-08-31 scheduled-
 * job audit: scrapeSocialLinks() had no cap, so the backlog of restaurants
 * with a website but no social links (grown to 17,639 rows live) turned a
 * single daily run into a 15-18 hour hang, blowing the 240-minute
 * withoutOverlapping mutex. This locks in the SOCIAL_SCRAPE_DAILY_LIMIT cap.
 */
class BackfillWebsitesSocialScrapeCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_scrape_phase_is_capped_at_daily_limit(): void
    {
        $cap = 400;
        $backlog = $cap + 50;

        Restaurant::factory()->count($backlog)->create([
            'website_url' => 'https://example.com',
            'social_links_count' => 0,
            // Filled so the cache/menu-scrape phases have nothing to do and
            // don't add noise to the scrapeSocial() call count this test asserts.
            'menu_url' => 'https://example.com/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'description' => 'Already has a description that is long enough.',
            'price_range' => '$$',
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('scrapeSocial')->times($cap)->andReturn(null);
        $scraper->shouldReceive('scrape')->never();
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:backfill-websites', [
            '--skip-cache' => true,
            '--skip-search' => true,
        ]);
        $command->run();
    }
}
