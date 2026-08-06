<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class BackfillRestaurantPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->create(array_merge([
            'name' => 'Test Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'photo_url' => null,
            'photos' => [],
        ], $overrides));
    }

    public function test_dry_run_finds_photo_without_persisting(): void
    {
        $r = $this->restaurant(['website_url' => 'https://eatery.example']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andReturn('https://cdn.example/photo.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos')
            ->expectsOutputToContain('DRY RUN');

        $this->assertNull($r->fresh()->photo_url, 'dry-run must not persist');
    }

    public function test_apply_persists_photo_and_gallery(): void
    {
        $r = $this->restaurant(['website_url' => 'https://eatery.example']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andReturn('https://cdn.example/photo.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertSame('https://cdn.example/photo.jpg', $fresh->photo_url);
        $this->assertContains('https://cdn.example/photo.jpg', $fresh->photos);
    }

    public function test_skips_restaurants_that_already_have_photo_by_default(): void
    {
        $r = $this->restaurant(['photo_url' => 'https://cdn.example/existing.jpg']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldNotReceive('searchAnyImage');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos')
            ->expectsOutputToContain('No restaurants need photos');

        $this->assertSame('https://cdn.example/existing.jpg', $r->fresh()->photo_url);
    }

    public function test_handles_scraper_failure_without_aborting(): void
    {
        $this->restaurant(['website_url' => 'https://eatery.example']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andThrow(new \RuntimeException('boom'));
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true])
            ->assertExitCode(0);
    }
}
