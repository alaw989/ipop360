<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ScrapeRestaurantSocialLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function makeScraperMock(?array $returnValue): RestaurantWebsiteScraperService
    {
        $mock = $this->createMock(RestaurantWebsiteScraperService::class);
        $mock->method('scrapeSocial')->willReturn($returnValue);

        return $mock;
    }

    public function test_scrapes_restaurants_with_no_social_links(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 0,
        ]);

        $scraperMock = $this->makeScraperMock([
            'instagram' => 'https://instagram.com/venue',
            'facebook' => 'https://facebook.com/venue',
        ]);
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 updated, 0 skipped, 0 errors.');
        $command->run();

        $restaurant->refresh();

        $this->assertSame(2, $restaurant->social_links_count);
        $this->assertDatabaseCount('restaurant_social_links', 2);
        $this->assertDatabaseHas('restaurant_social_links', [
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/venue',
        ]);
        $this->assertDatabaseHas('restaurant_social_links', [
            'restaurant_id' => $restaurant->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/venue',
        ]);
    }

    public function test_skips_restaurants_with_existing_social_links_without_force(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 3,
        ]);

        $scraperMock = $this->createMock(RestaurantWebsiteScraperService::class);
        $scraperMock->expects($this->never())->method('scrapeSocial');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants to scrape.');
        $command->run();
    }

    public function test_force_scrapes_restaurants_with_existing_social_links(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 3,
        ]);

        RestaurantSocialLink::create([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/old',
        ]);

        $scraperMock = $this->makeScraperMock([
            'twitter' => 'https://twitter.com/venue',
        ]);
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social', ['--force' => true]);
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 updated, 0 skipped, 0 errors.');
        $command->run();

        $restaurant->refresh();

        $this->assertSame(1, $restaurant->social_links_count);
        $this->assertDatabaseCount('restaurant_social_links', 1);
        $this->assertDatabaseHas('restaurant_social_links', [
            'restaurant_id' => $restaurant->id,
            'platform' => 'twitter',
            'url' => 'https://twitter.com/venue',
        ]);
        $this->assertDatabaseMissing('restaurant_social_links', [
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
        ]);
    }

    public function test_warns_when_no_restaurants_to_scrape(): void
    {
        $scraperMock = $this->createMock(RestaurantWebsiteScraperService::class);
        $scraperMock->expects($this->never())->method('scrapeSocial');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants to scrape.');
        $command->run();
    }

    public function test_skips_restaurants_without_website_url(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => null,
            'social_links_count' => 0,
        ]);

        $scraperMock = $this->createMock(RestaurantWebsiteScraperService::class);
        $scraperMock->expects($this->never())->method('scrapeSocial');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants to scrape.');
        $command->run();
    }

    public function test_skips_inactive_restaurants(): void
    {
        Restaurant::factory()->create([
            'is_active' => false,
            'website_url' => 'https://example.com',
            'social_links_count' => 0,
        ]);

        $scraperMock = $this->createMock(RestaurantWebsiteScraperService::class);
        $scraperMock->expects($this->never())->method('scrapeSocial');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants to scrape.');
        $command->run();
    }

    public function test_handles_scraper_returning_null_as_skipped(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 0,
        ]);

        $scraperMock = $this->makeScraperMock(null);
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 0 updated, 1 skipped, 0 errors.');
        $command->run();
    }

    public function test_handles_scraper_exception_as_error(): void
    {
        Log::shouldReceive('channel')->with('enrichment')->andReturnSelf();
        Log::shouldReceive('warning')->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 0,
        ]);

        $scraperMock = $this->createMock(RestaurantWebsiteScraperService::class);
        $scraperMock->method('scrapeSocial')->willThrowException(new \RuntimeException('timeout'));
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 0 updated, 0 skipped, 1 errors.');
        $command->run();
    }

    public function test_replaces_existing_social_links_on_new_scrape(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 2,
        ]);

        RestaurantSocialLink::create([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/old',
        ]);
        RestaurantSocialLink::create([
            'restaurant_id' => $restaurant->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/old',
        ]);

        $scraperMock = $this->makeScraperMock([
            'twitter' => 'https://twitter.com/venue',
        ]);
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social', ['--force' => true]);
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 updated, 0 skipped, 0 errors.');
        $command->run();

        $restaurant->refresh();

        $this->assertSame(1, $restaurant->social_links_count);
        $this->assertDatabaseCount('restaurant_social_links', 1);
        $this->assertDatabaseHas('restaurant_social_links', [
            'restaurant_id' => $restaurant->id,
            'platform' => 'twitter',
        ]);
        $this->assertDatabaseMissing('restaurant_social_links', ['platform' => 'instagram']);
        $this->assertDatabaseMissing('restaurant_social_links', ['platform' => 'facebook']);
    }

    public function test_scrapes_empty_website_url_restaurant_as_zero_match(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => '',
            'social_links_count' => 0,
        ]);

        $scraperMock = $this->createMock(RestaurantWebsiteScraperService::class);
        $scraperMock->expects($this->never())->method('scrapeSocial');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants to scrape.');
        $command->run();
    }

    public function test_processes_multiple_restaurants_in_batches(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Restaurant::factory()->create([
                'is_active' => true,
                'website_url' => "https://example{$i}.com",
                'social_links_count' => 0,
            ]);
        }

        $scraperMock = $this->makeScraperMock(['instagram' => 'https://instagram.com/venue']);
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraperMock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:scrape-social');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 3 updated, 0 skipped, 0 errors.');
        $command->run();

        $this->assertDatabaseCount('restaurant_social_links', 3);
    }
}
