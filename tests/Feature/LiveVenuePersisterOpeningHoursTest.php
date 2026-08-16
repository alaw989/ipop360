<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\CuisineMatcher;
use App\Services\GeolocationService;
use App\Services\LiveVenuePersister;
use App\Services\RestaurantValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Pin opening_hours ingestion in LiveVenuePersister::persist: a freshly
 * CREATED row copies the venue's OSM raw-hours tag into the app's stored
 * {structured:false, raw_text} shape, but an update never clobbers existing
 * (possibly structured) hours.
 */
class LiveVenuePersisterOpeningHoursTest extends TestCase
{
    use RefreshDatabase;

    private LiveVenuePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

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

    public function test_create_copies_osm_hours_into_raw_text_shape(): void
    {
        $result = $this->persister->persist($this->venue('place_hours', 'Open Late', [
            'opening_hours' => 'Mo-Fr 10:00-20:00; Sa 10:00-22:00',
        ]));

        $this->assertTrue($result['created']);

        $this->assertSame([
            'structured' => false,
            'raw_text' => 'Mo-Fr 10:00-20:00; Sa 10:00-22:00',
        ], $result['restaurant']->opening_hours);
    }

    public function test_create_without_hours_stores_null(): void
    {
        $result = $this->persister->persist($this->venue('place_no_hours', 'No Hours'));

        $this->assertTrue($result['created']);

        $this->assertNull($result['restaurant']->opening_hours);
    }

    public function test_create_ignores_non_string_hours(): void
    {
        $result = $this->persister->persist($this->venue('place_arr_hours', 'Structured', [
            'opening_hours' => ['structured' => true, 'hours' => [['day' => 'Monday', 'open' => '10:00', 'close' => '20:00']]],
        ]));

        $this->assertTrue($result['created']);

        $this->assertNull($result['restaurant']->opening_hours);
    }

    public function test_update_does_not_clobber_existing_hours(): void
    {
        $existing = [
            'structured' => true,
            'hours' => [['day' => 'Monday', 'open' => '10:00', 'close' => '20:00']],
        ];

        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_update',
            'opening_hours' => $existing,
        ]);

        $this->persister->persist($this->venue('place_update', $restaurant->name, [
            'opening_hours' => 'Mo-Fr 09:00-17:00',
        ]));

        $this->assertSame($existing, $restaurant->refresh()->opening_hours);
    }
}
