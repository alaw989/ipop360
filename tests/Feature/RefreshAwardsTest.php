<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\WikidataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Tests\TestCase;

/**
 * spec-104 award audit: restaurants:refresh-awards backfills has_award from
 * Wikidata for ALL restaurants (previously only the throttled enrich touched
 * it). Verifies the command writes changes and honours --dry-run.
 */
class RefreshAwardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sets_has_award_for_matching_restaurants(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'The Michelin Spot',
            'city' => 'San Francisco',
            'latitude' => 37.7984,
            'longitude' => -122.436,
            'has_award' => false,
        ]);
        // A far-away restaurant must NOT be flagged.
        Restaurant::factory()->create([
            'name' => 'The Michelin Spot',
            'city' => 'Houston',
            'latitude' => 29.753,
            'longitude' => -95.406,
            'has_award' => false,
        ]);

        $wikidata = Mockery::mock(WikidataService::class);
        $realService = new WikidataService;
        $wikidata->shouldReceive('findAwardedRestaurantsInBox')
            ->andReturn([
                ['name' => 'The Michelin Spot', 'lat' => 37.7984, 'lng' => -122.436],
            ]);
        $wikidata->shouldReceive('hasAwardInSet')
            ->andReturnUsing(fn ($name, $lat, $lng, $awarded) => $realService->hasAwardInSet($name, $lat, $lng, $awarded));

        $this->app->instance(WikidataService::class, $wikidata);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:refresh-awards');
        $command->assertExitCode(0);
        $command->run();

        $freshSf = $restaurant->fresh();
        $this->assertNotNull($freshSf);
        $this->assertTrue($freshSf->has_award, 'SF twin should be flagged');

        $houstonRestaurant = Restaurant::where('city', 'Houston')->first();
        $this->assertNotNull($houstonRestaurant);
        $freshHouston = $houstonRestaurant->fresh();
        $this->assertNotNull($freshHouston);
        $this->assertFalse($freshHouston->has_award, 'Houston twin is >1.5km away and must not be flagged');
    }

    public function test_dry_run_does_not_write(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Dry Run Spot',
            'city' => 'San Francisco',
            'latitude' => 37.7984,
            'longitude' => -122.436,
            'has_award' => false,
        ]);

        $wikidata = Mockery::mock(WikidataService::class);
        $wikidata->shouldReceive('findAwardedRestaurantsInBox')->andReturn([
            ['name' => 'Dry Run Spot', 'lat' => 37.7984, 'lng' => -122.436],
        ]);
        $wikidata->shouldReceive('hasAwardInSet')->andReturn(true);

        $this->app->instance(WikidataService::class, $wikidata);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:refresh-awards --dry-run');
        $command->assertExitCode(0);
        $command->run();

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->has_award, 'dry-run must not write');
    }
}
