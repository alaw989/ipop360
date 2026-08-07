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
 * The offline enrichment grid runs every city x cuisine and unfiltered sources
 * (BizData ignores its query param) return ALL nearby restaurants — so before
 * the fix, EVERY persisted venue was stamped with the searched cuisine. These
 * tests pin the new behavior: a venue is only tagged when it carries evidence.
 */
class EnrichCuisineTaggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.serpapi.api_key', null);

        // Pin the Overpass mirror list to the single host these tests fake.
        Config::set('restaurant-finder.sources.overpass.mirrors', ['https://overpass-api.de/api/interpreter']);
    }

    private function makeCuisine(): Cuisine
    {
        $category = CuisineCategory::create(['name' => 'European', 'slug' => 'european']);

        return Cuisine::create([
            'category_id' => $category->id,
            'name' => 'Italian',
            'slug' => 'italian',
        ]);
    }

    /** @return array<string, mixed> */
    private function bizDataVenue(string $name): array
    {
        return [
            'name' => $name,
            'lat' => 37.7749,
            'lon' => -122.4194,
            'address' => '123 Main St',
            'phone' => null,
            'website' => null,
            'opening_hours' => null,
        ];
    }

    /** @param array<string, string> $tags */
    /** @return array<string, mixed> */
    private function osmNode(int $id, string $name, array $tags = []): array
    {
        return [
            'type' => 'node',
            'id' => $id,
            'lat' => 37.78,
            'lon' => -122.41,
            'tags' => array_merge(['name' => $name, 'amenity' => 'restaurant'], $tags),
        ];
    }

    public function test_tags_only_venues_with_evidence(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [
                    $this->bizDataVenue("Tony's Pizza"),
                    $this->bizDataVenue("Jimmy's Grill"),
                ],
            ], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $tagged = Restaurant::where('name', "Tony's Pizza")->first();
        $this->assertNotNull($tagged);
        $this->assertTrue(
            $tagged->cuisines->pluck('slug')->contains('italian'),
            'A venue whose name matches the searched cuisine must be tagged'
        );

        $untagged = Restaurant::where('name', "Jimmy's Grill")->first();
        $this->assertNotNull($untagged, 'Venues without evidence must still be persisted');
        $this->assertCount(0, $untagged->cuisines, 'Venues without evidence must NOT be tagged');
    }

    public function test_tags_osm_venue_from_its_cuisine_tag(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    // Name carries no Italian keyword; the OSM `cuisine` tag is the evidence.
                    $this->osmNode(12345, 'Bella Vista', ['cuisine' => 'italian']),
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $this->makeCuisine());

        $restaurant = Restaurant::where('name', 'Bella Vista')->first();
        $this->assertNotNull($restaurant);
        $this->assertTrue(
            $restaurant->cuisines->pluck('slug')->contains('italian'),
            'An OSM venue tagged cuisine=italian must be tagged italian'
        );
    }

    public function test_tags_osm_venue_from_keyword_level_cuisine_tag(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['total' => 0, 'businesses' => []], 200),
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    // Name carries no Lebanese keyword; the OSM `cuisine`
                    // tag is keyword-level (mediterranean is in the Lebanese
                    // lexicon), so it must be credited as evidence.
                    $this->osmNode(12346, 'Cedars House', ['cuisine' => 'mediterranean']),
                ],
            ], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $category = CuisineCategory::create(['name' => 'Middle Eastern', 'slug' => 'middle-eastern']);
        $cuisine = Cuisine::create([
            'category_id' => $category->id,
            'name' => 'Lebanese',
            'slug' => 'lebanese',
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $service->enrichByCuisine(37.7749, -122.4194, $cuisine);

        $restaurant = Restaurant::where('name', 'Cedars House')->first();
        $this->assertNotNull($restaurant);
        $this->assertTrue(
            $restaurant->cuisines->pluck('slug')->contains('lebanese'),
            'An OSM venue tagged cuisine=mediterranean must be tagged lebanese'
        );
    }

    public function test_re_enriching_does_not_add_an_unmatched_cuisine_to_existing_row(): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response([
                'businesses' => [$this->bizDataVenue("Jimmy's Grill")],
            ], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
        ]);

        $service = app(RestaurantEnrichmentService::class);
        $cuisine = $this->makeCuisine();

        // First pass: persisted but (correctly) untagged.
        $service->enrichByCuisine(37.7749, -122.4194, $cuisine);

        $restaurant = Restaurant::where('name', "Jimmy's Grill")->first();
        $this->assertNotNull($restaurant);
        $this->assertCount(0, $restaurant->cuisines);

        // Second pass (as the city grid revisits this cuisine): still untagged.
        $service->enrichByCuisine(37.7749, -122.4194, $cuisine);
        $this->assertCount(0, $restaurant->fresh()->cuisines);
    }
}
