<?php

namespace Tests\Feature;

use App\Jobs\GeneratePhotoThumbnail;
use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Tests\TestCase;

/**
 * Contract for self-hosted photo copies. Google's gps-cs-s photo URLs expire
 * within weeks of SerpApi handing them out, so every photo is copied while its
 * source is fresh (queued on write, daily sweep as backstop) and the copy is
 * what gets shown — on the homepage, in live search, and on detail pages.
 */
class SelfHostedPhotoCopyTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE = 'https://lh3.googleusercontent.com/gps-cs-s/abc=w400-h300-c-no';

    private function thumbs(): PhotoThumbnailService
    {
        return $this->app->make(PhotoThumbnailService::class);
    }

    /** A row whose photo_thumb matches its current photo_url. */
    private function copied(string $photoUrl): Restaurant
    {
        $r = Restaurant::factory()->create(['photo_url' => $photoUrl]);
        Restaurant::query()->whereKey($r->id)->update(['photo_thumb' => $this->thumbs()->expectedFilename($r)]);

        return $r->refresh();
    }

    public function test_changing_photo_url_queues_a_copy(): void
    {
        Config::set('restaurant-finder.photo_thumbs.copy_on_write', true);
        Queue::fake();

        $r = Restaurant::factory()->create(['photo_url' => null]);
        Queue::assertNothingPushed();

        $r->update(['photo_url' => self::GOOGLE]);

        Queue::assertPushed(GeneratePhotoThumbnail::class, fn (GeneratePhotoThumbnail $job): bool => $job->restaurantId === $r->id);
    }

    public function test_a_new_row_with_a_photo_queues_a_copy(): void
    {
        Config::set('restaurant-finder.photo_thumbs.copy_on_write', true);
        Queue::fake();

        Restaurant::factory()->create(['photo_url' => self::GOOGLE]);

        Queue::assertPushed(GeneratePhotoThumbnail::class);
    }

    public function test_unrelated_writes_and_the_kill_switch_queue_nothing(): void
    {
        Config::set('restaurant-finder.photo_thumbs.copy_on_write', true);
        Queue::fake();
        $r = $this->copied(self::GOOGLE);
        Queue::fake();

        $r->update(['name' => 'Renamed']);
        Queue::assertNothingPushed();

        Config::set('restaurant-finder.photo_thumbs.copy_on_write', false);
        $r->update(['photo_url' => 'https://example.com/new.jpg']);
        Queue::assertNothingPushed();
    }

    public function test_a_new_photo_url_clears_the_stale_copy(): void
    {
        $r = $this->copied(self::GOOGLE);
        $this->assertNotNull($r->photo_thumb);

        $r->update(['photo_url' => 'https://example.com/new.jpg']);

        $this->assertNull($r->fresh()?->photo_thumb, 'a copy of the old photo must not be served for the new one');
    }

    public function test_job_stores_the_copy_without_touching_updated_at(): void
    {
        $r = Restaurant::factory()->create(['photo_url' => self::GOOGLE]);
        $before = $r->fresh()?->updated_at;
        $this->travel(5)->minutes();

        $service = Mockery::mock(PhotoThumbnailService::class)->makePartial();
        $service->shouldReceive('generate')->once()->andReturn('copy.webp');

        (new GeneratePhotoThumbnail($r->id))->handle($service);

        $fresh = $r->fresh();
        $this->assertInstanceOf(Restaurant::class, $fresh);
        $this->assertSame('copy.webp', $fresh->photo_thumb);
        $this->assertEquals($before, $fresh->updated_at);
    }

    public function test_job_releases_for_a_retry_when_the_download_fails(): void
    {
        $r = Restaurant::factory()->create(['photo_url' => self::GOOGLE]);

        $service = Mockery::mock(PhotoThumbnailService::class)->makePartial();
        $service->shouldReceive('generate')->once()->andReturn(null);

        $job = (new GeneratePhotoThumbnail($r->id))->withFakeQueueInteractions();
        $job->handle($service);

        $job->assertReleased(60);
        $this->assertNull($r->fresh()?->photo_thumb);
    }

    public function test_job_skips_a_row_that_already_has_a_matching_copy(): void
    {
        Storage::fake('local');
        $r = $this->copied(self::GOOGLE);
        Storage::disk('local')->put($this->thumbs()->storagePath((string) $r->photo_thumb), 'webp');

        $service = Mockery::mock(PhotoThumbnailService::class)->makePartial();
        $service->shouldNotReceive('generate');

        (new GeneratePhotoThumbnail($r->id))->handle($service);
    }

    public function test_live_results_get_the_copy_url_only_when_it_matches(): void
    {
        $copied = $this->copied(self::GOOGLE);
        $stale = $this->copied('https://example.com/old.jpg');
        Restaurant::query()->whereKey($stale->id)->update(['photo_url' => 'https://example.com/new.jpg']);
        $bare = Restaurant::factory()->create(['photo_url' => 'https://example.com/none.jpg']);

        $rows = $this->thumbs()->attachPublicUrls([
            ['id' => $copied->id, 'photo_url' => self::GOOGLE],
            ['id' => $stale->id, 'photo_url' => 'https://example.com/new.jpg'],
            ['id' => $bare->id, 'photo_url' => 'https://example.com/none.jpg'],
            ['id' => 'live-osm-123', 'photo_url' => null],
        ]);

        $this->assertSame('/thumbs/'.$copied->photo_thumb, $rows[0]['photo_thumb_url']);
        $this->assertArrayNotHasKey('photo_thumb_url', $rows[1]);
        $this->assertArrayNotHasKey('photo_thumb_url', $rows[2]);
        $this->assertArrayNotHasKey('photo_thumb_url', $rows[3]);
    }

    public function test_prune_deletes_only_copies_that_no_longer_match(): void
    {
        Storage::fake('local');
        $kept = $this->copied(self::GOOGLE);
        $keptPath = $this->thumbs()->storagePath((string) $kept->photo_thumb);
        $orphanPath = $this->thumbs()->storagePath($kept->id.'-0000000000.webp');
        $gonePath = $this->thumbs()->storagePath('999999-0000000000.webp');
        foreach ([$keptPath, $orphanPath, $gonePath] as $path) {
            Storage::disk('local')->put($path, 'webp');
        }

        /** @var PendingCommand $dry */
        $dry = $this->artisan('restaurants:photo-thumbnails', ['--prune' => true]);
        $dry->expectsOutputToContain('Would delete orphaned thumbnails: 2')->assertSuccessful()->run();
        Storage::disk('local')->assertExists($orphanPath);

        /** @var PendingCommand $apply */
        $apply = $this->artisan('restaurants:photo-thumbnails', ['--prune' => true, '--apply' => true]);
        $apply->expectsOutputToContain('Deleted orphaned thumbnails: 2')->assertSuccessful()->run();

        Storage::disk('local')->assertExists($keptPath);
        Storage::disk('local')->assertMissing($orphanPath);
        Storage::disk('local')->assertMissing($gonePath);
    }

    public function test_sweep_copies_expiring_google_photos_first(): void
    {
        Restaurant::factory()->create(['photo_url' => 'https://example.com/popular.jpg', 'popularity_score' => 0.99]);
        $google = Restaurant::factory()->create(['photo_url' => self::GOOGLE, 'popularity_score' => 0.01]);

        $service = Mockery::mock(PhotoThumbnailService::class);
        $service->shouldReceive('hasThumb')->andReturn(false);
        $service->shouldReceive('generate')->once()
            ->with(Mockery::on(fn (Restaurant $r): bool => $r->id === $google->id))
            ->andReturn('copy.webp');
        $this->app->instance(PhotoThumbnailService::class, $service);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-thumbnails', ['--apply' => true, '--limit' => 1]);
        $command->assertSuccessful()->run();
    }

    public function test_verify_skips_rows_with_a_self_hosted_copy(): void
    {
        $this->copied(self::GOOGLE);
        Restaurant::factory()->create(['photo_url' => 'https://upload.wikimedia.org/alive.jpg']);
        Http::fake([
            'lh3.googleusercontent.com/*' => Http::response('Forbidden', 403),
            'upload.wikimedia.org/*' => Http::response('img', 200),
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldNotReceive('searchImageForRestaurant');
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:backfill-photos', ['--verify' => true, '--apply' => true]);
        $command->expectsOutputToContain('Skipped (self-hosted copy): 1')->assertSuccessful()->run();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'googleusercontent.com'));
    }

    public function test_verify_rejects_the_same_dead_url_and_stamps_the_row(): void
    {
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
        $r = Restaurant::factory()->create(['photo_url' => 'https://venue.example/og.jpg', 'photos' => []]);
        Http::fake(['*' => Http::response('Not found', 404)]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')->once()
            ->andReturn(['url' => 'https://venue.example/og.jpg', 'source' => 'website']);
        $this->app->instance(RestaurantWebsiteScraperService::class, $scraper);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:backfill-photos', ['--verify' => true, '--apply' => true]);
        $command->expectsOutputToContain('Rejected candidates (same URL or dead): 1')->assertSuccessful()->run();

        $fresh = $r->fresh();
        $this->assertNull($fresh?->photo_url);
        $this->assertNotNull($fresh?->photo_verified_at, 'an unfixable row must be stamped so it is not re-checked weekly');
    }

    public function test_google_custom_search_stops_at_the_daily_cap(): void
    {
        Config::set('services.google_custom_search.api_key', 'key');
        Config::set('services.google_custom_search.cx', 'cx');
        Config::set('services.google_custom_search.daily_cap', 2);
        Http::fake(['googleapis.com/customsearch/*' => Http::response(['items' => []])]);

        $scraper = $this->app->make(RestaurantWebsiteScraperService::class);
        foreach (range(1, 4) as $i) {
            $scraper->searchGoogleImages("Eatery {$i}", 'Austin', 'TX');
        }

        Http::assertSentCount(2);
    }
}
