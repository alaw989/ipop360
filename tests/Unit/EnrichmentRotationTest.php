<?php

namespace Tests\Unit;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\EnrichmentCityState;
use App\Models\Restaurant;
use App\Services\RestaurantEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Coverage-aware rotation (staleness-primary ordering).
 *
 * The throttled grid historically ordered cities by unrated count only, so the
 * 60-combo/night cap always drained the same big metros. Ordering by
 * last_processed_at first (need as tiebreak) guarantees every configured city
 * is swept on a bounded cycle.
 */
class EnrichmentRotationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array{float, float}>  $cities
     * @return array<int, array<string, mixed>>
     */
    private function buildGrid(array $cities): array
    {
        $service = app(RestaurantEnrichmentService::class);

        $reflection = new \ReflectionMethod($service, 'buildCityCuisineGrid');
        $reflection->setAccessible(true);

        $category = CuisineCategory::create(['name' => 'European', 'slug' => 'european']);
        $italian = Cuisine::create(['category_id' => $category->id, 'name' => 'Italian', 'slug' => 'italian']);
        $mexican = Cuisine::create(['category_id' => $category->id, 'name' => 'Mexican', 'slug' => 'mexican']);

        return $reflection->invoke($service, $cities, collect([$italian, $mexican]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $combos
     * @return list<string>
     */
    private function cityOrder(array $combos): array
    {
        return array_values(array_unique(array_column($combos, 'city')));
    }

    public function test_never_processed_city_sorts_before_recently_processed_city(): void
    {
        Config::set('restaurant-finder.cities', [
            'processed-city' => [30.0, -90.0],
            'fresh-city' => [31.0, -91.0],
        ]);

        Restaurant::factory()->create(['city' => 'processed-city', 'google_rating' => null]);
        EnrichmentCityState::create([
            'city' => 'processed-city',
            'last_processed_at' => now(),
            'runs' => 3,
        ]);

        $this->assertSame(
            ['fresh-city', 'processed-city'],
            $this->cityOrder($this->buildGrid(config('restaurant-finder.cities')))
        );
    }

    public function test_least_recently_processed_city_sorts_first_even_with_less_need(): void
    {
        Config::set('restaurant-finder.cities', [
            'needy-recent' => [37.0, -122.0],
            'quiet-stale' => [34.0, -118.0],
        ]);

        // needy-recent has the larger unrated backlog but was swept yesterday;
        // quiet-stale has less need but has not been swept in a month.
        Restaurant::factory()->create(['city' => 'needy-recent', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'needy-recent', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'needy-recent', 'google_rating' => null]);

        EnrichmentCityState::create([
            'city' => 'needy-recent',
            'last_processed_at' => now()->subDay(),
            'runs' => 1,
        ]);
        EnrichmentCityState::create([
            'city' => 'quiet-stale',
            'last_processed_at' => now()->subDays(30),
            'runs' => 1,
        ]);

        $this->assertSame(
            ['quiet-stale', 'needy-recent'],
            $this->cityOrder($this->buildGrid(config('restaurant-finder.cities')))
        );
    }

    public function test_need_tiebreaks_within_the_same_staleness_bucket(): void
    {
        Config::set('restaurant-finder.cities', [
            'warm-city' => [34.0, -118.0],
            'need-city' => [37.0, -122.0],
        ]);

        // Neither has rotation state → both are equally stale; need decides.
        Restaurant::factory()->create(['city' => 'need-city', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'need-city', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'warm-city', 'google_rating' => 4.5]);

        $this->assertSame(
            ['need-city', 'warm-city'],
            $this->cityOrder($this->buildGrid(config('restaurant-finder.cities')))
        );
    }

    public function test_need_strategy_restores_legacy_ordering(): void
    {
        Config::set('restaurant-finder.enrich.rotation_strategy', 'need');
        Config::set('restaurant-finder.cities', [
            'needy-recent' => [37.0, -122.0],
            'quiet-fresh' => [34.0, -118.0],
        ]);

        Restaurant::factory()->create(['city' => 'needy-recent', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'needy-recent', 'google_rating' => null]);

        // Under the legacy strategy, processed state must be ignored and raw
        // need must win — needy-recent leads even though it was swept today.
        EnrichmentCityState::create([
            'city' => 'needy-recent',
            'last_processed_at' => now(),
            'runs' => 1,
        ]);

        $this->assertSame(
            ['needy-recent', 'quiet-fresh'],
            $this->cityOrder($this->buildGrid(config('restaurant-finder.cities')))
        );
    }
}
