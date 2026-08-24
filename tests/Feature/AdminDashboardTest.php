<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SerpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
