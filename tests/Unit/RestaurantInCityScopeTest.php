<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Restaurant::scopeInCity() backs the homepage "popular cities" browse links
 * (RestaurantController::index()). Stored city/state casing is inconsistent
 * ("Atlanta" vs "atlanta") and city names collide across states (Phoenix, AZ
 * vs Phoenix, MD), so the scope must match case-insensitively and require a
 * state to disambiguate.
 */
class RestaurantInCityScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_city_and_state_case_insensitively(): void
    {
        Restaurant::factory()->create(['city' => 'Chicago', 'state' => 'IL']);

        $this->assertSame(1, Restaurant::query()->inCity('chicago', 'il')->count());
    }

    public function test_does_not_cross_match_same_city_name_in_a_different_state(): void
    {
        Restaurant::factory()->create(['city' => 'Phoenix', 'state' => 'AZ']);
        Restaurant::factory()->create(['city' => 'Phoenix', 'state' => 'MD']);

        $this->assertSame(1, Restaurant::query()->inCity('Phoenix', 'AZ')->count());
    }

    public function test_excludes_restaurants_in_other_cities(): void
    {
        Restaurant::factory()->create(['city' => 'Austin', 'state' => 'TX']);

        $this->assertSame(0, Restaurant::query()->inCity('Chicago', 'IL')->count());
    }
}
