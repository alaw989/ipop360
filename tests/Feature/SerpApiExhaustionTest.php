<?php

namespace Tests\Feature;

use App\Services\SerpApiService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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

        $this->artisan('quota:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('Provider status: EXHAUSTED')
            ->expectsOutputToContain('Live fetches are paused');
    }
}
