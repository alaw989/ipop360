<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Contract for the photo-backfill follow-up (verified live after PR #105):
 *  1. APPLY mode must log each found photo to the enrichment channel (the
 *     command was silent in apply mode — only dry-run logged — so scheduled
 *     runs produced no observable record of what was found).
 *  2. The scheduled run must top up gallery arrays (`--min-photos`) so rows
 *     that already have a primary photo also accumulate a multi-photo gallery
 *     (live gallery coverage was only 4.2%).
 *  3. The daily limit should be raised (100 → 200): 83% of live hits came from
 *     free-first sources, so Google CSE (~100 req/day) is barely touched.
 */
class PhotoBackfillImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
    }

    public function test_apply_mode_logs_found_photo_to_enrichment_channel(): void
    {
        Restaurant::factory()->create([
            'name' => 'Test Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => 'https://eatery.example',
            'photo_url' => null,
            'photos' => [],
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andReturn('https://upload.wikimedia.org/x/photo.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        Log::shouldReceive('channel')->with('enrichment')->andReturnSelf();
        Log::shouldReceive('info')->withArgs(fn ($message, $context) => str_contains($message, 'Photo backfill found photo')
            && isset($context['photo_url'])
            && str_contains($context['photo_url'], 'upload.wikimedia.org'))->once();

        $this->artisan('restaurants:backfill-photos', ['--apply' => true]);
    }

    public function test_min_photos_tops_up_gallery_on_row_with_primary_photo(): void
    {
        Restaurant::factory()->create([
            'name' => 'Gallery Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => 'https://gallery.example',
            'photo_url' => 'https://cdn.example/existing.jpg',
            'photos' => ['https://cdn.example/existing.jpg'],
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andReturn('https://cdn.example/second.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true, '--min-photos' => 2]);

        $fresh = Restaurant::where('name', 'Gallery Eatery')->firstOrFail();
        $this->assertIsArray($fresh->photos);
        $this->assertCount(2, $fresh->photos, 'gallery must be topped up to 2');
        $this->assertContains('https://cdn.example/second.jpg', $fresh->photos);
    }

    public function test_min_photos_without_existing_gallery_still_works(): void
    {
        Restaurant::factory()->create([
            'name' => 'No Gallery Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => 'https://nogallery.example',
            'photo_url' => 'https://cdn.example/primary.jpg',
            'photos' => [],
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andReturn('https://cdn.example/primary.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true, '--min-photos' => 2]);

        $fresh = Restaurant::where('name', 'No Gallery Eatery')->firstOrFail();
        $this->assertIsArray($fresh->photos);
        $this->assertCount(1, $fresh->photos, 'primary photo seeded into gallery, deduped');
    }

    public function test_scheduled_backfill_uses_min_photos_and_raises_limit(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? $event->description ?? '', 'restaurants:backfill-photos')
                && ! str_contains($event->command ?? '', '--verify'));

        $this->assertCount(1, $events, 'exactly one daily backfill-photos event (the weekly --verify sweep is separate)');
        $command = $events->first()->command ?? '';

        $this->assertStringContainsString('--min-photos', $command, 'scheduled run must top up gallery arrays');
        $this->assertMatchesRegularExpression('/--limit=(\d+)/', $command, $command);
        preg_match('/--limit=(\d+)/', $command, $m);
        $limit = $m[1] ?? null;
        $this->assertNotNull($limit, 'limit flag must carry a numeric value');
        $this->assertGreaterThanOrEqual(200, (int) $limit, 'daily limit should be raised to at least 200');
    }
}
