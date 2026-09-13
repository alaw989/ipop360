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
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false; // rejects file://, gopher://, ftp://, etc.
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }

        // Host may already be an IP literal (e.g. http://127.0.0.1 or an IPv6
        // [::1]); otherwise resolve it. gethostbynamel is IPv4-only, so IPv6-only
        // hostnames fail closed — but bracketed IPv6 literals are validated here.
        $hostLiteral = str_starts_with($host, '[') ? trim($host, '[]') : $host;
        $ips = filter_var($hostLiteral, FILTER_VALIDATE_IP) !== false
            ? [$hostLiteral]
            : gethostbynamel($host);

        if ($ips === false || $ips === []) {
            return false; // DNS failure → fail closed
        }

        foreach ($ips as $ip) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                return false; // private / reserved / loopback / link-local
            }
        }

        return true;
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
