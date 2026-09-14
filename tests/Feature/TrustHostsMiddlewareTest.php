<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * spec-103: host-header injection defense.
 *
 * `next_page_url` (RestaurantController::apiIndex) and the Ziggy `location`
 * prop (HandleInertiaRequests) derive scheme+host from the request Host /
 * X-Forwarded-* headers. Behind a misconfigured proxy (or a cache-poisoning
 * front) an attacker-controlled host can be reflected into URLs the frontend
 * follows. The global TrustHosts middleware rejects any Host not on the
 * configured allow-list before a controller can echo it back.
 *
 * The `/up` health route is used as a cheap 200 that still passes through the
 * global middleware — no Inertia render / Vite manifest required.
 */
class TrustHostsMiddlewareTest extends TestCase
{
    public function test_forged_host_header_is_rejected(): void
    {
        config(['app.trusted_hosts' => ['example.com', 'www.example.com']]);

        $this->get('http://evil.com/up')->assertStatus(400);
    }

    public function test_allow_listed_hosts_pass(): void
    {
        config(['app.trusted_hosts' => ['example.com', 'www.example.com']]);

        $this->get('http://example.com/up')->assertOk();
        $this->get('http://www.example.com/up')->assertOk();
    }

    public function test_host_match_is_case_insensitive_and_ignores_port(): void
    {
        config(['app.trusted_hosts' => ['example.com']]);

        $this->get('http://EXAMPLE.com:8090/up')->assertOk();
    }

    public function test_forwarded_host_from_untrusted_proxy_cannot_override_real_host(): void
    {
        config(['app.trusted_hosts' => ['localhost']]);

        // X-Forwarded-Host is only honored from a trusted proxy (127.0.0.1 by
        // default). A request whose REMOTE_ADDR is not trusted must fall back to
        // the real Host header, so spoofing X-Forwarded-Host: evil.com is inert.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-Host' => 'evil.com'])
            ->get('http://localhost/up')
            ->assertOk();
    }

    public function test_empty_allow_list_is_a_noop(): void
    {
        // An unconfigured allow-list must never lock out legitimate traffic.
        config(['app.trusted_hosts' => []]);

        $this->get('http://anything.test/up')->assertOk();
    }

    public function test_local_environment_bypasses_the_check(): void
    {
        // Dev is often reached over a LAN/Tailscale IP, so the check is a
        // prod concern and stands down in local.
        $this->app->detectEnvironment(fn () => 'local');
        config(['app.trusted_hosts' => ['example.com']]);

        $this->get('http://evil.com/up')->assertOk();
    }
}
