<?php

namespace Tests\Fakes;

use App\Services\DomainDnsChecker;

/**
 * DomainDnsChecker over a fixed table instead of real DNS: name => records,
 * null = the lookup failed (SERVFAIL); unlisted names have no records
 * (NXDOMAIN).
 */
class FakeDnsChecker extends DomainDnsChecker
{
    /** @param array<string, list<array<string, mixed>>|null> $dns */
    public function __construct(private array $dns) {}

    protected function records(string $name, int $type): ?array
    {
        return array_key_exists($name, $this->dns) ? $this->dns[$name] : [];
    }
}
