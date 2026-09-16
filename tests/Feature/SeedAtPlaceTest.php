<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\RestaurantEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Generic Census-place seeding: one free-source fetch per place, no cuisine
 * fan-out and no SerpApi. Gives small towns (Troy, AL) a DB footprint from the
 * free sources that already answer a live search there.
 */
class SeedAtPlaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.serpapi.api_key', null);
        Config::set('restaurant-finder.sources.overpass.mirrors', ['https://overpass-api.de/api/interpreter']);
    }

    /**
     * @return array<string, mixed>
     */
    private function bizDataVenue(string $name, float $lat, float $lon): array
    {
        return [
            'name' => $name,
            'lat' => $lat,
            'lon' => $lon,
            'address' => '123 Main St',
            'phone' => null,
            'website' => null,
            'opening_hours' => null,
        ];
    }

    /**
     * @param  array<string, string>  $tags
     * @return array<string, mixed>
     */
    private function osmNode(int $id, string $name, float $lat, float $lon, array $tags = []): array
    {
        return [
            'type' => 'node',
            'id' => $id,
            'lat' => $lat,
            'lon' => $lon,
            'tags' => array_merge(['name' => $name, 'amenity' => 'restaurant'], $tags),
        ];
    }

    public function test_seed_at_place_persists_free_venues_with_the_place_city(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('Troy Diner', 31.8088, -85.9700)],
            ], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
        ]);

        $count = app(RestaurantEnrichmentService::class)->seedAtPlace(31.8088, -85.9700, 'Troy', 'AL');

        $this->assertSame(1, $count);

        $restaurant = Restaurant::where('name', 'Troy Diner')->firstOrFail();
        $this->assertSame(['Troy', 'AL'], [$restaurant->city, $restaurant->state]);
        // Ratings are SerpApi-only; a seeded row must not carry one.
        $this->assertNull($restaurant->google_rating);
    }

    public function test_seed_at_place_never_calls_serpapi(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('Free Only Cafe', 31.8088, -85.9700)],
            ], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
        ]);

        app(RestaurantEnrichmentService::class)->seedAtPlace(31.8088, -85.9700, 'Troy', 'AL');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'serpapi.com'));
    }

    public function test_seed_at_place_attaches_only_venue_tagged_cuisines(): void
    {
        $category = CuisineCategory::create(['name' => 'Asian', 'slug' => 'asian']);
        $thai = Cuisine::create(['category_id' => $category->id, 'name' => 'Thai', 'slug' => 'thai']);

        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [$this->osmNode(9100, 'Troy Thai Kitchen', 31.8088, -85.9700, ['cuisine' => 'thai'])],
            ], 200),
        ]);

        app(RestaurantEnrichmentService::class)->seedAtPlace(31.8088, -85.9700, 'Troy', 'AL');

        $restaurant = Restaurant::where('name', 'Troy Thai Kitchen')->firstOrFail();
        $this->assertTrue(
            $restaurant->cuisines()->whereKey($thai->id)->exists(),
            'the OSM cuisine tag should be attached to the seeded row'
        );
    }

    public function test_seed_at_place_is_idempotent(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue('Troy Diner', 31.8088, -85.9700)],
            ], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->seedAtPlace(31.8088, -85.9700, 'Troy', 'AL');
        $service->seedAtPlace(31.8088, -85.9700, 'Troy', 'AL');

        $this->assertSame(1, Restaurant::where('name', 'Troy Diner')->count());
    }
}
