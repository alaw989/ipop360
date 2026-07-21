<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_city_and_state_from_coords(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => [
                    'city' => 'San Francisco',
                    'state' => 'California',
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/geocode?lat=37.7749&lng=-122.4194');

        $response->assertOk()
            ->assertJson(['city' => 'San Francisco', 'state' => 'California']);
    }

    public function test_validates_lat_lng_required(): void
    {
        $response = $this->getJson('/api/geocode');

        $response->assertStatus(422);
    }

    public function test_validates_lat_range(): void
    {
        $response = $this->getJson('/api/geocode?lat=999&lng=0');

        $response->assertStatus(422);
    }

    public function test_returns_nulls_when_reverse_geocode_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $response = $this->getJson('/api/geocode?lat=37.7749&lng=-122.4194');

        $response->assertOk()
            ->assertJson(['city' => null, 'state' => null]);
    }

    public function test_forward_geocode_returns_coords(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '40.7128', 'lon' => '-74.0060'],
            ], 200),
        ]);

        $response = $this->getJson('/api/geocode/forward?city=New+York&state=NY');

        $response->assertOk()
            ->assertJson(['lat' => 40.7128, 'lng' => -74.006]);
    }

    public function test_forward_geocode_validates_city_required(): void
    {
        $response = $this->getJson('/api/geocode/forward');

        $response->assertStatus(422);
    }

    public function test_forward_geocode_returns_nulls_when_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $response = $this->getJson('/api/geocode/forward?city=Nowhere');

        $response->assertOk()
            ->assertJson(['lat' => null, 'lng' => null]);
    }

    public function test_random_cuisine_returns_valid_data(): void
    {
        Cache::forget('featured_cuisines_pool');
        $category = CuisineCategory::factory()->create(['name' => 'Asian', 'slug' => 'asian']);
        $cuisine = Cuisine::factory()->create([
            'category_id' => $category->id,
            'name' => 'Sushi',
            'slug' => 'sushi',
        ]);
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $restaurant->cuisines()->attach($cuisine);

        $response = $this->getJson('/api/random-cuisine');

        $response->assertOk();
        $data = $response->json();
        $this->assertNotNull($data);
        $this->assertEquals('Asian', $data['category_name']);
        $this->assertEquals('asian', $data['category_slug']);
        $this->assertEquals('Sushi', $data['cuisine_name']);
        $this->assertEquals('sushi', $data['cuisine_slug']);
        $this->assertEquals('Asian ▸ Sushi', $data['label']);
    }

    public function test_random_cuisine_returns_null_when_no_restaurants(): void
    {
        Cache::forget('featured_cuisines_pool');
        CuisineCategory::factory()->create(['name' => 'Italian', 'slug' => 'italian']);

        $response = $this->getJson('/api/random-cuisine');

        $response->assertOk();
        $this->assertEmpty($response->json());
    }

    public function test_random_city_returns_city_state_and_coords(): void
    {
        Cache::forget('featured_cities_pool');
        Restaurant::factory()->create([
            'city' => 'Mobile',
            'state' => 'Alabama',
            'latitude' => 30.6944,
            'longitude' => -88.0431,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/random-city');

        $response->assertOk();
        $data = $response->json();
        $this->assertNotNull($data);
        $this->assertEquals('Mobile', $data['city']);
        $this->assertEquals('Alabama', $data['state']);
        $this->assertEquals(30.6944, $data['lat']);
        $this->assertEquals(-88.0431, $data['lng']);
    }
}
