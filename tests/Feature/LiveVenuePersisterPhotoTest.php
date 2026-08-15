<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\CuisineMatcher;
use App\Services\GeolocationService;
use App\Services\LiveVenuePersister;
use App\Services\RestaurantValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Pin the transient-photo guard in LiveVenuePersister::persist. Google
 * gps-cs-s CDN photo URLs (from SerpApi google_maps results) decay opaquely
 * (~1-month), so at persist time a gps-cs-s URL must only fill an empty
 * photo_url and must never overwrite an existing stable (Wikimedia/venue-owned)
 * photo, nor displace one in the photos gallery.
 */
class LiveVenuePersisterPhotoTest extends TestCase
{
    use RefreshDatabase;

    private LiveVenuePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $geo = Mockery::mock(GeolocationService::class);

        $this->persister = new LiveVenuePersister(
            app(RestaurantValidationService::class),
            app(CuisineMatcher::class),
            $geo,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function venue(string $googlePlaceId, string $name, array $overrides = []): array
    {
        return array_merge([
            'google_place_id' => $googlePlaceId,
            'slug' => Str::slug($name),
            'name' => $name,
            'popularity_score' => 0.5,
        ], $overrides);
    }

    public function test_gps_cs_s_photo_does_not_overwrite_existing_stable_photo(): void
    {
        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_stable',
            'photo_url' => 'https://upload.wikimedia.org/stable.jpg',
        ]);

        $this->persister->persist($this->venue('place_stable', $restaurant->name, [
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/TOKEN=w400-h300-c-no',
            'photos' => ['https://lh3.googleusercontent.com/gps-cs-s/TOKEN=w400-h300-c-no'],
        ]));

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(
            'https://upload.wikimedia.org/stable.jpg',
            $fresh->photo_url,
            'a gps-cs-s URL must never overwrite an existing stable photo'
        );
    }

    public function test_gps_cs_s_photo_fills_empty_photo_url(): void
    {
        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_empty',
            'photo_url' => null,
        ]);

        $this->persister->persist($this->venue('place_empty', $restaurant->name, [
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/TOKEN=w400-h300-c-no',
        ]));

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(
            'https://lh3.googleusercontent.com/gps-cs-s/TOKEN=w400-h300-c-no',
            $fresh->photo_url,
            'a gps-cs-s URL is allowed to fill an empty photo_url'
        );
    }

    public function test_stable_photo_replaces_existing_gps_cs_s_photo(): void
    {
        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_transient',
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/OLD=w400-h300-c-no',
        ]);

        $this->persister->persist($this->venue('place_transient', $restaurant->name, [
            'photo_url' => 'https://upload.wikimedia.org/fresh.jpg',
        ]));

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(
            'https://upload.wikimedia.org/fresh.jpg',
            $fresh->photo_url,
            'a stable photo is allowed to replace an existing gps-cs-s photo'
        );
    }

    public function test_gallery_gps_cs_s_does_not_displace_existing_stable_entry(): void
    {
        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_gallery',
            'photo_url' => 'https://upload.wikimedia.org/stable.jpg',
            'photos' => ['https://upload.wikimedia.org/stable.jpg'],
        ]);

        $this->persister->persist($this->venue('place_gallery', $restaurant->name, [
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/NEW=w400-h300-c-no',
            'photos' => ['https://lh3.googleusercontent.com/gps-cs-s/NEW=w400-h300-c-no'],
        ]));

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertIsArray($fresh->photos);
        $this->assertSame(
            'https://upload.wikimedia.org/stable.jpg',
            $fresh->photos[0],
            'a gps-cs-s gallery entry must not displace an existing stable entry'
        );
    }

    public function test_create_sets_gps_cs_s_photo_when_no_row_exists(): void
    {
        $result = $this->persister->persist($this->venue('place_new', 'New Eatery', [
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/TOKEN=w400-h300-c-no',
        ]));

        $this->assertTrue($result['created']);
        $this->assertSame(
            'https://lh3.googleusercontent.com/gps-cs-s/TOKEN=w400-h300-c-no',
            $result['restaurant']->photo_url,
            'a create has no existing photo to protect, so gps-cs-s is stored as-is'
        );
    }
}
