<?php

namespace Tests\Unit;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\CuisineMatcher;
use App\Services\LiveSearchService;
use App\Services\LiveVenuePersister;
use App\Services\PopularityScoreService;
use App\Services\UnifiedSearchService;
use App\Services\VenuePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Contract for UnifiedSearchService — the always-live merged search that fires
 * the free sources on every search (SerpApi stays guarded upstream) and merges
 * persisted DB rows into the same ranked, one-pass-scored union.
 *
 * DB row wins as the base; live fields overlay it (rating, price, photo,
 * website, description, place_types). Match keys in precedence order:
 * google_place_id → slug → phone-last-10 → fuzzy name + ~200m proximity.
 */
class UnifiedSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private PopularityScoreService $scoreService;

    private CuisineMatcher $cuisineMatcher;

    private VenuePipeline $venuePipeline;

    private LiveVenuePersister $venuePersister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoreService = $this->app->make(PopularityScoreService::class);
        $this->cuisineMatcher = $this->app->make(CuisineMatcher::class);
        $this->venuePipeline = $this->app->make(VenuePipeline::class);
        $this->venuePersister = $this->app->make(LiveVenuePersister::class);
    }

    public function test_live_and_db_rows_merge_into_one_ranked_list(): void
    {
        $this->seedCuisine('Chinese', 'chinese');

        Restaurant::factory()->create([
            'name' => 'China Palace',
            'slug' => 'china-palace',
            'google_place_id' => 'g-db-1',
            'latitude' => 30.65,
            'longitude' => -88.20,
            'website_clicks_count' => 100,
            'social_links_count' => 2,
        ]);

        $service = $this->makeService([
            'name' => 'Golden Dragon',
            'slug' => 'golden-dragon',
            'source' => 'serpapi',
            'lat' => 30.67,
            'lng' => -88.22,
            'google_place_id' => 'g-live-1',
            'google_rating' => 4.8,
            'google_review_count' => 900,
            'place_types' => ['Chinese restaurant'],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null, null, 'best_match', 25.0);

        $names = array_column($results, 'name');
        $this->assertContains('China Palace', $names, 'DB row must be present in the merged result');
        $this->assertContains('Golden Dragon', $names, 'Live row must be present in the merged result');

        $this->assertNotEmpty($results, 'Merged search must return results');
        $scores = array_map(fn ($r) => (float) $r['popularity_score'], $results);
        for ($i = 0; $i < count($scores) - 1; $i++) {
            $this->assertGreaterThanOrEqual($scores[$i + 1], $scores[$i], 'Merged results must be sorted by score descending');
        }
    }

    public function test_db_row_wins_as_base_with_live_overlay_fields_on_place_id_match(): void
    {
        $db = Restaurant::factory()->create([
            'name' => 'China Palace',
            'slug' => 'china-palace',
            'google_place_id' => 'g-123',
            'latitude' => 30.65,
            'longitude' => -88.20,
            'google_rating' => 4.2,
            'google_review_count' => 100,
            'price_range' => '$',
            'photo_url' => null,
            'website_url' => null,
            'website_clicks_count' => 500,
            'social_links_count' => 3,
        ]);

        $service = $this->makeService([
            'name' => 'China Palace',
            'slug' => 'china-palace',
            'source' => 'serpapi',
            'lat' => 30.65,
            'lng' => -88.20,
            'google_place_id' => 'g-123',
            'google_rating' => 4.9,
            'google_review_count' => 2000,
            'price_range' => '$$',
            'photo_url' => 'https://cdn.example.com/palace.jpg',
            'website_url' => 'https://chinapalace.example.com',
            'place_types' => ['Chinese restaurant'],
            'description' => 'Fresh hand-pulled noodles.',
        ]);

        $results = $service->search(30.6199783, -88.1967496, null, null, 'best_match', 25.0);

        $this->assertCount(1, $results, 'DB + live same venue must dedup to one merged row');
        $merged = $results[0];

        $this->assertSame($db->id, $merged['id'], 'Merged row must keep the real DB id');
        $this->assertSame(500, $merged['website_clicks_count'] ?? null, 'DB engagement must survive as the base');
        $this->assertSame(3, $merged['social_links_count'] ?? null, 'DB social presence must survive as the base');
        $this->assertSame(4.9, $merged['google_rating'], 'Live rating must overlay the DB row');
        $this->assertSame('$$', $merged['price_range'], 'Live price must overlay the DB row');
        $this->assertSame('https://cdn.example.com/palace.jpg', $merged['photo_url'], 'Live photo must overlay the DB row');
        $this->assertSame('Fresh hand-pulled noodles.', $merged['description'], 'Live description must overlay the DB row');
        $this->assertSame($db->id, $merged['id'], 'Merged row must keep the real DB id');
    }

    public function test_live_venue_without_db_match_is_persisted_and_included(): void
    {
        $this->seedCuisine('Chinese', 'chinese');

        $this->assertSame(0, Restaurant::count());

        $service = $this->makeService([
            'name' => 'Golden Dragon',
            'slug' => 'golden-dragon',
            'source' => 'serpapi',
            'lat' => 30.67,
            'lng' => -88.22,
            'google_place_id' => 'g-live-1',
            'google_rating' => 4.8,
            'google_review_count' => 900,
            'price_range' => '$$',
            'place_types' => ['Chinese restaurant'],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null, null, 'best_match', 25.0);

        $this->assertNotEmpty($results);
        $this->assertSame(1, Restaurant::count(), 'New live venue must be persisted to the DB');
        $this->assertGreaterThan(0, (int) $results[0]['id'], 'Merged live venue must carry a real positive DB id');
    }

    public function test_live_venue_matched_by_slug_merges_into_db_row(): void
    {
        $db = Restaurant::factory()->create([
            'name' => 'Sông Huong',
            'slug' => 'song-huong',
            'google_place_id' => null,
            'latitude' => 30.66,
            'longitude' => -88.21,
            'google_rating' => null,
            'google_review_count' => 0,
        ]);

        $service = $this->makeService([
            'name' => 'Sông Huong',
            'slug' => 'song-huong',
            'source' => 'photon',
            'lat' => 30.66,
            'lng' => -88.21,
            'google_place_id' => null,
            'google_rating' => 4.7,
            'google_review_count' => 400,
            'cuisines' => [
                ['id' => 1, 'name' => 'Chinese', 'slug' => 'chinese'],
            ],
        ]);

        $results = $service->search(30.6199783, -88.1967496, null, null, 'best_match', 25.0);

        $this->assertCount(1, $results, 'Slug-matched live venue must fold into the DB row');
        $this->assertSame($db->id, $results[0]['id']);
        $this->assertSame(4.7, $results[0]['google_rating'], 'Live rating must overlay the slug-matched DB row');
        $this->assertSame('Sông Huong', $results[0]['name']);
    }

    public function test_scoped_search_stamps_cuisine_match_on_db_rows_too(): void
    {
        $this->seedCuisine('Chinese', 'chinese');

        Restaurant::factory()->create([
            'name' => 'China Wok',
            'slug' => 'china-wok',
            'google_place_id' => 'g-db-wok',
            'latitude' => 30.65,
            'longitude' => -88.20,
        ]);

        $service = $this->makeService(); // live returns nothing

        $results = $service->search(30.6199783, -88.1967496, 'chinese', null, 'best_match', 25.0);

        $this->assertNotEmpty($results, 'DB-only scoped search must still return the tagged venue');
        $this->assertArrayHasKey('cuisine_match', $results[0], 'DB rows must carry the cuisine_match stamp in scoped searches');
        $this->assertSame(1.0, (float) $results[0]['cuisine_match'], 'A tagged venue must be an on-cuisine match');
        $this->assertArrayHasKey('popularity_score', $results[0]);
        $this->assertArrayHasKey('score_breakdown', $results[0]);
    }

    public function test_unscoped_search_returns_only_db_rows_when_live_empty(): void
    {
        $this->seedCuisine('Chinese', 'chinese');

        Restaurant::factory()->create([
            'name' => 'China Palace',
            'slug' => 'china-palace',
            'google_place_id' => 'g-db-1',
            'latitude' => 30.65,
            'longitude' => -88.20,
        ]);

        $service = $this->makeService(); // live returns nothing

        $results = $service->search(30.6199783, -88.1967496, null, null, 'best_match', 25.0);

        $this->assertNotEmpty($results, 'DB rows must be served when live sources contribute nothing');
        $this->assertSame('China Palace', $results[0]['name']);
        $this->assertArrayNotHasKey('cuisine_match', $results[0], 'Unscoped search must not stamp cuisine_match');
    }

    public function test_max_results_default_allows_deep_result_sets(): void
    {
        $this->assertGreaterThanOrEqual(
            100,
            (int) config('restaurant-finder.live_search.max_results'),
            'The merged search must raise max_results well past 60 so the union is not starved'
        );
    }

    /**
     * Build a UnifiedSearchService whose live search returns $liveRows (or []),
     * driving DB rows + live rows through the real merge + one-pass scoring.
     *
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $liveRows
     */
    private function makeService(array $liveRows = []): UnifiedSearchService
    {
        $live = Mockery::mock(LiveSearchService::class);
        $live->shouldReceive('search')
            ->andReturn(is_array($liveRows) && array_is_list($liveRows) ? $liveRows : [$liveRows]);

        return new UnifiedSearchService(
            $live,
            $this->scoreService,
            $this->cuisineMatcher,
            $this->venuePipeline,
            $this->venuePersister,
        );
    }

    private function seedCuisine(string $name, string $slug): void
    {
        $category = CuisineCategory::create([
            'name' => $name,
            'slug' => $slug.'-cat',
        ]);

        Cuisine::create([
            'name' => $name,
            'slug' => $slug,
            'category_id' => $category->id,
        ]);
    }
}
