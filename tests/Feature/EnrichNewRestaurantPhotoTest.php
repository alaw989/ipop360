<?php

namespace Tests\Feature;

use App\Jobs\EnrichNewRestaurantPhoto;
use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Contract for EnrichNewRestaurantPhoto — the ingestion-time photo hunt queued
 * by LiveVenuePersister when a photo-less row is CREATED. It must fill a
 * missing photo_url, never clobber an existing one, and never promote a
 * transient gps-cs-s CDN URL to primary.
 */
class EnrichNewRestaurantPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_photo_url_when_missing(): void
    {
        $restaurant = Restaurant::factory()->create(['photo_url' => null]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')
            ->once()
            ->andReturn('https://upload.wikimedia.org/stable.jpg');

        (new EnrichNewRestaurantPhoto($restaurant->id))->handle($scraper);

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('https://upload.wikimedia.org/stable.jpg', $fresh->photo_url);
    }

    public function test_skips_when_photo_already_set(): void
    {
        $restaurant = Restaurant::factory()->create([
            'photo_url' => 'https://upload.wikimedia.org/existing.jpg',
        ]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldNotReceive('searchImageForRestaurant');

        (new EnrichNewRestaurantPhoto($restaurant->id))->handle($scraper);

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('https://upload.wikimedia.org/existing.jpg', $fresh->photo_url);
    }

    public function test_skips_missing_restaurant(): void
    {
        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldNotReceive('searchImageForRestaurant');

        (new EnrichNewRestaurantPhoto(999999))->handle($scraper);

        $this->assertSame(0, Restaurant::count());
    }

    public function test_does_not_promote_gps_cs_s_to_primary(): void
    {
        $restaurant = Restaurant::factory()->create(['photo_url' => null]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')
            ->once()
            ->andReturn('https://lh3.googleusercontent.com/gps-cs-s/TOKEN=w400-h300-c-no');

        (new EnrichNewRestaurantPhoto($restaurant->id))->handle($scraper);

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->photo_url, 'a gps-cs-s result must never become primary photo_url');
    }

    public function test_no_result_leaves_photo_empty(): void
    {
        $restaurant = Restaurant::factory()->create(['photo_url' => null]);

        $scraper = Mockery::mock(RestaurantWebsiteScraperService::class);
        $scraper->shouldReceive('searchImageForRestaurant')
            ->once()
            ->andReturnNull();

        (new EnrichNewRestaurantPhoto($restaurant->id))->handle($scraper);

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->photo_url);
    }
}
