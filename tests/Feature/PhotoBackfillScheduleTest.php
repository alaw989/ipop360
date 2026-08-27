<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * Contract for decoupling photo retrieval from the SerpApi-bound enrichment:
 *
 *  1. `restaurants:backfill-photos` must be SCHEDULED (it was never wired into
 *     routes/console.php — image retrieval only ran as a side-effect of the
 *     daily throttled enrichment, which zeroed out on Aug 14 when SerpApi
 *     exhausted, leaving 38% of the corpus photo-less).
 *  2. The scheduled run must stay BOUNDED (a --limit cap) so Google Custom
 *     Search (~100 req/day free) is only touched as the last-resort source.
 *  3. The dead `enrich-loop` supervisor program (script deleted in 79a3915,
 *     config still deployed → FATAL on the droplet) must no longer be wired in.
 */
class PhotoBackfillScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
    }

    public function test_backfill_photos_is_scheduled_daily(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        // Exclude the weekly --verify sweep: it also references backfill-photos.
        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? $event->description ?? '', 'restaurants:backfill-photos')
                && ! str_contains($event->command ?? '', '--verify'));

        $this->assertCount(1, $events, 'exactly one daily backfill-photos run (the weekly --verify sweep is separate)');

        $event = $events->first();
        $this->assertStringContainsString('backfill-photos', $event->command ?? '', 'Scheduled event must reference the backfill-photos command');
    }

    public function test_scheduled_backfill_photos_is_bounded_by_a_limit(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? $event->description ?? '', 'restaurants:backfill-photos'));

        $this->assertNotEmpty($events, 'expected a scheduled backfill-photos event');
        $command = $events->first()->command ?? '';
        $this->assertStringContainsString('--limit', $command, 'The scheduled backfill-photos run must be capped with --limit so the free-source budget stays bounded');
    }

    public function test_google_custom_search_is_the_last_resort_image_source(): void
    {
        // Partial mock: keep the REAL searchImageForRestaurant ordering logic
        // (reached via the searchAnyImage thin wrapper), mock only the outbound
        // collaborators it calls. The ~100/day Google CSE quota source must be
        // reached ONLY after the free unlimited sources return nothing.
        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class)->makePartial();
        $scraper->shouldReceive('scrapePhotos')->once()->andReturn(null);
        $scraper->shouldReceive('searchWikimediaCommons')->once()->andReturn(null);
        $scraper->shouldReceive('searchWikipediaImage')->once()->andReturn(null);
        $scraper->shouldReceive('searchGoogleImages')->once()->andReturn('https://cdn.example/cse-photo.jpg');

        $photo = $scraper->searchAnyImage('Test Eatery', 'Austin', 'TX', 'https://eatery.example');
        $this->assertSame('https://cdn.example/cse-photo.jpg', $photo);
    }

    public function test_backfill_photos_command_still_applies_and_persists(): void
    {
        $r = Restaurant::factory()->create([
            'name' => 'Test Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => 'https://eatery.example',
            'photo_url' => null,
            'photos' => [],
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')->andReturn(['url' => 'https://cdn.example/photo.jpg', 'source' => 'website']);
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('https://cdn.example/photo.jpg', $fresh->photo_url);
    }
}
