<?php

namespace Tests\Unit;

use App\Support\SsrfGuard;
use Tests\TestCase;

/**
 * spec-103: DNS-rebinding/IPv6/encoding defense for the SSRF guard.
 *
 * spec-075's guard resolved + validated the host, but the later HTTP fetch
 * re-resolved it — a rebinding resolver could answer the validation with a
 * public IP and the fetch with 127.0.0.1 / 169.254.169.254. The guard now
 * exposes the validated IP so callers can PIN it (CURLOPT_RESOLVE). These tests
 * drive the resolver deterministically via SsrfGuard::resolveUsing() — no real
 * DNS — so each encoding/range case is pinned exactly.
 */
class SsrfGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrfGuard::resolveUsing(null);
        parent::tearDown();
    }

    /** @param array<int, string>|false $ips */
    private function resolverReturns(array|false $ips): void
    {
        SsrfGuard::resolveUsing(fn () => $ips);
    }

    public function test_resolve_safe_returns_public_literal_ip(): void
    {
        $this->assertSame('8.8.8.8', SsrfGuard::resolveSafe('http://8.8.8.8/'));
    }

    public function test_resolve_safe_rejects_ipv4_private_reserved_ranges(): void
    {
        foreach ([
            'http://127.0.0.1/',
            'http://10.0.0.5/',
            'http://172.16.0.1/',
            'http://192.168.1.1/',
            'http://169.254.169.254/latest/meta-data/',
            'http://0.0.0.0/',
            'http://100.64.0.1/',
        ] as $url) {
            $this->assertNull(SsrfGuard::resolveSafe($url), "must reject {$url}");
        }
    }

    public function test_resolve_safe_rejects_ipv6_literals(): void
    {
        foreach ([
            'http://[::1]/',
            'http://[fc00::1]/',
            'http://[fd12:3456::1]/',
            'http://[fe80::1]/',
        ] as $url) {
            $this->assertNull(SsrfGuard::resolveSafe($url), "must reject {$url}");
        }
    }

    public function test_resolve_safe_rejects_non_http_schemes(): void
    {
        $this->assertNull(SsrfGuard::resolveSafe('file://127.0.0.1/etc/passwd'));
        $this->assertNull(SsrfGuard::resolveSafe('gopher://example.com/'));
    }

    public function test_resolve_safe_fails_closed_on_dns_failure(): void
    {
        $this->resolverReturns(false);

        $this->assertNull(SsrfGuard::resolveSafe('http://does-not-resolve.test/'));
    }

    public function test_resolve_safe_rejects_private_result_for_encoded_literals(): void
    {
        // Decimal/octal/hex IP encodings are not FILTER_VALIDATE_IP literals, so
        // they go through the resolver. Whatever it returns, a private address is
        // rejected — the filter can't be bypassed by the encoding.
        foreach (['http://2130706433/', 'http://0177.0.0.1/', 'http://0x7f000001/'] as $url) {
            $this->resolverReturns(['127.0.0.1']);
            $this->assertNull(SsrfGuard::resolveSafe($url), "must reject {$url}");
        }
    }

    public function test_pinned_options_build_curl_resolve_entry_for_validated_ip(): void
    {
        $this->resolverReturns(['93.184.216.34']);

        $this->assertSame(
            ['curl' => [CURLOPT_RESOLVE => ['example.com:443:93.184.216.34']]],
            SsrfGuard::pinnedOptions('https://example.com/path')
        );
    }

    public function test_pinned_options_use_default_port_80_for_http(): void
    {
        $this->resolverReturns(['93.184.216.34']);

        $this->assertSame(
            ['curl' => [CURLOPT_RESOLVE => ['example.com:80:93.184.216.34']]],
            SsrfGuard::pinnedOptions('http://example.com/path')
        );
    }

    public function test_pinned_options_skip_ip_literal_hosts(): void
    {
        // An IP-literal host has no DNS name to re-resolve — nothing to pin.
        $this->assertSame([], SsrfGuard::pinnedOptions('http://8.8.8.8/'));
    }

    public function test_pinned_options_empty_for_unsafe_host(): void
    {
        $this->resolverReturns(['127.0.0.1']);

        $this->assertSame([], SsrfGuard::pinnedOptions('http://rebind.test/'));
    }

    public function test_rebinding_resolves_exactly_once(): void
    {
        // A resolver that answers public on the first call and private on any
        // later call is the rebinding attack. Pinning resolves once, so the
        // private answer is never consulted.
        $calls = 0;
        SsrfGuard::resolveUsing(function () use (&$calls): array {
            $calls++;

            return $calls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        });

        $options = SsrfGuard::pinnedOptions('http://rebind.test/');

        $this->assertSame(1, $calls, 'the host must be resolved exactly once');
        $this->assertSame(['curl' => [CURLOPT_RESOLVE => ['rebind.test:80:93.184.216.34']]], $options);
    }
}
