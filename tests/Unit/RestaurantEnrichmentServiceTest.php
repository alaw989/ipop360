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
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RestaurantEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a RestaurantEnrichmentService whose collaborators are no-op mocks.
     * Only the throttling / quota-guard contract under test is exercised, so
     * none of the 11 collaborators' real (network/DB) paths are ever hit.
     */
    private function makeService(?callable $configureSerpApi = null, ?callable $configureFreeSources = null): RestaurantEnrichmentService
    {
        /** @var OverpassService&MockInterface $overpass */
        $overpass = Mockery::mock(OverpassService::class)->shouldIgnoreMissing();
        /** @var BizDataApiService&MockInterface $bizData */
        $bizData = Mockery::mock(BizDataApiService::class)->shouldIgnoreMissing();
        /** @var SerpApiService&MockInterface $serpApiService */
        $serpApiService = Mockery::mock(SerpApiService::class)->shouldIgnoreMissing();
        /** @var SocrataOpenDataService&MockInterface $socrataService */
        $socrataService = Mockery::mock(SocrataOpenDataService::class)->shouldIgnoreMissing();
        /** @var WikidataService&MockInterface $wikidata */
        $wikidata = Mockery::mock(WikidataService::class)->shouldIgnoreMissing();
        /** @var PopularityScoreService&MockInterface $popularityScore */
        $popularityScore = Mockery::mock(PopularityScoreService::class)->shouldIgnoreMissing();
        /** @var RestaurantWebsiteScraperService&MockInterface $websiteScraper */
        $websiteScraper = Mockery::mock(RestaurantWebsiteScraperService::class)->shouldIgnoreMissing();
        /** @var AiEnrichmentService&MockInterface $aiEnrichment */
        $aiEnrichment = Mockery::mock(AiEnrichmentService::class)->shouldIgnoreMissing();
        /** @var CuisineMatcher&MockInterface $cuisineMatcher */
        $cuisineMatcher = Mockery::mock(CuisineMatcher::class)->shouldIgnoreMissing();
        /** @var VenuePipeline&MockInterface $venuePipeline */
        $venuePipeline = Mockery::mock(VenuePipeline::class)->shouldIgnoreMissing();
        /** @var RestaurantValidationService&MockInterface $restaurantValidation */
        $restaurantValidation = Mockery::mock(RestaurantValidationService::class)->shouldIgnoreMissing();

        // cacheKeyFor drives the isSerpApiCacheFresh check — make it deterministic
        // and (by default) absent from the DB so the serpapi key is never "fresh".
        $serpApiService->shouldReceive('cacheKeyFor')->andReturn('unused:combo-key');
        // humanize drives the cache key; defer to a stable value.
        $cuisineMatcher->shouldReceive('humanize')->andReturn('taco');

        if ($configureSerpApi !== null) {
            $configureSerpApi($serpApiService);
        }

        if ($configureFreeSources !== null) {
            $configureFreeSources($bizData, $overpass, $socrataService);
        }

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
            'combos_processed' => 0,
            'combos_cap_reached' => false,
            'total_processed' => 0,
            'real_calls_made' => 0,
            'cache_hits_skipped' => 0,
            'quota_exhausted' => false,
            'per_run_cap_reached' => false,
            'max_runtime_reached' => false,
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
            'combos_processed' => 0,
            'combos_cap_reached' => false,
            'total_processed' => 0,
            'real_calls_made' => 0,
            'cache_hits_skipped' => 0,
            'quota_exhausted' => false,
            'per_run_cap_reached' => false,
            'max_runtime_reached' => false,
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
     * Honest quota accounting: a FAILED outbound SerpApi call (500) still burns
     * quota, so it must be visible to countRealSerpApiCallsLast30Days() and trip
     * the enrichment monthly-budget guard EARLY. Drives the empty row through the
     * real service failure path (consumePoolResponses) rather than inserting it.
     */
    public function test_failed_call_counts_toward_monthly_budget_guard(): void
    {
        Config::set('restaurant-finder.cities', ['Mobile' => [30.69, -88.04]]);
        Config::set('restaurant-finder.cuisines', ['Taco']);
        Config::set('restaurant-finder.enrich.monthly_budget', 1);

        Cuisine::factory()->create(['slug' => 'taco', 'name' => 'Taco']);

        // One FAILED call through the real failure path records an empty row.
        $serpApi = app(SerpApiService::class);
        $serpApi->consumePoolResponses(
            [new Response(new PsrResponse(500, [], 'boom'))],
            30.69,
            -88.04,
            'taco',
            $serpApi->cacheKeyFor(30.69, -88.04, 'taco'),
        );

        $this->assertSame(1, ExternalApiCache::stats()['serpapi_calls_last_30d']);

        $result = $this->makeService()->enrichAllCitiesThrottled();

        $this->assertTrue($result['quota_exhausted']);
        $this->assertSame(0, $result['real_calls_made']);
        $this->assertSame(0, $result['total_processed']);
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

    /**
     * Fail-open enrichment: when SerpApi has flagged the account exhausted
     * (429 "out of searches"), throttled enrichment must NOT stop the grid —
     * every city×cuisine combo still runs the free sources (BizData/Overpass/
     * Socrata + the AI/photo/social/website enrichment they trigger), makes
     * ZERO SerpApi calls, and keeps quota_exhausted surfaced so the outage
     * stays visible. Ratings backfill later via the need-ordering grid.
     */
    public function test_runs_free_sources_for_every_combo_when_provider_exhausted(): void
    {
        Config::set('restaurant-finder.cities', [
            'Mobile' => [30.69, -88.04],
            'Austin' => [30.27, -97.74],
        ]);
        Config::set('restaurant-finder.cuisines', ['Taco']);
        // Budgets far above the grid so neither the monthly nor per-run cap fires
        // first — the provider-exhaustion flag is what switches the run to fail-open.
        Config::set('restaurant-finder.enrich.monthly_budget', 1000);
        Config::set('restaurant-finder.enrich.per_run_cap', 1000);

        Cuisine::factory()->create(['slug' => 'taco', 'name' => 'Taco']);

        // The free-source mocks return [] so enrichByCuisine() completes cleanly;
        // the ->times(2) expectations assert every one of the 2 combos still hit
        // the free sources instead of being short-circuited by the exhaustion break.
        $result = $this->makeService(
            fn ($mock) => $mock->shouldReceive('isProviderExhausted')->andReturn(true),
            function ($bizData, $overpass, $socrata) {
                $bizData->shouldReceive('poolRequestsFor')->times(2)->andReturn([]);
                $overpass->shouldReceive('poolRequestsFor')->times(2)->andReturn([]);
                $socrata->shouldReceive('poolRequestsFor')->times(2)->andReturn([]);
            }
        )->enrichAllCitiesThrottled();

        $this->assertTrue($result['quota_exhausted']);
        $this->assertSame(0, $result['real_calls_made']);
        $this->assertSame(2, $result['total_processed']);
        $this->assertFalse($result['per_run_cap_reached']);
    }

    /**
     * Combos-per-run cap: the free-source sweep (cache-fresh pre-warm AND the
     * provider-exhausted fail-open path) is NOT bounded by per_run_cap or the
     * monthly budget, so without its own cap it walks the whole ~1,470-combo
     * grid nightly — the ~5h35m run that collides with the 05:00+ jobs (and up
     * to ~15h in fail-open mode). With the cap at 2 over a 3-combo grid, the
     * third combo must never reach the free sources.
     */
    public function test_caps_combos_per_run_when_provider_exhausted(): void
    {
        Config::set('restaurant-finder.cities', [
            'Mobile' => [30.69, -88.04],
            'Austin' => [30.27, -97.74],
            'Boulder' => [40.01, -105.27],
        ]);
        Config::set('restaurant-finder.cuisines', ['Taco']);
        Config::set('restaurant-finder.enrich.monthly_budget', 1000);
        Config::set('restaurant-finder.enrich.per_run_cap', 1000);
        Config::set('restaurant-finder.enrich.combos_per_run', 2);

        Cuisine::factory()->create(['slug' => 'taco', 'name' => 'Taco']);

        $result = $this->makeService(
            fn ($mock) => $mock->shouldReceive('isProviderExhausted')->andReturn(true),
            function ($bizData, $overpass, $socrata) {
                $bizData->shouldReceive('poolRequestsFor')->times(2)->andReturn([]);
                $overpass->shouldReceive('poolRequestsFor')->times(2)->andReturn([]);
                $socrata->shouldReceive('poolRequestsFor')->times(2)->andReturn([]);
            }
        )->enrichAllCitiesThrottled();

        $this->assertTrue($result['combos_cap_reached']);
        $this->assertSame(2, $result['combos_processed']);
        $this->assertSame(2, $result['total_processed']);
        $this->assertTrue($result['quota_exhausted']);
    }

    /**
     * Wall-clock guard disabled (0): the run is bounded only by the combo cap /
     * quota caps and never sets max_runtime_reached. Guards the default config.
     */
    public function test_max_runtime_zero_disables_the_guard(): void
    {
        Config::set('restaurant-finder.cities', [
            'Mobile' => [30.69, -88.04],
            'Austin' => [30.27, -97.74],
            'Boulder' => [40.01, -105.27],
        ]);
        Config::set('restaurant-finder.cuisines', ['Taco']);
        Config::set('restaurant-finder.enrich.monthly_budget', 1000);
        Config::set('restaurant-finder.enrich.per_run_cap', 1000);
        Config::set('restaurant-finder.enrich.combos_per_run', 100);
        Config::set('restaurant-finder.enrich.max_runtime_minutes', 0);

        Cuisine::factory()->create(['slug' => 'taco', 'name' => 'Taco']);

        $result = $this->makeService(
            fn ($mock) => $mock->shouldReceive('isProviderExhausted')->andReturn(true),
            function ($bizData, $overpass, $socrata) {
                $bizData->shouldReceive('poolRequestsFor')->andReturn([]);
                $overpass->shouldReceive('poolRequestsFor')->andReturn([]);
                $socrata->shouldReceive('poolRequestsFor')->andReturn([]);
            }
        )->enrichAllCitiesThrottled();

        $this->assertFalse($result['max_runtime_reached']);
        $this->assertSame(3, $result['combos_processed']);
    }

    /**
     * Wall-clock guard triggers: a fail-open free-source sweep that runs past
     * max_runtime_minutes must stop early (not walk the whole grid for hours).
     * A tiny threshold guarantees the guard fires before the grid is exhausted.
     */
    public function test_max_runtime_guard_stops_the_run_early(): void
    {
        Config::set('restaurant-finder.cities', [
            'Mobile' => [30.69, -88.04],
            'Austin' => [30.27, -97.74],
            'Boulder' => [40.01, -105.27],
        ]);
        Config::set('restaurant-finder.cuisines', ['Taco']);
        Config::set('restaurant-finder.enrich.monthly_budget', 1000);
        Config::set('restaurant-finder.enrich.per_run_cap', 1000);
        Config::set('restaurant-finder.enrich.combos_per_run', 100);
        Config::set('restaurant-finder.enrich.max_runtime_minutes', 0.00001);

        Cuisine::factory()->create(['slug' => 'taco', 'name' => 'Taco']);

        $result = $this->makeService(
            fn ($mock) => $mock->shouldReceive('isProviderExhausted')->andReturn(true),
            function ($bizData, $overpass, $socrata) {
                $bizData->shouldReceive('poolRequestsFor')->andReturn([]);
                $overpass->shouldReceive('poolRequestsFor')->andReturn([]);
                $socrata->shouldReceive('poolRequestsFor')->andReturn([]);
            }
        )->enrichAllCitiesThrottled();

        $this->assertTrue($result['max_runtime_reached']);
        $this->assertLessThan(3, $result['combos_processed']);
    }
}
