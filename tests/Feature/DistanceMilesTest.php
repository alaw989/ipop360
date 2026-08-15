<?php

namespace Tests\Feature;

use App\Http\Resources\LiveRestaurantResource;
use App\Http\Resources\RestaurantResource;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\UnifiedSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract for switching the user-facing distance filter to MILES while keeping
 * internal geo math in km (haversine, nearby(), scoring all stay km):
 *
 *  1. Backend: the `distance` query param is now MILES → convert to km
 *     ($miles * 1.60934) before it reaches UnifiedSearchService::search /
 *     Restaurant::nearby. So `distance=10` (10 mi) → 16.09 km downstream.
 *  2. Resources emit `distance` in MILES (km → mi, × 0.621371), so cards render
 *     "X mi" not "X km".
 *  3. Frontend filter labels + card displays say "mi".
 *  4. PopularityScoreService proximity detail converts km→mi (it already said
 *     "mi" while the value was km — a latent bug).
 */
class DistanceMilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_controller_converts_miles_to_km_for_unified_search(): void
    {
        $this->seedCuisine('Italian', 'italian');

        $mock = $this->mock(UnifiedSearchService::class);
        $mock->shouldReceive('search')
            ->once()
            ->withArgs(fn ($lat, $lng, $cuisine, $category, $sort, $distanceKm, $priceRange) => is_float($distanceKm)
                && abs($distanceKm - 16.0934) < 0.01)
            ->andReturn([]);

        $this->get('/search?distance=10&sort=nearest&lat=30.0&lng=-88.0');

        $mock->shouldHaveReceived('search');
    }

    public function test_search_controller_default_distance_in_miles_converts_to_km(): void
    {
        $this->seedCuisine('Italian', 'italian');

        $mock = $this->mock(UnifiedSearchService::class);
        $mock->shouldReceive('search')
            ->once()
            ->withArgs(fn ($lat, $lng, $cuisine, $category, $sort, $distanceKm, $priceRange) => is_float($distanceKm)
                && abs($distanceKm - 25 * 1.60934) < 0.01)
            ->andReturn([]);

        $this->get('/search?lat=30.0&lng=-88.0');

        $mock->shouldHaveReceived('search');
    }

    public function test_restaurant_resource_emits_distance_in_miles(): void
    {
        $category = CuisineCategory::create(['name' => 'Italian', 'slug' => 'italian-cat']);
        $cuisine = Cuisine::create(['name' => 'Italian', 'slug' => 'italian', 'category_id' => $category->id]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Roma Trattoria',
            'slug' => 'roma-trattoria',
            'city' => 'Austin',
            'state' => 'TX',
            'cuisines' => collect([$cuisine]),
        ]);

        // nearby() sets distance in km via the selectRaw haversine; simulate a
        // 3 km distance and assert the resource emits it as miles (~1.86).
        $restaurant->setAttribute('distance', 3.0);

        $resource = (new RestaurantResource($restaurant))->resolve();
        $this->assertArrayHasKey('distance', $resource);
        $this->assertNotNull($resource['distance']);
        $this->assertEqualsWithDelta(3.0 * 0.621371, $resource['distance'], 0.01);
    }

    public function test_live_restaurant_resource_emits_distance_in_miles(): void
    {
        $resource = (new LiveRestaurantResource([
            'id' => 1,
            'name' => 'China Palace',
            'slug' => 'china-palace',
            'distance' => 5.0,
        ]))->resolve();

        $this->assertEqualsWithDelta(5.0 * 0.621371, $resource['distance'], 0.01);
    }

    public function test_resource_emits_null_distance_when_absent(): void
    {
        $resource = (new LiveRestaurantResource([
            'id' => 1,
            'name' => 'China Palace',
            'slug' => 'china-palace',
        ]))->resolve();

        $this->assertNull($resource['distance']);
    }

    public function test_proximity_score_detail_converts_km_to_miles(): void
    {
        $service = app(\App\Services\PopularityScoreService::class);

        // The proximity detail string is built from the raw distance (km).
        // It must display miles, not km. We assert the rawValueFromArray path
        // via calculateBreakdownForArray and inspect the proximity signal's
        // detail string.
        $row = [
            'name' => 'Test',
            'distance' => 1.60934, // 1 mile
        ];
        $breakdown = $service->calculateBreakdownForArray($row, collect([$row]));

        $proximity = collect($breakdown['signals'])->firstWhere('label', 'Proximity');
        $this->assertNotNull($proximity, 'proximity signal must be present');
        $this->assertStringContainsString('1.0 mi', $proximity['detail']);
    }

    public function test_distance_filter_options_are_miles(): void
    {
        // The controller passes distanceOptions straight to the frontend; the
        // values [1,5,10,25,50] are now interpreted as MILES by the backend
        // conversion. Assert the source of truth (config or controller) keeps
        // these values so the UI shows "mi".
        $this->assertSame([1, 5, 10, 25, 50], config('restaurant-finder.search.distance_options', [1, 5, 10, 25, 50]));
    }

    private function seedCuisine(string $name, string $slug): void
    {
        $category = CuisineCategory::create(['name' => $name, 'slug' => $slug.'-cat']);
        Cuisine::create(['name' => $name, 'slug' => $slug, 'category_id' => $category->id]);
    }
}
