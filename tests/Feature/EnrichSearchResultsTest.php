<?php

namespace Tests\Feature;

use App\Jobs\EnrichSearchResults;
use App\Models\Cuisine;
use App\Services\CuisineMatcher;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\LiveVenuePersister;
use Database\Seeders\CuisineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * EnrichSearchResults runs when a scoped search finds no persisted rows. Before
 * the fix it stamped the searched cuisine (or, for a category, EVERY member
 * cuisine) onto every live result — even ambiguous venues the live pipeline
 * deliberately keeps. These tests pin the per-venue evidence gating.
 */
class EnrichSearchResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CuisineSeeder::class);
    }

    /** @param array<int, array<string, mixed>> $cuisines */
    /** @return array<string, mixed> */
    private function venue(string $name, array $cuisines = []): array
    {
        return [
            'name' => $name,
            'cuisines' => $cuisines,
            'place_types' => [],
            'description' => null,
        ];
    }

    /** @param array<int, array<string, mixed>> $venues */
    private function mocks(array $venues): array
    {
        $liveSearch = Mockery::mock(LiveSearchService::class);
        $liveSearch->shouldReceive('search')->once()->andReturn($venues);

        $geo = Mockery::mock(GeolocationService::class);
        $geo->shouldReceive('reverseGeocode')->once()->andReturn(['city' => 'Tampa', 'state' => 'FL']);

        $persister = Mockery::mock(LiveVenuePersister::class);

        return [$liveSearch, $geo, $persister];
    }

    public function test_attaches_searched_cuisine_only_to_matched_venues(): void
    {
        $italianId = Cuisine::where('slug', 'italian')->value('id');

        [$liveSearch, $geo, $persister] = $this->mocks([
            $this->venue('Trattoria Roma'),
            $this->venue('Arco Iris'),
        ]);

        $persister->shouldReceive('persist')
            ->once()
            ->with(
                Mockery::on(fn ($v) => $v['name'] === 'Trattoria Roma'),
                [$italianId],
                Mockery::any(),
            )
            ->andReturn(['created' => true, 'updated' => false, 'venue' => []]);

        $persister->shouldReceive('persist')
            ->once()
            ->with(
                Mockery::on(fn ($v) => $v['name'] === 'Arco Iris'),
                [],
                Mockery::any(),
            )
            ->andReturn(['created' => false, 'updated' => true, 'venue' => []]);

        $job = new EnrichSearchResults(27.96, -82.45, 'italian', null, 'best_match', 25);
        $job->handle($liveSearch, $geo, $persister, app(CuisineMatcher::class));
    }

    public function test_category_search_attaches_only_matched_member_cuisine(): void
    {
        $mexicanId = Cuisine::where('slug', 'mexican')->value('id');

        [$liveSearch, $geo, $persister] = $this->mocks([
            $this->venue('Taco Bell'),
        ]);

        // Pre-fix this would have been ALL latin-american members. Now only
        // the matched member cuisine (mexican, via 'taco') is attached.
        $persister->shouldReceive('persist')
            ->once()
            ->with(
                Mockery::on(fn ($v) => $v['name'] === 'Taco Bell'),
                [$mexicanId],
                Mockery::any(),
            )
            ->andReturn(['created' => true, 'updated' => false, 'venue' => []]);

        $job = new EnrichSearchResults(27.96, -82.45, null, 'latin-american', 'best_match', 25);
        $job->handle($liveSearch, $geo, $persister, app(CuisineMatcher::class));
    }

    public function test_attaches_osm_cuisine_tags_resolved_to_real_ids(): void
    {
        $italianId = Cuisine::where('slug', 'italian')->value('id');

        [$liveSearch, $geo, $persister] = $this->mocks([
            // An OSM venue whose name carries no keyword, but whose own
            // cuisine tags (from the OSM `cuisine` tag) resolve to real ids.
            $this->venue('Bella Vista', [
                ['id' => abs(crc32('italian')), 'name' => 'Italian', 'slug' => 'italian'],
            ]),
        ]);

        $persister->shouldReceive('persist')
            ->once()
            ->with(
                Mockery::on(fn ($v) => $v['name'] === 'Bella Vista'),
                [$italianId],
                Mockery::any(),
            )
            ->andReturn(['created' => true, 'updated' => false, 'venue' => []]);

        $job = new EnrichSearchResults(27.96, -82.45, 'vietnamese', null, 'best_match', 25);
        $job->handle($liveSearch, $geo, $persister, app(CuisineMatcher::class));
    }
}
