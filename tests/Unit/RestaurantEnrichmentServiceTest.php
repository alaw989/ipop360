<?php

namespace Tests\Unit;

use App\Models\Cuisine;
use App\Models\ExternalApiCache;
use App\Services\AiEnrichmentService;
use App\Services\BizDataApiService;
use App\Services\CuisineMatcher;
use App\Services\OverpassService;
use App\Services\PopularityScoreService;
use App\Services\RestaurantEnrichmentService;
use App\Services\RestaurantValidationService;
use App\Services\RestaurantWebsiteScraperService;
use App\Services\SerpApiService;
use App\Services\SocrataOpenDataService;
use App\Services\VenuePipeline;
use App\Services\WikidataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class RestaurantEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a RestaurantEnrichmentService whose collaborators are no-op mocks.
     * Only the throttling / quota-guard contract under test is exercised, so
     * none of the 11 collaborators' real (network/DB) paths are ever hit.
     */
    private function makeService(): RestaurantEnrichmentService
    {
        /** @var OverpassService&\Mockery\MockInterface $overpass */
        $overpass = Mockery::mock(OverpassService::class)->shouldIgnoreMissing();
        /** @var BizDataApiService&\Mockery\MockInterface $bizData */
        $bizData = Mockery::mock(BizDataApiService::class)->shouldIgnoreMissing();
        /** @var SerpApiService&\Mockery\MockInterface $serpApiService */
        $serpApiService = Mockery::mock(SerpApiService::class)->shouldIgnoreMissing();
        /** @var SocrataOpenDataService&\Mockery\MockInterface $socrataService */
        $socrataService = Mockery::mock(SocrataOpenDataService::class)->shouldIgnoreMissing();
        /** @var WikidataService&\Mockery\MockInterface $wikidata */
        $wikidata = Mockery::mock(WikidataService::class)->shouldIgnoreMissing();
        /** @var PopularityScoreService&\Mockery\MockInterface $popularityScore */
        $popularityScore = Mockery::mock(PopularityScoreService::class)->shouldIgnoreMissing();
        /** @var RestaurantWebsiteScraperService&\Mockery\MockInterface $websiteScraper */
        $websiteScraper = Mockery::mock(RestaurantWebsiteScraperService::class)->shouldIgnoreMissing();
        /** @var AiEnrichmentService&\Mockery\MockInterface $aiEnrichment */
        $aiEnrichment = Mockery::mock(AiEnrichmentService::class)->shouldIgnoreMissing();
        /** @var CuisineMatcher&\Mockery\MockInterface $cuisineMatcher */
        $cuisineMatcher = Mockery::mock(CuisineMatcher::class)->shouldIgnoreMissing();
        /** @var VenuePipeline&\Mockery\MockInterface $venuePipeline */
        $venuePipeline = Mockery::mock(VenuePipeline::class)->shouldIgnoreMissing();
        /** @var RestaurantValidationService&\Mockery\MockInterface $restaurantValidation */
        $restaurantValidation = Mockery::mock(RestaurantValidationService::class)->shouldIgnoreMissing();

        // cacheKeyFor drives the isSerpApiCacheFresh check — make it deterministic
        // and (by default) absent from the DB so the serpapi key is never "fresh".
        $serpApiService->shouldReceive('cacheKeyFor')->andReturn('unused:combo-key');
        // humanize drives the cache key; defer to a stable value.
        $cuisineMatcher->shouldReceive('humanize')->andReturn('taco');

        return new RestaurantEnrichmentService(
            $overpass, $bizData, $serpApiService, $socrataService, $wikidata,
            $popularityScore, $websiteScraper, $aiEnrichment, $cuisineMatcher,
            $venuePipeline, $restaurantValidation
        );
    }

    /**
     * No cities configured = instant all-zero result. Guards the quota/cap and
     * enrichment code from ever running (and burning quota) with no grid.
     */
    public function test_returns_zero_result_when_no_cities_configured(): void
    {
        Config::set('restaurant-finder.cities', []);

        $result = $this->makeService()->enrichAllCitiesThrottled();

        $this->assertSame([
            'total_processed' => 0,
            'real_calls_made' => 0,
            'cache_hits_skipped' => 0,
            'quota_exhausted' => false,
            'per_run_cap_reached' => false,
        ], $result);
    }

    /**
     * Returns zero result when cities exist but no cuisines are configured and
     * none exist in the DB. Guards cleanup of the default (empty) cuisines config.
     */
    public function test_returns_zero_result_when_cuisines_present_but_cities_only(): void
    {
        Config::set('restaurant-finder.cities', ['Mobile' => [30.69, -88.04]]);
        Config::set('restaurant-finder.cuisines', []);

        $result = $this->makeService()->enrichAllCitiesThrottled();

        $this->assertSame([
            'total_processed' => 0,
            'real_calls_made' => 0,
            'cache_hits_skipped' => 0,
            'quota_exhausted' => false,
            'per_run_cap_reached' => false,
        ], $result);
    }

    /**
     * Quota guard: once the rolling-30-day real SerpApi call count reaches the
     * monthly budget, throttled enrichment must stop before burning any budget.
     * The persisted serpapi rows drive countRealSerpApiCallsLast30Days(); the
     * mock cache key is absent so the combo isn't treated as a fresh/skip.
     */
    public function test_stops_when_monthly_budget_exhausted(): void
    {
        Config::set('restaurant-finder.cities', ['Mobile' => [30.69, -88.04]]);
        Config::set('restaurant-finder.cuisines', ['Taco']);
        Config::set('restaurant-finder.enrich.monthly_budget', 2);

        Cuisine::factory()->create(['slug' => 'taco', 'name' => 'Taco']);

        // Two real SerpApi cache entries within the last 30 days → at quota.
        ExternalApiCache::query()->create([
            'source' => 'serpapi',
            'external_id' => 'row-1',
            'data' => [],
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        ExternalApiCache::query()->create([
            'source' => 'serpapi',
            'external_id' => 'row-2',
            'data' => [],
            'fetched_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);

        $result = $this->makeService()->enrichAllCitiesThrottled();

        $this->assertTrue($result['quota_exhausted']);
        $this->assertSame(0, $result['real_calls_made']);
        $this->assertSame(0, $result['total_processed']);
        $this->assertSame(0, $result['cache_hits_skipped']);
        $this->assertFalse($result['per_run_cap_reached']);
    }

    /**
     * Per-run cap guard: with quota intact but the per-run cap already met
     * (0), the run stops without enriching and flags per_run_cap_reached.
     */
    public function test_stops_when_per_run_cap_reached(): void
    {
        Config::set('restaurant-finder.cities', ['Mobile' => [30.69, -88.04]]);
        Config::set('restaurant-finder.cuisines', ['Taco']);
        Config::set('restaurant-finder.enrich.per_run_cap', 0);
        // Monthly budget far above a single run so the cap (not quota) triggers.
        Config::set('restaurant-finder.enrich.monthly_budget', 1000);

        Cuisine::factory()->create(['slug' => 'taco', 'name' => 'Taco']);

        $result = $this->makeService()->enrichAllCitiesThrottled();

        $this->assertTrue($result['per_run_cap_reached']);
        $this->assertFalse($result['quota_exhausted']);
        $this->assertSame(0, $result['real_calls_made']);
        $this->assertSame(0, $result['total_processed']);
    }
}
