<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Contract for the backfill-websites menu/hours scrape phase: restaurants that
 * have a website_url but are missing menu_url (92% of the corpus) get their
 * menu URL + opening hours scraped from their own site and persisted. The
 * scrape is cached per-domain (7d TTL) so repeated daily runs are cheap, and
 * existing values are never clobbered (fill-empty only).
 */
class BackfillWebsitesMenuScrapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{opening_hours: mixed, menu_url: string|null, photo_url: string|null, photos: string[]}|null  $returnValue
     */
    private function scraperMock(?array $returnValue): RestaurantWebsiteScraperService
    {
        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('scrape')->andReturn($returnValue);

        return $scraper;
    }

    public function test_scrapes_and_persists_menu_url_and_opening_hours(): void
    {
        Restaurant::factory()->create([
            'name' => 'Menu Eatery',
            'website_url' => 'https://menueatery.example',
            'menu_url' => null,
            'opening_hours' => null,
            'social_links_count' => 1,
        ]);

        $this->app->instance(RestaurantWebsiteScraperService::class, $this->scraperMock([
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'menu_url' => 'https://menueatery.example/menu',
            'photo_url' => null,
            'photos' => [],
        ]));

        $this->artisan('restaurants:backfill-websites');

        $restaurant = Restaurant::where('name', 'Menu Eatery')->firstOrFail();
        $this->assertSame('https://menueatery.example/menu', $restaurant->menu_url);
        $this->assertSame('Mo-Su 11:00-21:00', $restaurant->opening_hours);
    }

    public function test_does_not_overwrite_existing_menu_url_or_hours(): void
    {
        Restaurant::factory()->create([
            'name' => 'Keeps Menu',
            'website_url' => 'https://keeps.example',
            'menu_url' => 'https://keeps.example/menu-old',
            'opening_hours' => 'Mo-Fr 09:00-17:00',
            'social_links_count' => 1,
        ]);

        $this->app->instance(RestaurantWebsiteScraperService::class, $this->scraperMock([
            'opening_hours' => 'Sa-Su 10:00-18:00',
            'menu_url' => 'https://keeps.example/menu-new',
            'photo_url' => null,
            'photos' => [],
        ]));

        $this->artisan('restaurants:backfill-websites');

        $restaurant = Restaurant::where('name', 'Keeps Menu')->firstOrFail();
        $this->assertSame('https://keeps.example/menu-old', $restaurant->menu_url);
        $this->assertSame('Mo-Fr 09:00-17:00', $restaurant->opening_hours);
    }

    public function test_dry_run_reports_but_does_not_persist(): void
    {
        Restaurant::factory()->create([
            'name' => 'Dry Menu',
            'website_url' => 'https://dry.example',
            'menu_url' => null,
            'opening_hours' => null,
            'social_links_count' => 1,
        ]);

        $this->app->instance(RestaurantWebsiteScraperService::class, $this->scraperMock([
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'menu_url' => 'https://dry.example/menu',
            'photo_url' => null,
            'photos' => [],
        ]));

        $this->artisan('restaurants:backfill-websites', ['--dry-run' => true]);

        $restaurant = Restaurant::where('name', 'Dry Menu')->firstOrFail();
        $this->assertNull($restaurant->menu_url);
        $this->assertNull($restaurant->opening_hours);
    }

    public function test_scrapes_opening_hours_when_menu_url_already_present(): void
    {
        Restaurant::factory()->create([
            'name' => 'Has Menu No Hours',
            'website_url' => 'https://hasmenunohours.example',
            'menu_url' => 'https://hasmenunohours.example/menu',
            'opening_hours' => null,
            'social_links_count' => 1,
        ]);

        $this->app->instance(RestaurantWebsiteScraperService::class, $this->scraperMock([
            'opening_hours' => 'Mo-Sa 10:00-20:00',
            'menu_url' => 'https://hasmenunohours.example/menu-new',
            'photo_url' => null,
            'photos' => [],
        ]));

        $this->artisan('restaurants:backfill-websites');

        $restaurant = Restaurant::where('name', 'Has Menu No Hours')->firstOrFail();
        $this->assertSame('https://hasmenunohours.example/menu', $restaurant->menu_url);
        $this->assertSame('Mo-Sa 10:00-20:00', $restaurant->opening_hours);
    }

    public function test_restaurant_with_both_fields_set_is_not_rescraped(): void
    {
        Restaurant::factory()->create([
            'name' => 'Complete Row',
            'website_url' => 'https://complete.example',
            'menu_url' => 'https://complete.example/menu',
            'opening_hours' => 'Mo-Fr 09:00-17:00',
            'social_links_count' => 1,
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('scrape')->never();
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-websites', ['--skip-cache' => true, '--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Complete Row')->firstOrFail();
        $this->assertSame('https://complete.example/menu', $restaurant->menu_url);
        $this->assertSame('Mo-Fr 09:00-17:00', $restaurant->opening_hours);
    }

    public function test_restaurant_without_website_url_is_skipped(): void
    {
        Restaurant::factory()->create([
            'name' => 'No Site',
            'website_url' => null,
            'menu_url' => null,
            'opening_hours' => null,
            'social_links_count' => 1,
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('scrape')->never();
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-websites', ['--skip-cache' => true, '--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'No Site')->firstOrFail();
        $this->assertNull($restaurant->menu_url);
        $this->assertNull($restaurant->opening_hours);
    }
}
