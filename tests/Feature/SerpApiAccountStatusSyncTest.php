<?php

namespace Tests\Feature;

use App\Services\SerpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * spec-107: periodic sync of SerpApi's own /account.json (an account-info
 * call, not the metered /search endpoint — zero quota cost) into the local
 * exhausted flag + dashboard snapshot, so the app's quota state reflects
 * provider-confirmed truth instead of an inference from its own call
 * history.
 */
class SerpApiAccountStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWithKey(): SerpApiService
    {
        Config::set('services.serpapi.api_key', 'test-key');

        return app(SerpApiService::class);
    }

    private function fakeAccountResponse(int $totalSearchesLeft, string $status = 'ok'): void
    {
        Http::fake([
            'serpapi.com/account.json*' => Http::response([
                'account_status' => $status,
                'plan_name' => 'Free Plan',
                'plan_renewal_date' => '2026-09-19',
                'searches_per_month' => 250,
                'total_searches_left' => $totalSearchesLeft,
                'this_month_usage' => 250 - $totalSearchesLeft,
            ], 200),
        ]);
    }

    public function test_fetch_account_status_returns_null_without_a_key(): void
    {
        Config::set('services.serpapi.api_key', null);
        $service = app(SerpApiService::class);

        $this->assertNull($service->fetchAccountStatus());
    }

    public function test_fetch_account_status_returns_parsed_data_on_success(): void
    {
        $service = $this->serviceWithKey();
        $this->fakeAccountResponse(0, 'Your account has run out of searches.');

        $data = $service->fetchAccountStatus();

        $this->assertNotNull($data);
        $this->assertSame(0, $data['total_searches_left']);
        $this->assertSame('Your account has run out of searches.', $data['account_status']);
    }

    public function test_fetch_account_status_returns_null_on_http_failure(): void
    {
        $service = $this->serviceWithKey();
        Http::fake(['serpapi.com/account.json*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertNull($service->fetchAccountStatus());
    }

    public function test_fetch_account_status_returns_null_on_malformed_response(): void
    {
        $service = $this->serviceWithKey();
        Http::fake(['serpapi.com/account.json*' => Http::response(['unexpected' => 'shape'], 200)]);

        $this->assertNull($service->fetchAccountStatus());
    }

    public function test_sync_marks_exhausted_when_zero_searches_left(): void
    {
        $service = $this->serviceWithKey();
        $this->fakeAccountResponse(0, 'Your account has run out of searches.');

        $snapshot = $service->syncAccountStatus();

        $this->assertNotNull($snapshot);
        $this->assertSame(0, $snapshot['total_searches_left']);
        $this->assertTrue($service->isProviderExhausted());
        $this->assertSame($snapshot, $service->cachedAccountSnapshot());
    }

    public function test_sync_clears_exhausted_flag_when_searches_are_available(): void
    {
        $service = $this->serviceWithKey();
        $service->markProviderExhausted();
        $this->assertTrue($service->isProviderExhausted());

        $this->fakeAccountResponse(50);
        $service->syncAccountStatus();

        $this->assertFalse($service->isProviderExhausted());
    }

    public function test_sync_does_not_touch_flag_or_cache_on_fetch_failure(): void
    {
        $service = $this->serviceWithKey();
        $service->markProviderExhausted();
        Http::fake(['serpapi.com/account.json*' => Http::response(['error' => 'boom'], 500)]);

        $result = $service->syncAccountStatus();

        $this->assertNull($result);
        $this->assertTrue($service->isProviderExhausted(), 'a transient sync failure must not clear a real exhaustion');
        $this->assertNull($service->cachedAccountSnapshot());
    }

    public function test_cached_account_snapshot_is_null_before_any_sync(): void
    {
        $service = app(SerpApiService::class);

        $this->assertNull($service->cachedAccountSnapshot());
    }

    public function test_sync_command_reports_success(): void
    {
        Config::set('services.serpapi.api_key', 'test-key');
        $this->fakeAccountResponse(0, 'Your account has run out of searches.');

        /** @var PendingCommand $command */
        $command = $this->artisan('serpapi:sync-account-status');
        $command->assertExitCode(0)
            ->expectsOutputToContain('0 / 250 searches left');
        $command->run();
    }

    public function test_sync_command_skips_gracefully_without_a_key(): void
    {
        Config::set('services.serpapi.api_key', null);

        /** @var PendingCommand $command */
        $command = $this->artisan('serpapi:sync-account-status');
        $command->assertExitCode(0)
            ->expectsOutputToContain('No SerpApi API key configured');
        $command->run();
    }

    public function test_sync_command_fails_on_fetch_failure(): void
    {
        Config::set('services.serpapi.api_key', 'test-key');
        Http::fake(['serpapi.com/account.json*' => Http::response(['error' => 'boom'], 500)]);

        /** @var PendingCommand $command */
        $command = $this->artisan('serpapi:sync-account-status');
        $command->assertExitCode(1);
        $command->run();
    }
}
