<?php

namespace Tests\Feature;

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
 * Pin state-name reconciliation in LiveVenuePersister::persist: some live
 * sources (Photon, the reverse-geocode fallback) hand back the full state
 * name rather than the DB's canonical 2-letter abbreviation. Persisting
 * must normalize at write time so newly-ingested rows stay queryable via
 * HomeController's exact-match `where('state', ...)`, instead of drifting
 * until the next DataHygiene sweep.
 */
class LiveVenuePersisterStateNormalizationTest extends TestCase
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

    public function test_persist_normalizes_a_photon_style_full_state_name(): void
    {
        $result = $this->persister->persist($this->venue('place_photon', 'Terra Gaucha', [
            'city' => 'Austin',
            'state' => 'Texas',
        ]));

        $this->assertSame('TX', $result['restaurant']->state);
    }

    public function test_persist_normalizes_a_full_state_name_from_the_reverse_geocode_fallback(): void
    {
        $result = $this->persister->persist(
            $this->venue('place_fallback', 'No Own State'),
            defaultLocation: ['city' => 'Miami', 'state' => 'Florida'],
        );

        $this->assertSame('FL', $result['restaurant']->state);
    }
}
