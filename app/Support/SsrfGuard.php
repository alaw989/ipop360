<?php

namespace App\Support;

/**
 * spec-075 SSRF guard, shared by the website scraper and the photo-thumbnail
 * fetcher. Deciding what the server may fetch is security-critical enough that
 * it should exist once, not be re-implemented per caller.
 */
class SsrfGuard
{
    /**
     * Resolver used to map a host to IPv4 addresses. Overridable in tests so
     * DNS-rebinding behavior can be exercised deterministically (no real DNS).
     *
     * @var (callable(string): (array<int, string>|false))|null
     */
    private static $resolver = null;

    /**
     * Override host resolution (tests only). Pass null to restore the default.
     *
     * @param  (callable(string): (array<int, string>|false))|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @return array<int, string>|false
     */
    private static function resolveHost(string $host): array|false
    {
        return self::$resolver !== null ? (self::$resolver)($host) : gethostbynamel($host);
    }

    /**
     * Is this URL safe for the server to fetch?
     *
     * Allows only http(s), resolves the host, and rejects any resolved IP in a
     * private/loopback/link-local/reserved range — including 169.254.169.254
     * (cloud instance metadata), 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, ::1,
     * and fc00::/7. Fail-closed: an unparseable URL, a non-http(s) scheme, or a
     * DNS resolution failure → unsafe (return false).
     */
    public static function isSafe(string $url): bool
    {
        return self::resolveSafe($url) !== null;
    }

    /**
     * Resolve the URL's host to a validated public IP, or null if unsafe.
     *
     * The returned IP is what callers should PIN the fetch to (CURLOPT_RESOLVE)
     * so the fetch cannot be re-resolved to a private address (DNS rebinding).
     * An IP-literal host is returned as-is (there is no DNS name to rebind).
     */
    public static function resolveSafe(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null; // rejects file://, gopher://, ftp://, etc.
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }

        // Host may already be an IP literal (e.g. http://127.0.0.1 or an IPv6
        // [::1]); otherwise resolve it. gethostbynamel is IPv4-only, so IPv6-only
        // hostnames fail closed — but bracketed IPv6 literals are validated here.
        $hostLiteral = str_starts_with($host, '[') ? trim($host, '[]') : $host;
        $ips = filter_var($hostLiteral, FILTER_VALIDATE_IP) !== false
            ? [$hostLiteral]
            : self::resolveHost($host);

        if ($ips === false || $ips === []) {
            return null; // DNS failure → fail closed
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null; // private / reserved / loopback / link-local
            }
        }

        return (string) $ips[0];
    }

    /**
     * Is this a routable public address? Rejects PHP's private/reserved ranges
     * plus RFC 6598 (100.64.0.0/10) shared address space, which
     * FILTER_FLAG_NO_RES_RANGE does not cover but which cloud networks route
     * internally (a CGNAT/metadata-adjacent target for SSRF).
     */
    private static function isPublicIp(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        $octets = explode('.', $ip);
        if (count($octets) === 4 && (int) $octets[0] === 100 && (int) $octets[1] >= 64 && (int) $octets[1] <= 127) {
            return false; // 100.64.0.0/10
        }

        return true;
    }

    /**
     * Guzzle/curl options that PIN the fetch to the validated IP.
     *
     * Without this, the validation in isSafe()/resolveSafe() resolves the host
     * once and the subsequent HTTP request re-resolves it — a rebinding resolver
     * can hand back a private/metadata address on the second lookup.
     *
     * Returns [] when the host is an IP literal (nothing to rebind), when the
     * URL is unsafe, or when curl is unavailable.
     *
     * @return array<string, mixed>
     */
    public static function pinnedOptions(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return [];
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return [];
        }

        $hostLiteral = str_starts_with($host, '[') ? trim($host, '[]') : $host;
        if (filter_var($hostLiteral, FILTER_VALIDATE_IP) !== false) {
            return []; // IP literal: no DNS name to pin
        }

        $ip = self::resolveSafe($url);
        if ($ip === null) {
            return [];
        }

        if (! defined('CURLOPT_RESOLVE')) {
            return []; // curl extension missing — cannot pin
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]];
    }

    /**
     * Is the URL's host an IP literal (no DNS name to resolve/pin)?
     */
    public static function isIpLiteralHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = str_starts_with($host, '[') ? trim($host, '[]') : $host;

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * The guarded `allow_redirects` config — capped at 3, http(s)-only, and
     * each hop re-validated so a public host can't redirect into a
     * private/loopback/metadata endpoint.
     *
     * @return array<string,mixed>
     */
    public static function redirectOptions(): array
    {
        return [
            'max' => 3,
            'strict' => true,
            'protocols' => ['https', 'http'],
            'on_redirect' => function ($request, $response, $uri): void {
                if (! self::isSafe((string) $uri)) {
                    throw new \RuntimeException('SSRF guard blocked unsafe redirect target: '.$uri);
                }
            },
        ];
    }
}
