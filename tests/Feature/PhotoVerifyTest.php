<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Contract for the photo-verify mode on restaurants:backfill-photos.
 *
 * Live audit: gps-cs-s Google CDN photo URLs (from SerpApi google_maps results)
 * expire opaquely — no expiry token in the URL, no column, no header. Google
 * revokes them silently over time (~1-month observed). 1497/5175 photo-having
 * rows carry them; June-era ones already 403. Nothing re-checks the URL, so dead
 * images persist forever (the trending section looks fully broken because it
 * surfaces top-scored = SerpApi-enriched = all-gps-cs-s rows).
 *
 * The only detection is an HTTP check. --verify mode must:
 *  1. HTTP-check each row's photo_url (HEAD→GET fallback), keep valid photos.
 *  2. Re-source confirmed-dead ones via searchAnyImage (free chain).
 *  3. Dedupe dead URLs out of the photos gallery.
 *  4. Prioritize gps-cs-s rows first.
 *  5. Be scheduled WEEKLY (matches ~1-month decay), bounded by --limit.
 */
class PhotoVerifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
    }

    public function test_verify_keeps_valid_photo_and_does_not_replace(): void
    {
        $r = Restaurant::factory()->create([
            'name' => 'Valid Eatery',
            'photo_url' => 'https://upload.wikimedia.org/valid.jpg',
            'photos' => ['https://upload.wikimedia.org/valid.jpg'],
        ]);

        Http::fake([
            'upload.wikimedia.org/*' => Http::response('ok', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->artisan('restaurants:backfill-photos', ['--verify' => true, '--apply' => true]);

        $this->assertSame('https://upload.wikimedia.org/valid.jpg', $r->fresh()->photo_url, 'valid photo must be kept untouched');
    }

    public function test_verify_resources_dead_photo_from_free_chain(): void
    {
        $r = Restaurant::factory()->create([
            'name' => 'Dead Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => 'https://eatery.example',
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/DEADTOKEN=w400-h300-c-no',
            'photos' => ['https://lh3.googleusercontent.com/gps-cs-s/DEADTOKEN=w400-h300-c-no'],
        ]);

        Http::fake([
            'lh3.googleusercontent.com/*' => Http::response('Forbidden', 403),
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andReturn('https://upload.wikimedia.org/fresh.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--verify' => true, '--apply' => true]);

        $fresh = $r->fresh();
        $this->assertSame('https://upload.wikimedia.org/fresh.jpg', $fresh->photo_url, 'dead photo must be re-sourced from the free chain');
        $this->assertIsArray($fresh->photos);
        $this->assertContains('https://upload.wikimedia.org/fresh.jpg', $fresh->photos);
        $this->assertNotContains('https://lh3.googleusercontent.com/gps-cs-s/DEADTOKEN=w400-h300-c-no', $fresh->photos, 'dead URL must be removed from the gallery');
    }

    public function test_verify_dry_run_does_not_persist(): void
    {
        $r = Restaurant::factory()->create([
            'name' => 'Dry Eatery',
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/DEADTOKEN=w400-h300-c-no',
        ]);

        Http::fake([
            'lh3.googleusercontent.com/*' => Http::response('Forbidden', 403),
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchAnyImage')->once()->andReturn('https://upload.wikimedia.org/fresh.jpg');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        $this->artisan('restaurants:backfill-photos', ['--verify' => true]);

        $this->assertSame(
            'https://lh3.googleusercontent.com/gps-cs-s/DEADTOKEN=w400-h300-c-no',
            $r->fresh()->photo_url,
            'dry-run verify must not persist the re-sourced photo'
        );
    }

    public function test_verify_prioritizes_gps_cs_s_rows(): void
    {
        $gps = Restaurant::factory()->create([
            'name' => 'GPS Eatery',
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/TOKEN1=w400-h300-c-no',
        ]);
        Restaurant::factory()->create([
            'name' => 'Other Eatery',
            'photo_url' => 'https://venue.example/photo.jpg',
        ]);

        Http::fake([
            '*' => Http::response('ok', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        // No scraper needed: both URLs verify OK. Assert the gps-cs-s row is
        // checked before the venue-owned row by capturing the request order.
        $this->artisan('restaurants:backfill-photos', ['--verify' => true, '--apply' => true, '--limit' => 1]);

        Http::assertSentInOrder([
            fn ($request) => str_contains($request->url(), 'gps-cs-s'),
        ]);
    }

    public function test_verify_transient_403_is_retried_not_churned(): void
    {
        $r = Restaurant::factory()->create([
            'name' => 'Flaky Eatery',
            'photo_url' => 'https://venue.example/flaky.jpg',
        ]);

        // First check fails, retry succeeds → valid photo kept.
        Http::fake([
            'venue.example/flaky.jpg' => Http::sequence()
                ->push('Forbidden', 403)
                ->push('ok', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->artisan('restaurants:backfill-photos', ['--verify' => true, '--apply' => true]);

        $this->assertSame('https://venue.example/flaky.jpg', $r->fresh()->photo_url, 'a photo that recovers on retry must be kept');
    }

    public function test_verify_is_scheduled_weekly(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? $event->description ?? '', '--verify'));

        $this->assertCount(1, $events, 'exactly one weekly verify sweep must be scheduled');
        $this->assertStringContainsString('--verify', $events->first()->command ?? '', 'scheduled sweep must run in verify mode');
        $this->assertStringContainsString('--apply', $events->first()->command ?? '');
        $this->assertStringContainsString('--limit', $events->first()->command ?? '');
    }
}
