<?php

namespace App\Services;

/**
 * Is a website's domain gone from DNS? A site whose registered domain no
 * longer exists (NXDOMAIN — lapsed, never renewed) is dead: nobody can reach
 * it, so the identity check can reject it instead of shrugging "unreachable".
 *
 * Conservative by design — any doubt answers "not dead":
 *   - only an empty answer counts as gone: PHP's dns_get_record() returns []
 *     for NXDOMAIN but false for SERVFAIL/timeouts, which stay undecided;
 *   - the host itself must not resolve either;
 *   - a canary domain that always exists must resolve at the same moment, so
 *     a broken resolver can never read as thousands of dead domains;
 *   - the registered domain is the host's last two labels. For a multi-part
 *     suffix (shop.example.co.uk → co.uk) that asks about the suffix, which
 *     exists — the safe direction.
 */
class DomainDnsChecker
{
    /** Always exists (IANA-reserved); if it doesn't resolve, DNS is down. */
    private const CANARY_DOMAIN = 'example.com';

    public function isDead(string $url): bool
    {
        $host = $this->hostOf($url);
        $domain = $host === null ? null : $this->registeredDomain($host);
        if ($host === null || $domain === null) {
            return false;
        }

        return $this->records($domain, DNS_NS) === []
            && $this->records($host, DNS_A + DNS_AAAA) === []
            && ! in_array($this->records(self::CANARY_DOMAIN, DNS_NS), [[], null], true);
    }

    /** The host's last two labels, or null for an IP or a single-label host. */
    public function registeredDomain(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2 || in_array('', $labels, true)) {
            return null;
        }

        return implode('.', array_slice($labels, -2));
    }

    /**
     * DNS records of the given type(s): [] when there are none (NXDOMAIN),
     * null when the lookup itself failed.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function records(string $name, int $type): ?array
    {
        // Trailing dot: an absolute name, never expanded with resolv.conf's
        // search domains.
        $records = @dns_get_record($name.'.', $type);

        return is_array($records) ? $records : null;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url(str_contains($url, '://') ? $url : 'http://'.$url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
