<?php

namespace Tests\Feature;

use App\Models\EnrichmentPlaceCursor;
use App\Models\Restaurant;
use App\Services\RestaurantEnrichmentService;
use App\Support\CensusPlaceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeedPlacesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{key: string, name: string, state: string, lat: float, lng: float}>  $places
     */
    private function fakeCatalog(array $places): void
    {
        /** @var CensusPlaceCatalog&MockInterface $catalog */
        $catalog = Mockery::mock(CensusPlaceCatalog::class);
        $catalog->shouldReceive('all')->andReturn($places);

        $this->instance(CensusPlaceCatalog::class, $catalog);
    }

    /**
     * @return array{key: string, name: string, state: string, lat: float, lng: float}
     */
    private function place(string $key, string $state, float $lat = 31.0, float $lng = -85.0): array
    {
        return ['key' => $key, 'name' => Str::title($key), 'state' => $state, 'lat' => $lat, 'lng' => $lng];
    }

    /**
     * @return RestaurantEnrichmentService&MockInterface
     */
    private function fakeService(): RestaurantEnrichmentService
    {
        /** @var RestaurantEnrichmentService&MockInterface $service */
        $service = Mockery::mock(RestaurantEnrichmentService::class);
        $this->instance(RestaurantEnrichmentService::class, $service);

        return $service;
    }

    private function runSeed(string $args = ''): PendingCommand
    {
        /** @var PendingCommand $command */
        $command = $this->artisan(trim('restaurants:seed-places '.$args));

        return $command;
    }

    public function test_report_mode_seeds_nothing_and_leaves_the_cursor_untouched(): void
    {
        $this->fakeCatalog([$this->place('alpha', 'AL'), $this->place('beta', 'AL')]);
        $this->fakeService()->shouldNotReceive('seedAtPlace');

        $this->runSeed()->assertSuccessful();

        $this->assertNull(EnrichmentPlaceCursor::current());
        $this->assertSame(0, Restaurant::count());
    }

    public function test_apply_seeds_pending_places_and_advances_the_cursor(): void
    {
        $this->fakeCatalog([$this->place('alpha', 'AL'), $this->place('beta', 'AL')]);
        $this->fakeService()->shouldReceive('seedAtPlace')->twice()->andReturn(3);

        $this->runSeed('--apply --min-rows=5')->assertSuccessful();

        $this->assertSame('beta|AL', EnrichmentPlaceCursor::current());
    }

    public function test_places_at_or_above_min_rows_are_skipped(): void
    {
        Restaurant::factory()->count(5)->create(['city' => 'Beta', 'state' => 'AL']);
        $this->fakeCatalog([$this->place('alpha', 'AL'), $this->place('beta', 'AL')]);

        $service = $this->fakeService();
        $service->shouldReceive('seedAtPlace')
            ->once()
            ->with(31.0, -85.0, 'Alpha', 'AL')
            ->andReturn(2);

        $this->runSeed('--apply --min-rows=5')->assertSuccessful();

        $this->assertSame('beta|AL', EnrichmentPlaceCursor::current());
    }

    public function test_limit_bounds_the_batch(): void
    {
        $this->fakeCatalog([
            $this->place('alpha', 'AL'),
            $this->place('beta', 'AL'),
            $this->place('gamma', 'AL'),
        ]);
        $this->fakeService()->shouldReceive('seedAtPlace')->once()->andReturn(1);

        $this->runSeed('--apply --limit=1 --min-rows=5')->assertSuccessful();

        $this->assertSame('alpha|AL', EnrichmentPlaceCursor::current());
    }

    public function test_state_filter_skips_other_states(): void
    {
        $this->fakeCatalog([
            $this->place('alpha', 'AL'),
            $this->place('gamma', 'TX'),
            $this->place('delta', 'AL'),
        ]);

        $service = $this->fakeService();
        $service->shouldReceive('seedAtPlace')->once()->with(31.0, -85.0, 'Alpha', 'AL')->andReturn(1);
        $service->shouldReceive('seedAtPlace')->once()->with(31.0, -85.0, 'Delta', 'AL')->andReturn(1);

        $this->runSeed('--apply --state=AL --min-rows=5')->assertSuccessful();
    }

    public function test_start_option_overrides_the_stored_cursor(): void
    {
        EnrichmentPlaceCursor::advance('alpha|AL');
        $this->fakeCatalog([
            $this->place('alpha', 'AL'),
            $this->place('beta', 'AL'),
            $this->place('gamma', 'AL'),
        ]);
        $this->fakeService()->shouldReceive('seedAtPlace')->once()->with(31.0, -85.0, 'Gamma', 'AL')->andReturn(1);

        $this->runSeed('--apply --start=beta|AL --min-rows=5')->assertSuccessful();

        $this->assertSame('gamma|AL', EnrichmentPlaceCursor::current());
    }
}
