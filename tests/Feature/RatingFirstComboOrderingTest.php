<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\RestaurantEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class RatingFirstComboOrderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array{float, float}> $cities
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
        $japanese = Cuisine::create(['category_id' => $category->id, 'name' => 'Japanese', 'slug' => 'japanese']);

        return $reflection->invoke($service, $cities, collect([$italian, $mexican, $japanese]));
    }

    public function test_neediest_city_combos_come_first(): void
    {
        Config::set('restaurant-finder.cities', [
            'need-city' => [37.7749, -122.4194],
            'warm-city' => [34.0522, -118.2437],
        ]);

        // need-city: 3 unrated rows, warm-city: 1 unrated row
        Restaurant::factory()->create(['city' => 'need-city', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'need-city', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'need-city', 'google_rating' => 4.0]);
        Restaurant::factory()->create(['city' => 'warm-city', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'warm-city', 'google_rating' => 4.5]);

        $combos = $this->buildGrid(config('restaurant-finder.cities'));

        $citiesInOrder = array_values(array_unique(array_column($combos, 'city')));
        $this->assertSame(['need-city', 'warm-city'], $citiesInOrder);
    }

    public function test_empty_city_is_seeded_before_low_need_populated_city(): void
    {
        Config::set('restaurant-finder.cities', [
            'empty-city' => [40.7128, -74.0060],
            'rated-city' => [41.8781, -87.6298],
        ]);

        // empty-city: no rows at all, rated-city: 1 unrated + 1 rated
        Restaurant::factory()->create(['city' => 'rated-city', 'google_rating' => null]);
        Restaurant::factory()->create(['city' => 'rated-city', 'google_rating' => 4.2]);

        $combos = $this->buildGrid(config('restaurant-finder.cities'));

        $citiesInOrder = array_values(array_unique(array_column($combos, 'city')));
        $this->assertSame(['empty-city', 'rated-city'], $citiesInOrder);
    }
}
