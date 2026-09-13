<?php

namespace Tests\Feature;

use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The /thumbs/{file} route and the API resource's photo_thumb_url.
 *
 * The file name embeds the restaurant id and the photo hash, so a thumbnail is
 * only served while it still matches the row's current photo_url — a replaced
 * photo must 404, never serve a stale image.
 */
class PhotoThumbnailRouteTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal valid 1×1 WebP. */
    private const TINY_WEBP = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/vuUAAA=';

    private function service(): PhotoThumbnailService
    {
        return $this->app->make(PhotoThumbnailService::class);
    }

    private function storedName(Restaurant $restaurant, string $photoUrl): string
    {
        return $restaurant->id.'-'.substr(sha1($photoUrl), 0, 10).'.webp';
    }

    public function test_serves_a_stored_thumbnail_with_immutable_caching(): void
    {
        Storage::fake('local');
        $restaurant = Restaurant::factory()->create(['photo_url' => 'https://a.example/one.jpg']);
        $file = $this->storedName($restaurant, 'https://a.example/one.jpg');
        Storage::disk('local')->put($this->service()->storagePath($file), base64_decode(self::TINY_WEBP));

        $response = $this->get('/thumbs/'.$file);

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
    }

    public function test_404s_when_the_photo_has_changed(): void
    {
        Storage::fake('local');
        $restaurant = Restaurant::factory()->create(['photo_url' => 'https://a.example/new.jpg']);
        $file = $this->storedName($restaurant, 'https://a.example/old.jpg');
        Storage::disk('local')->put($this->service()->storagePath($file), base64_decode(self::TINY_WEBP));

        $this->get('/thumbs/'.$file)->assertNotFound();
    }

    public function test_404s_when_the_file_is_missing(): void
    {
        Storage::fake('local');
        $restaurant = Restaurant::factory()->create(['photo_url' => 'https://a.example/one.jpg']);
        $file = $this->storedName($restaurant, 'https://a.example/one.jpg');

        $this->get('/thumbs/'.$file)->assertNotFound();
    }

    public function test_404s_for_a_name_that_does_not_match_the_route(): void
    {
        Storage::fake('local');

        $this->get('/thumbs/not-a-thumbnail.jpg')->assertNotFound();
    }

    public function test_resource_includes_the_thumb_url_only_when_fresh(): void
    {
        $restaurant = Restaurant::factory()->create(['photo_url' => 'https://a.example/one.jpg']);
        $fresh = $this->storedName($restaurant, 'https://a.example/one.jpg');

        $restaurant->forceFill(['photo_thumb' => $fresh])->save();
        $with = (new RestaurantResource($restaurant->fresh()))->resolve();
        $this->assertSame('/thumbs/'.$fresh, $with['photo_thumb_url']);

        $restaurant->forceFill(['photo_thumb' => '9-deadbeef00.webp'])->save();
        $without = (new RestaurantResource($restaurant->fresh()))->resolve();
        $this->assertArrayNotHasKey('photo_thumb_url', $without);
    }
}
