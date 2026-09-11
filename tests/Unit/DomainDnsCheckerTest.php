<?php

namespace Tests\Unit;

use App\Services\DomainDnsChecker;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeDnsChecker;

/**
 * DomainDnsChecker only calls a domain dead when DNS is sure: the registered
 * domain and the host have no records while a canary still resolves.
 */
class DomainDnsCheckerTest extends TestCase
{
    public function test_registered_domain_is_the_last_two_labels(): void
    {
        $dns = new DomainDnsChecker;

        $this->assertSame('3trainpizzeria.com', $dns->registeredDomain('www.3trainpizzeria.com'));
        $this->assertSame('example.com', $dns->registeredDomain('Shop.Example.COM.'));
        // A multi-part suffix yields the suffix itself, which exists: never "dead".
        $this->assertSame('co.uk', $dns->registeredDomain('bistro.example.co.uk'));
        $this->assertNull($dns->registeredDomain('localhost'));
        $this->assertNull($dns->registeredDomain('192.168.1.10'));
        $this->assertNull($dns->registeredDomain('[::1]'));
    }

    public function test_dead_only_when_domain_and_host_are_gone_and_the_canary_resolves(): void
    {
        $canary = ['example.com' => [['type' => 'NS']]];

        $this->assertTrue((new FakeDnsChecker($canary))->isDead('https://www.lapsed-bistro.com/menu'));
        $this->assertTrue((new FakeDnsChecker($canary))->isDead('lapsed-bistro.com'), 'a scheme-less URL');

        $this->assertFalse((new FakeDnsChecker($canary + ['lapsed-bistro.com' => [['type' => 'NS']]]))->isDead('https://www.lapsed-bistro.com/'));
        $this->assertFalse((new FakeDnsChecker($canary + ['www.lapsed-bistro.com' => [['type' => 'A']]]))->isDead('https://www.lapsed-bistro.com/'));
        $this->assertFalse((new FakeDnsChecker($canary + ['lapsed-bistro.com' => null]))->isDead('https://www.lapsed-bistro.com/'));
        $this->assertFalse((new FakeDnsChecker([]))->isDead('https://www.lapsed-bistro.com/'), 'resolver down');
        $this->assertFalse((new FakeDnsChecker(['example.com' => null]))->isDead('https://www.lapsed-bistro.com/'), 'canary lookup failed');
        $this->assertFalse((new FakeDnsChecker($canary))->isDead('http://10.0.0.5/'), 'an IP host');
        $this->assertFalse((new FakeDnsChecker($canary))->isDead('not a url'));
    }
}
