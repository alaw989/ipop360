<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\CuisineMatcher;
use App\Services\GeolocationService;
use App\Services\LiveVenuePersister;
use App\Services\RestaurantValidationService;
use Database\Seeders\CuisineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Pin the per-venue evidence gating in LiveVenuePersister::persistTaggedVenues
 * (extracted from SearchController). The live search is recall-protective
 * (ambiguous venues are kept, ranked low), so only venues
 * carrying positive evidence for a candidate cuisine are tagged — never a
 * blanket stamp of the searched cuisine or every category member.
 */
class LiveVenuePersisterTagTest extends TestCase
{
    use RefreshDatabase;

    private LiveVenuePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CuisineSeeder::class);

        $geo = Mockery::mock(GeolocationService::class);
        $geo->shouldReceive('reverseGeocode')->andReturn(['city' => 'Tampa', 'state' => 'FL']);

        $this->persister = new LiveVenuePersister(
            app(RestaurantValidationService::class),
            app(CuisineMatcher::class),
            $geo,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cuisines
     * @return array<string, mixed>
     */
    private function venue(string $name, array $cuisines = []): array
    {
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'cuisines' => $cuisines,
            'place_types' => [],
            'description' => null,
            'popularity_score' => 0.5,
        ];
    }

    public function test_searched_cuisine_attached_only_to_matched_venues(): void
    {
        $this->persister->persistTaggedVenues([
            $this->venue('Trattoria Roma'),
            $this->venue('Arco Iris'),
        ], 'italian', null, 27.96, -82.45);

        $matched = Restaurant::where('name', 'Trattoria Roma')->firstOrFail();
        $this->assertSame(['italian'], $matched->cuisines()->pluck('slug')->all());

        $unmatched = Restaurant::where('name', 'Arco Iris')->firstOrFail();
        $this->assertSame([], $unmatched->cuisines()->pluck('slug')->all());
    }

    public function test_category_search_attaches_only_matched_member_cuisine(): void
    {
        $this->persister->persistTaggedVenues([
            $this->venue('Taco Bell'),
        ], null, 'latin-american', 27.96, -82.45);

        $restaurant = Restaurant::where('name', 'Taco Bell')->firstOrFail();
        $this->assertSame(['mexican'], $restaurant->cuisines()->pluck('slug')->all());
    }

    public function test_attaches_osm_cuisine_tags_resolved_to_real_ids(): void
    {
        $this->persister->persistTaggedVenues([
            $this->venue('Bella Vista', [
                ['id' => abs(crc32('italian')), 'name' => 'Italian', 'slug' => 'italian'],
            ]),
        ], 'vietnamese', null, 27.96, -82.45);

        $restaurant = Restaurant::where('name', 'Bella Vista')->firstOrFail();
        $this->assertSame(['italian'], $restaurant->cuisines()->pluck('slug')->all());
    }

    public function test_reports_created_and_updated_counts(): void
    {
        $existing = Restaurant::factory()->create([
            'name' => 'Trattoria Roma',
            'slug' => 'trattoria-roma',
        ]);

        $result = $this->persister->persistTaggedVenues([
            $this->venue('Trattoria Roma'),
            $this->venue('Arco Iris'),
        ], 'italian', null, 27.96, -82.45);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
    }
}
