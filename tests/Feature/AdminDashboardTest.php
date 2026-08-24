<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SerpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin dashboard surfacing for the SerpApi provider-exhaustion flag
 * (spec: fail-open enrichment). When the account reports "out of searches",
 * the admin dashboard must show it so the outage stays visible even though
 * enrichment now continues via the free sources.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_dashboard_reports_provider_not_exhausted_by_default(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('serpapiQuota.serpapi_exhausted', false));
    }

    public function test_dashboard_reports_provider_exhausted_when_flagged(): void
    {
        app(SerpApiService::class)->markProviderExhausted();

        $response = $this->actingAs($this->admin())->get('/admin')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('serpapiQuota.serpapi_exhausted', true));
    }

    /**
     * spec-106: the exhausted badge is provider-confirmed (a real 429 "out of
     * searches"), while calls_used is a locally-computed estimate that can lag
     * behind. When they disagree the two must never render a contradiction —
     * remaining/pct_used are clamped to reflect the trustworthy exhausted flag
     * regardless of what calls_used says.
     */
    public function test_dashboard_reconciles_usage_numbers_with_exhausted_flag(): void
    {
        app(SerpApiService::class)->markProviderExhausted();

        $response = $this->actingAs($this->admin())->get('/admin')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('serpapiQuota.serpapi_exhausted', true)
            ->where('serpapiQuota.remaining', 0)
            ->where('serpapiQuota.pct_used', 100)
            ->where('serpapiQuota.circuit_breaker_tripped', true)
            ->where('serpapiQuota.enrich_budget_exhausted', true));
    }

    /**
     * spec-107: before the first scheduled sync runs, live_account is null
     * (the dashboard must not error — it shows a "not yet synced" state).
     */
    public function test_dashboard_live_account_is_null_before_first_sync(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('serpapiQuota.live_account', null));
    }

    /**
     * spec-107: once the account-status sync has run, the dashboard surfaces
     * the provider-confirmed snapshot (not just the app's own inference).
     */
    public function test_dashboard_surfaces_synced_live_account_snapshot(): void
    {
        Config::set('services.serpapi.api_key', 'test-key');
        Http::fake([
            'serpapi.com/account.json*' => Http::response([
                'account_status' => 'Your account has run out of searches.',
                'plan_name' => 'Free Plan',
                'plan_renewal_date' => '2026-09-19',
                'searches_per_month' => 250,
                'total_searches_left' => 0,
                'this_month_usage' => 250,
            ], 200),
        ]);
        app(SerpApiService::class)->syncAccountStatus();

        $response = $this->actingAs($this->admin())->get('/admin')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('serpapiQuota.live_account.total_searches_left', 0)
            ->where('serpapiQuota.live_account.account_status', 'Your account has run out of searches.')
            ->where('serpapiQuota.serpapi_exhausted', true));
    }
}
