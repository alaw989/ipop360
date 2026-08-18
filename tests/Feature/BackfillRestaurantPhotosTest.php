<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\PendingCommand;
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function restaurant(array $overrides = []): Restaurant
    {
        $r = Restaurant::factory()->create(array_merge([
            'name' => 'Test Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'photo_url' => null,
            'photos' => [],
        ], $overrides));

        return Restaurant::query()->whereKey($r->getKey())->firstOrFail();
    }

    public function test_dry_run_finds_photo_without_persisting(): void
    {
        $r = $this->restaurant(['website_url' => 'https://eatery.example']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')->once()->andReturn('https://cdn.example/photo.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:backfill-photos');
        $cmd->expectsOutputToContain('DRY RUN');

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->photo_url, 'dry-run must not persist');
    }

    public function test_apply_persists_photo_and_gallery(): void
    {
        $r = $this->restaurant(['website_url' => 'https://eatery.example']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')->once()->andReturn('https://cdn.example/photo.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('https://cdn.example/photo.jpg', $fresh->photo_url);
        $this->assertIsArray($fresh->photos);
        $this->assertContains('https://cdn.example/photo.jpg', $fresh->photos);
    }

    public function test_skips_restaurants_that_already_have_photo_by_default(): void
    {
        $r = $this->restaurant(['photo_url' => 'https://cdn.example/existing.jpg']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldNotReceive('searchImageForRestaurant');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:backfill-photos');
        $cmd->expectsOutputToContain('No restaurants need photos');

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('https://cdn.example/existing.jpg', $fresh->photo_url);
    }

    public function test_handles_scraper_failure_without_aborting(): void
    {
        $this->restaurant(['website_url' => 'https://eatery.example']);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')->once()->andThrow(new \RuntimeException('boom'));
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:backfill-photos', ['--apply' => true]);
        $cmd->assertExitCode(0);
    }

    public function test_backfill_prioritizes_highest_popularity_rows_within_limit(): void
    {
        $low = $this->restaurant(['website_url' => 'https://low.example', 'popularity_score' => 0.1]);
        $mid = $this->restaurant(['website_url' => 'https://mid.example', 'popularity_score' => 0.5]);
        $high = $this->restaurant(['website_url' => 'https://high.example', 'popularity_score' => 0.9]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')->andReturnUsing(function (Restaurant $r) {
            return 'https://cdn.example/photo-'.$r->id.'.jpg';
        });
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true, '--limit' => 2]);

        $this->assertNotNull($high->fresh()?->photo_url, 'highest-popularity row must be backfilled first');
        $this->assertNotNull($mid->fresh()?->photo_url, 'second-highest-popularity row must be backfilled');
        $this->assertNull($low->fresh()?->photo_url, 'lowest-popularity row must wait for the next run when the daily budget is spent');
    }

    public function test_backfill_skips_recently_verified_dead_row_within_cooldown(): void
    {
        $r = $this->restaurant([
            'website_url' => 'https://eatery.example',
            'photo_url' => null,
            'photo_verified_at' => now(),
        ]);

        // A recently cleared (known-dead-unresolvable) row must NOT be re-sourced
        // before the cooldown elapses.
        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')->never();
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->photo_url, 'recently cleared dead row must not be re-sourced within the cooldown');
    }
}
