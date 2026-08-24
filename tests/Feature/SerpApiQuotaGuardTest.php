<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use App\Models\SerpApiCallLog;
use App\Services\SerpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec-073: the live read path's SerpApi quota guard.
 *
 * Covers (a) coord rounding in the cache key (collapses GPS/IP-geo jitter so it
 * stops minting distinct keys and re-burning quota), (b) the monthly circuit
 * breaker that guarantees the read path can never exhaust the quota, its
 * kill-switch, and (c) the per-IP hourly limiter on distinct cache-miss fetches.
 *
 * The binding constraint is SerpApi's ~250/mo quota; a live dashboard showed
 * 188/250 used mid-cycle, which these guards exist to prevent recurring.
 */
class SerpApiQuotaGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.serpapi.api_key', 'test-key');
        // Default: keep the circuit breaker well out of the way unless a test
        // explicitly tightens it.
        Config::set('restaurant-finder.serpapi.free_quota', 1000);

        // Pin the Overpass mirror list to the single host these tests fake.
        Config::set('restaurant-finder.sources.overpass.mirrors', ['https://overpass-api.de/api/interpreter']);
    }

    /** Fake every source; SerpApi returns one venue near the search area. */
    private function fakeSources(float $lat = 40.75, float $lng = -74.05): void
    {
        Http::fake([
            'serpapi.com/*' => Http::response([
                'local_results' => [
                    [
                        'title' => 'Guard Test Pizzeria',
                        'gps_coordinates' => ['latitude' => $lat, 'longitude' => $lng],
                        'rating' => 4.5,
                        'reviews' => 50,
                        'type' => 'Italian restaurant',
                    ],
                ],
            ], 200),
            'bizdata-web.vercel.app/*' => Http::response(['businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'socrata*/*' => Http::response(['data' => []], 200),
        ]);
    }

    public function test_cache_key_rounds_coordinates_so_jitter_shares_a_key(): void
    {
        $svc = app(SerpApiService::class);

        // Sub-50m GPS jitter (4th-decimal nudges within one 3dp bucket) must
        // collapse to ONE cache key — the dominant fix: a user's GPS variance
        // no longer mints fresh keys and re-burns quota on every search.
        $this->assertSame(
            $svc->cacheKeyFor(40.7001, -74.0002, 'Italian'),
            $svc->cacheKeyFor(40.7004, -74.0001, 'Italian'),
            'coords within one ~3dp bucket must share a cache key (no quota re-burn on GPS jitter)'
        );

        // Genuinely different neighborhoods (~1km+) still cache independently.
        $this->assertNotSame(
            $svc->cacheKeyFor(40.7000, -74.0000, 'Italian'),
            $svc->cacheKeyFor(40.8000, -74.0000, 'Italian')
        );
    }

    public function test_circuit_breaker_skips_live_serpapi_when_quota_near_limit(): void
    {
        // free_quota=10, fraction=0.5 → breaker trips at ceil(5) = 5 prior calls.
        Config::set('restaurant-finder.serpapi.free_quota', 10);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 0.5);

        for ($i = 0; $i < 5; $i++) {
            SerpApiCallLog::record();
        }

        $this->fakeSources();
        $before = ExternalApiCache::where('source', 'serpapi')->count();

        // Cache-cold live search — breaker must SKIP the outbound SerpApi call.
        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');

        $after = ExternalApiCache::where('source', 'serpapi')->count();
        $this->assertSame($before, $after, 'circuit breaker must skip the live SerpApi call (no new entry)');
    }

    public function test_circuit_breaker_kill_switch_allows_fetch(): void
    {
        Config::set('restaurant-finder.serpapi.free_quota', 10);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 0.5);
        Config::set('restaurant-finder.serpapi.read_path_guard', false); // master kill-switch

        for ($i = 0; $i < 5; $i++) {
            SerpApiCallLog::record();
        }

        $this->fakeSources();
        $before = ExternalApiCache::where('source', 'serpapi')->count();

        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');

        $after = ExternalApiCache::where('source', 'serpapi')->count();
        $this->assertSame($before + 1, $after, 'kill-switch off → fetch proceeds despite near-limit');
    }

    public function test_circuit_breaker_does_not_trip_below_threshold(): void
    {
        // 4 prior calls < 5-call limit → breaker open, fetch proceeds.
        Config::set('restaurant-finder.serpapi.free_quota', 10);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 0.5);

        for ($i = 0; $i < 4; $i++) {
            SerpApiCallLog::record();
        }

        $this->fakeSources();
        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');

        $this->assertSame(
            1,
            ExternalApiCache::where('source', 'serpapi')->count(),
            'below the threshold the live fetch proceeds and caches'
        );
    }

    /**
     * Honest quota accounting end-to-end: a FAILED outbound SerpApi call (500)
     * now records an empty cache row, and that row must count toward
     * `serpapi_calls_last_30d` so the live-read circuit breaker trips EARLY —
     * not after silently under-counting the failure. Mirrors the manual-row
     * breaker test but drives the row through the real service failure path.
     */
    public function test_failed_call_trips_circuit_breaker_early(): void
    {
        // free_quota=1, fraction=1.0 → breaker trips at ceil(1) = 1 prior call.
        Config::set('restaurant-finder.serpapi.free_quota', 1);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 1.0);

        // Record ONE failed call via the real fetchRaw() failure path (a 500),
        // which writes an empty row under a DIFFERENT key than the live search.
        Http::fake(['serpapi.com/*' => Http::response(['error' => 'boom'], 500)]);
        $this->assertNull(app(SerpApiService::class)->fetchRaw(30.69, -88.04, 'vietnamese'));
        $this->assertSame(1, SerpApiCallLog::countLast30Days(), 'the failed call must be recorded in the true call-attempt log the breaker reads');

        // Live search at a cache-cold location must now skip SerpApi entirely.
        $this->fakeSources();
        $before = ExternalApiCache::where('source', 'serpapi')->count();

        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');

        $after = ExternalApiCache::where('source', 'serpapi')->count();
        $this->assertSame($before, $after, 'the counted failure must trip the circuit breaker (no live SerpApi fetch)');
    }

    public function test_per_ip_limiter_skips_serpapi_after_hourly_cap(): void
    {
        Config::set('restaurant-finder.serpapi.live_misses_per_hour', 2);
        $this->fakeSources();

        // Three DISTINCT cache-cold searches (coords differ at the 1st decimal →
        // distinct rounded keys → distinct misses) from one IP (127.0.0.1).
        $this->getJson('/api/restaurants?lat=40.70&lng=-74.00&cuisine=italian');
        $this->getJson('/api/restaurants?lat=40.80&lng=-74.10&cuisine=italian');
        $afterTwo = ExternalApiCache::where('source', 'serpapi')->count();

        $this->getJson('/api/restaurants?lat=40.90&lng=-74.20&cuisine=italian');
        $afterThree = ExternalApiCache::where('source', 'serpapi')->count();

        $this->assertSame(2, $afterTwo, 'first two distinct misses each fetch + cache SerpApi');
        $this->assertSame($afterTwo, $afterThree, 'third distinct miss within the hour is blocked by the per-IP limiter');
    }

    /**
     * spec-074: when another request already holds the per-key SerpApi fetch
     * lock, a waiter times out and falls back to an unserialized fetch rather
     * than blocking forever (recall-safe: one extra call >> returning nothing).
     * (True N-concurrent herd-collapse can't be exercised in single-threaded
     * PHPUnit; this proves the lock can never cause a denial of service.)
     */
    public function test_serpapi_lock_falls_back_when_already_held(): void
    {
        $key = app(SerpApiService::class)->cacheKeyFor(40.75, -74.05, 'Italian');
        $holder = Cache::lock("serpapi_fetch:{$key}", 30);
        $holder->get(); // acquire and hold, simulating an in-flight fetch

        Config::set('restaurant-finder.live_search.serpapi_lock_wait', 1); // short wait
        $this->fakeSources(40.75, -74.05);

        $before = ExternalApiCache::where('source', 'serpapi')->count();
        $this->getJson('/api/restaurants?lat=40.75&lng=-74.05&cuisine=italian');
        $after = ExternalApiCache::where('source', 'serpapi')->count();

        $this->assertSame($before + 1, $after, 'lock timeout falls back to an unserialized fetch (recall-safe)');
    }
}
