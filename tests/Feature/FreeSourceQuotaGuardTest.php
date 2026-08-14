<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unified-merged-search: the free-source read-path guard.
 *
 * The merged search fires the free sources (BizData, Socrata, Overpass,
 * Photon) on EVERY request, so a cache-cold city/cuisine means outbound calls
 * on every request. These sources are quota-free but not cost-free (Overpass
 * IP-bans heavy clients), so a per-IP hourly limiter — mirroring SerpApi's —
 * bounds how many DISTINCT cache-miss fetches one IP can trigger per hour.
 * There is no circuit breaker: the free sources have no quota to exhaust.
 */
class FreeSourceQuotaGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the Overpass mirror list to the single host these tests fake.
        Config::set('restaurant-finder.sources.overpass.mirrors', ['https://overpass-api.de/api/interpreter']);
    }

    /** Fake every source; each free source returns an empty (but cachable) set. */
    private function fakeSources(): void
    {
        Http::fake([
            'serpapi.com/*' => Http::response(['local_results' => []], 200),
            'bizdata-web.vercel.app/*' => Http::response(['businesses' => []], 200),
            'overpass-api.de/*' => Http::response(['elements' => []], 200),
            'socrata*/*' => Http::response(['data' => []], 200),
            'photon.komoot.io/*' => Http::response(['features' => []], 200),
        ]);
    }

    public function test_per_ip_limiter_skips_free_sources_after_hourly_cap(): void
    {
        Config::set('restaurant-finder.free_sources.live_misses_per_hour', 2);
        $this->fakeSources();

        // Three DISTINCT cache-cold searches (coords differ at the 1st decimal →
        // distinct keys → distinct misses) from one IP (127.0.0.1).
        $this->getJson('/api/restaurants?lat=40.70&lng=-74.00&cuisine=italian');
        $this->getJson('/api/restaurants?lat=40.80&lng=-74.10&cuisine=italian');
        $afterTwo = ExternalApiCache::where('source', 'bizdata')->count();

        $this->getJson('/api/restaurants?lat=40.90&lng=-74.20&cuisine=italian');
        $afterThree = ExternalApiCache::where('source', 'bizdata')->count();

        $this->assertSame(2, $afterTwo, 'first two distinct misses each fetch + cache BizData');
        $this->assertSame($afterTwo, $afterThree, 'third distinct miss within the hour is blocked by the per-IP limiter');
    }

    public function test_free_source_guard_kill_switch_allows_fetch(): void
    {
        Config::set('restaurant-finder.free_sources.live_misses_per_hour', 1);
        Config::set('restaurant-finder.free_sources.read_path_guard', false); // master kill-switch
        $this->fakeSources();

        $this->getJson('/api/restaurants?lat=40.70&lng=-74.00&cuisine=italian');
        $this->getJson('/api/restaurants?lat=40.80&lng=-74.10&cuisine=italian');

        $this->assertSame(
            2,
            ExternalApiCache::where('source', 'bizdata')->count(),
            'kill-switch off → every distinct miss fetches despite the per-IP cap'
        );
    }
}
