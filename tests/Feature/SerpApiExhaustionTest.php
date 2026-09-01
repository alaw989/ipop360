<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use App\Models\SerpApiCallLog;
use App\Services\SerpApiService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Provider-level SerpApi exhaustion: when SerpApi returns 429 "out of
 * searches", the account is spent regardless of what the app's cache-store
 * tracker says. The service must flag it, stop issuing pool requests for the
 * retry window, and quota:status must surface it.
 */
class SerpApiExhaustionTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWithKey(): SerpApiService
    {
        Config::set('services.serpapi.api_key', 'test-key');

        return app(SerpApiService::class);
    }

    private function clientResponse(int $status, string $body): Response
    {
        return new Response(new PsrResponse($status, [], $body));
    }

    public function test_429_out_of_searches_marks_provider_exhausted(): void
    {
        $service = $this->serviceWithKey();

        $response = $this->clientResponse(429, '{"error":"Your account has run out of searches."}');
        $service->detectProviderExhaustion($response);

        $this->assertTrue($service->isProviderExhausted());
        $this->assertTrue(Cache::has('serpapi_provider_exhausted'));
    }

    public function test_pool_requests_are_suppressed_when_exhausted(): void
    {
        $service = $this->serviceWithKey();
        $service->markProviderExhausted();

        $this->assertSame([], $service->poolRequestsFor(27.95, -82.45, 'vietnamese'));
    }

    public function test_other_failures_do_not_flag_exhaustion(): void
    {
        $service = $this->serviceWithKey();

        $service->detectProviderExhaustion($this->clientResponse(500, 'boom'));
        $this->assertFalse($service->isProviderExhausted());

        // A 429 that is NOT "out of searches" (e.g. a transient rate limit)
        // must not be treated as account exhaustion.
        $service->detectProviderExhaustion($this->clientResponse(429, '{"error":"Rate limit exceeded"}'));
        $this->assertFalse($service->isProviderExhausted());
    }

    public function test_quota_status_reports_provider_exhaustion(): void
    {
        app(SerpApiService::class)->markProviderExhausted();

        /** @var PendingCommand $command */
        $command = $this->artisan('quota:status');
        $command->assertExitCode(0);
        $command->expectsOutputToContain('Provider status: EXHAUSTED');
        $command->expectsOutputToContain('Live fetches are paused');
        $command->run();
    }

    /**
     * A failed SerpApi response (e.g. 500) still consumed a call at the
     * provider, so it must be recorded in the cache so quota accounting (and
     * the circuit breaker) sees it instead of silently under-counting.
     */
    public function test_failed_pool_response_is_recorded_for_quota_accounting(): void
    {
        $service = $this->serviceWithKey();
        $cacheKey = $service->cacheKeyFor(27.95, -82.45, 'vietnamese');

        $this->assertSame(0, ExternalApiCache::stats()['serpapi_calls_last_30d']);

        $result = $service->consumePoolResponses(
            [$this->clientResponse(500, 'boom')],
            27.95,
            -82.45,
            'vietnamese',
            $cacheKey,
        );

        $this->assertSame([], $result);
        $this->assertSame(1, ExternalApiCache::where('source', 'serpapi')->count());
        $this->assertSame(1, ExternalApiCache::stats()['serpapi_calls_last_30d']);
        $this->assertSame(1, SerpApiCallLog::countLast30Days());
    }

    /**
     * A connection error (Throwable) in the pool also records the attempted
     * call so a down/slow provider trips the circuit breaker early.
     */
    public function test_throwable_pool_response_is_recorded_for_quota_accounting(): void
    {
        $service = $this->serviceWithKey();
        $cacheKey = $service->cacheKeyFor(27.95, -82.45, 'vietnamese');

        $result = $service->consumePoolResponses(
            [new \RuntimeException('connection refused')],
            27.95,
            -82.45,
            'vietnamese',
            $cacheKey,
        );

        $this->assertSame([], $result);
        $this->assertSame(1, ExternalApiCache::where('source', 'serpapi')->count());
        $this->assertSame(1, SerpApiCallLog::countLast30Days());
    }

    /**
     * A 429 "out of searches" arriving through the pool path must flag the
     * account (parsePoolResponse → detectProviderExhaustion).
     */
    public function test_pool_429_out_of_searches_marks_provider_exhausted(): void
    {
        $service = $this->serviceWithKey();
        $cacheKey = $service->cacheKeyFor(27.95, -82.45, 'vietnamese');

        $result = $service->consumePoolResponses(
            [$this->clientResponse(429, '{"error":"Your account has run out of searches."}')],
            27.95,
            -82.45,
            'vietnamese',
            $cacheKey,
        );

        $this->assertSame([], $result);
        $this->assertTrue($service->isProviderExhausted());
    }

    /**
     * search() is the last direct-call SerpApi path that would still fire a
     * live call into a dead account — poolRequestsFor already honors the
     * exhaustion flag. A flagged account must suppress the outbound call here
     * too (returning []) rather than hammering a provider that reports "out of
     * searches".
     */
    public function test_search_does_not_fire_live_call_when_provider_exhausted(): void
    {
        $service = $this->serviceWithKey();
        $service->markProviderExhausted();

        Http::fake([
            'serpapi.com/*' => Http::response([
                'local_results' => [
                    ['title' => 'Would Have Fetched Pizzeria'],
                ],
            ], 200),
        ]);

        $this->assertSame([], $service->search(27.95, -82.45, 'vietnamese'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'serpapi.com'));
        $this->assertSame(0, SerpApiCallLog::countLast30Days(), 'a suppressed (never-fired) call must not be logged as a real attempt');
    }

    /**
     * search() (the direct-call, non-pool path) must also log every real
     * outbound attempt, success or failure — this is the counter the
     * dashboard and circuit breaker now read instead of the cache-row proxy.
     */
    public function test_search_records_a_call_attempt_on_success(): void
    {
        $service = $this->serviceWithKey();
        Http::fake([
            'serpapi.com/*' => Http::response(['local_results' => [
                ['title' => 'Guard Test Pizzeria'],
            ]], 200),
        ]);

        $service->search(27.95, -82.45, 'vietnamese');

        $this->assertSame(1, SerpApiCallLog::countLast30Days());
    }

    /**
     * spec-106: ExternalApiCache::stats()['serpapi_calls_last_30d'] counts
     * DISTINCT cache rows (an upsert-by-key store), not real call attempts —
     * so repeat calls against the SAME key (e.g. a failed/empty result
     * retried after its short TTL expires) stay invisible to it forever.
     * SerpApiCallLog is append-only and must count every real attempt.
     */
    public function test_repeated_calls_against_same_key_are_undercounted_by_cache_rows_but_not_by_call_log(): void
    {
        $service = $this->serviceWithKey();
        $cacheKey = $service->cacheKeyFor(27.95, -82.45, 'vietnamese');

        Http::fake(['serpapi.com/*' => Http::response(['error' => 'boom'], 500)]);

        // Three real outbound attempts against the identical cache key,
        // simulating retries after each empty-row TTL (2h) expires — travel
        // forward between calls so findByKey() doesn't just serve the still-
        // fresh cached empty result instead of firing again.
        $service->search(27.95, -82.45, 'vietnamese');
        $this->travel(3)->hours();
        $service->search(27.95, -82.45, 'vietnamese');
        $this->travel(3)->hours();
        $service->search(27.95, -82.45, 'vietnamese');

        $this->assertSame(
            1,
            ExternalApiCache::where('external_id', $cacheKey)->count(),
            'the old proxy metric collapses all 3 real calls into one upserted row'
        );
        $this->assertSame(
            3,
            SerpApiCallLog::countLast30Days(),
            'the true call-attempt log must count all 3 real calls'
        );
    }

    /**
     * The public UI must know about the outage via a global Inertia shared
     * prop (so it can relabel the Rating sort option and swap SEO copy), not
     * just the admin dashboard.
     */
    public function test_shared_inertia_prop_reports_provider_exhaustion(): void
    {
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('serpapi_exhausted', false));

        app(SerpApiService::class)->markProviderExhausted();

        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('serpapi_exhausted', true));
    }
}
