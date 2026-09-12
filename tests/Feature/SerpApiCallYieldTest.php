<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Models\SerpApiCallLog;
use App\Services\RestaurantEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Every real SerpApi call records what it returned, and an enrichment call
 * also records what persisting its results produced — so ratings-per-call
 * (the quota's actual value) is measured, not guessed.
 */
class SerpApiCallYieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.serpapi.api_key', 'test-serpapi-key');
        Config::set('services.ai.api_key', '');
        Config::set('restaurant-finder.sources.overpass.mirrors', ['https://overpass-api.de/api/interpreter']);
    }

    private function cuisine(): Cuisine
    {
        $category = CuisineCategory::create(['name' => 'European', 'slug' => 'european']);

        return Cuisine::create(['category_id' => $category->id, 'name' => 'Italian', 'slug' => 'italian']);
    }

    /** @param array<string, mixed> $serpapi */
    private function fake(array $serpapi, int $status = 200): void
    {
        Http::fake([
            'bizdata-web.vercel.app/*' => Http::response(['businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'socrata*/*' => Http::response(['data' => []], 200),
            'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]], 200),
            'serpapi.com/*' => Http::response($serpapi, $status),
        ]);
    }

    public function test_enrichment_call_records_results_and_rating_yield(): void
    {
        Restaurant::factory()->create([
            'name' => 'Trattoria Nonna',
            'latitude' => 37.7749,
            'longitude' => -122.4194,
            'google_rating' => null,
            'google_review_count' => 0,
        ]);
        Restaurant::factory()->create([
            'name' => 'Osteria Rated',
            'latitude' => 37.7760,
            'longitude' => -122.4180,
            'google_rating' => 4.0,
            'google_review_count' => 90,
        ]);
        $this->fake(['local_results' => [
            ['title' => 'Trattoria Nonna', 'gps_coordinates' => ['latitude' => 37.7749, 'longitude' => -122.4194], 'rating' => 4.5, 'reviews' => 120, 'type' => 'Italian restaurant'],
            ['title' => 'Osteria Rated', 'gps_coordinates' => ['latitude' => 37.7760, 'longitude' => -122.4180], 'rating' => 4.1, 'reviews' => 95, 'type' => 'Italian restaurant'],
            ['title' => 'Brand New Pasta Bar', 'gps_coordinates' => ['latitude' => 37.7800, 'longitude' => -122.4100], 'rating' => 4.2, 'reviews' => 40, 'type' => 'Italian restaurant'],
            ['title' => 'Unrated Pop-Up Pasta', 'gps_coordinates' => ['latitude' => 37.7810, 'longitude' => -122.4110], 'type' => 'Italian restaurant'],
        ]]);

        app(RestaurantEnrichmentService::class)->enrichByCuisine(37.7749, -122.4194, $this->cuisine());

        $log = SerpApiCallLog::query()->sole();
        $this->assertSame('enrichment', $log->context);
        $this->assertSame('ok', $log->status);
        $this->assertSame(4, $log->results);
        $this->assertSame(3, $log->rated_results);
        $this->assertSame(2, $log->matched, 'both existing rows were matched');
        $this->assertSame(1, $log->newly_rated, 'only the previously unrated row counts as newly rated');
        $this->assertSame(1, $log->created_rows);

        /** @var PendingCommand $command */
        $command = $this->artisan('quota:status');
        $command
            ->expectsOutputToContain('Enrichment yield: 1 calls → 3 rated results; 2 matched existing rows (1 newly rated), 1 new rated rows (2.0 newly rated + new per call)')
            ->assertSuccessful();
    }

    public function test_failed_call_is_still_counted_and_marked_failed(): void
    {
        $this->fake(['error' => 'Internal error'], 500);

        app(RestaurantEnrichmentService::class)->enrichByCuisine(37.7749, -122.4194, $this->cuisine());

        $log = SerpApiCallLog::query()->sole();
        $this->assertSame('enrichment', $log->context);
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->results);
        $this->assertSame(1, SerpApiCallLog::countLast30Days(), 'a failed call still burns quota');
    }

    public function test_free_only_run_makes_and_records_no_call(): void
    {
        $this->fake(['local_results' => []]);

        app(RestaurantEnrichmentService::class)->enrichByCuisine(37.7749, -122.4194, $this->cuisine(), true);

        $this->assertSame(0, SerpApiCallLog::query()->count());
    }
}
