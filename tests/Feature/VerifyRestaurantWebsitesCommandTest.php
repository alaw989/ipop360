<?php

namespace Tests\Feature;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Services\DomainDnsChecker;
use App\Services\WebsiteIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Tests\Fakes\FakeDnsChecker;
use Tests\TestCase;

/**
 * restaurants:verify-websites identity-checks stored websites (was a HEAD
 * liveness check that let dictionary pages, parked domains and other
 * businesses' sites live forever) and quarantines — never silently nulls —
 * the rejected ones.
 */
class VerifyRestaurantWebsitesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
    }

    /** @param array<string, mixed> $pages */
    private function fake(array $pages): void
    {
        Http::fake(array_merge($pages, ['*' => Http::response('', 404)]));
    }

    /** @param array<string, mixed> $overrides */
    private function venue(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->create(array_merge([
            'is_active' => true,
            'name' => 'Blue Heron Bistro',
            'address' => '410 Harbor Way',
            'city' => 'Tacoma',
            'state' => 'WA',
            'phone' => '2535550142',
            'website_url' => 'https://blueheron.example/',
            'website_verified_at' => null,
        ], $overrides));
    }

    private function ownSite(): string
    {
        return '<html><head><title>Blue Heron Bistro | Tacoma</title></head><body><p>410 Harbor Way, Tacoma WA</p><p>(253) 555-0142</p></body></html>';
    }

    /** @param array<string, mixed> $options */
    private function verifyCommand(array $options = []): PendingCommand
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites', $options);

        return $command->assertSuccessful();
    }

    public function test_warns_when_no_restaurants_with_website_urls(): void
    {
        Http::fake();

        $this->verifyCommand()->expectsOutputToContain('No restaurants with website URLs to verify.');
    }

    public function test_own_site_is_verified_and_stamped(): void
    {
        $restaurant = $this->venue();
        $this->fake(['https://blueheron.example/' => Http::response($this->ownSite())]);

        $this->verifyCommand()->expectsOutputToContain('Done. 1 verified, 0 brand, 0 unconfirmed, 0 rejected, 0 skipped (unreachable).')->run();

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $fresh->website_identity);
        $this->assertNotNull($fresh->website_verified_at);
        $this->assertSame('https://blueheron.example/', $fresh->website_url);
    }

    public function test_dictionary_site_is_quarantined_without_a_fetch(): void
    {
        $restaurant = $this->venue(['website_url' => 'https://www.merriam-webster.com/dictionary/bistro']);
        Http::fake();

        $this->verifyCommand()->expectsOutputToContain('0 verified, 0 brand, 0 unconfirmed, 1 rejected')->run();

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->website_url);
        $entry = FieldQuarantine::query()->where('restaurant_id', $restaurant->id)->firstOrFail();
        $this->assertSame('website_url', $entry->field);
        $this->assertSame('https://www.merriam-webster.com/dictionary/bistro', $entry->old_value);
        $this->assertSame('website_blocked_domain', $entry->reason);
        Http::assertNothingSent();
    }

    public function test_someone_elses_site_is_quarantined(): void
    {
        $restaurant = $this->venue(['website_url' => 'https://plumber.example/']);
        $this->fake(['https://plumber.example/' => Http::response(
            '<html><head><title>Acme Plumbing</title></head><body>'.str_repeat('<p>Drains, water heaters and repipes since 1990.</p>', 10).'</body></html>'
        )]);

        $this->verifyCommand()->expectsOutputToContain('1 rejected')->run();

        $this->assertNull($restaurant->fresh()?->website_url);
        $this->assertSame('website_no_name_evidence', FieldQuarantine::query()->where('restaurant_id', $restaurant->id)->value('reason'));
    }

    public function test_404_is_rejected_and_quarantined(): void
    {
        $restaurant = $this->venue();
        $this->fake(['https://blueheron.example/' => Http::response('', 404)]);

        $this->verifyCommand()->expectsOutputToContain('1 rejected')->run();

        $this->assertNull($restaurant->fresh()?->website_url);
        $this->assertSame('website_dead_link', FieldQuarantine::query()->where('restaurant_id', $restaurant->id)->value('reason'));
    }

    public function test_server_error_and_network_failure_keep_the_url(): void
    {
        $erroring = $this->venue(['website_url' => 'https://erroring.example/']);
        $offline = $this->venue(['website_url' => 'https://offline.example/']);
        $this->fake([
            'https://erroring.example/' => Http::response('', 500),
            'https://offline.example/' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->verifyCommand()->expectsOutputToContain('0 rejected, 2 skipped (unreachable).')->run();

        $freshErroring = $erroring->fresh();
        $this->assertNotNull($freshErroring);
        $this->assertSame('https://erroring.example/', $freshErroring->website_url);
        $this->assertNotNull($freshErroring->website_verified_at);
        $this->assertSame('https://offline.example/', $offline->fresh()?->website_url);
        $this->assertSame(0, FieldQuarantine::query()->count());
    }

    public function test_unfetchable_site_on_a_lapsed_domain_is_quarantined(): void
    {
        $restaurant = $this->venue(['website_url' => 'https://www.blueheron-lapsed.com/']);
        Http::fake(['*' => fn () => throw new ConnectionException('Could not resolve host')]);
        Config::set('restaurant-finder.data_integrity.website_dead_domain_check', true);
        $this->app->instance(DomainDnsChecker::class, new FakeDnsChecker(['example.com' => [['type' => 'NS']]]));

        $this->verifyCommand()->expectsOutputToContain('1 rejected, 0 skipped (unreachable).')->run();

        $this->assertNull($restaurant->fresh()?->website_url);
        $this->assertSame('website_dead_domain', FieldQuarantine::query()->where('restaurant_id', $restaurant->id)->value('reason'));
    }

    public function test_unreachable_option_rechecks_only_rows_without_an_identity_verdict(): void
    {
        $unjudged = $this->venue(['website_verified_at' => now()->subDays(2)]);
        $judged = $this->venue([
            'website_url' => 'https://judged.example/',
            'website_verified_at' => now()->subDays(2),
            'website_identity' => WebsiteIdentityVerifier::VERIFIED,
        ]);
        $neverChecked = $this->venue(['website_url' => 'https://never.example/']);
        $this->fake(['https://blueheron.example/' => Http::response($this->ownSite())]);

        $this->verifyCommand(['--unreachable' => true])->expectsOutputToContain('Done. 1 verified, 0 brand, 0 unconfirmed, 0 rejected')->run();

        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $unjudged->fresh()?->website_identity);
        $this->assertTrue($judged->fresh()?->website_verified_at?->lt(now()->subDay()), 'a row with a verdict is left alone');
        $this->assertNull($neverChecked->fresh()?->website_verified_at, 'never-checked rows belong to the normal run');
        $this->assertSame(0, FieldQuarantine::query()->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        Log::shouldReceive('channel')->with('enrichment')->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Website URL verification complete' && ($context['dry_run'] ?? false) === true);
        Log::shouldReceive('debug');

        $restaurant = $this->venue();
        $this->fake(['https://blueheron.example/' => Http::response('', 404)]);

        $this->verifyCommand(['--dry-run' => true])->expectsOutputToContain('1 rejected')->run();

        $this->assertSame('https://blueheron.example/', $restaurant->fresh()?->website_url);
        $this->assertSame(0, FieldQuarantine::query()->count());
    }

    public function test_excludes_inactive_null_and_empty_urls(): void
    {
        $this->venue(['is_active' => false]);
        $this->venue(['website_url' => null]);
        $this->venue(['website_url' => '']);
        Http::fake();

        $this->verifyCommand()->expectsOutputToContain('No restaurants with website URLs to verify.')->run();

        Http::assertNothingSent();
    }

    public function test_limit_and_never_checked_first_ordering(): void
    {
        $this->venue(['website_url' => 'https://stale.example/', 'website_verified_at' => now()->subDays(45)]);
        $neverChecked = $this->venue();
        $this->fake(['https://blueheron.example/' => Http::response($this->ownSite())]);

        $this->verifyCommand(['--limit' => 1])->expectsOutputToContain('Done. 1 verified')->run();

        $this->assertNotNull($neverChecked->fresh()?->website_verified_at);
    }

    public function test_import_filled_websites_are_checked_before_the_untagged_backlog(): void
    {
        $backlog = $this->venue(['website_url' => 'https://backlog.example/']);
        $filled = $this->venue(['field_sources' => ['website_url' => 'overture:2026-08-19.0']]);
        $this->fake(['https://blueheron.example/' => Http::response($this->ownSite())]);

        $this->verifyCommand(['--limit' => 1])->expectsOutputToContain('Done. 1 verified')->run();

        $this->assertNotNull($filled->fresh()?->website_verified_at);
        $this->assertNull($backlog->fresh()?->website_verified_at);
    }

    public function test_recently_verified_rows_are_skipped_by_max_age(): void
    {
        $this->venue(['website_verified_at' => now()->subDays(5)]);
        Http::fake();

        $this->verifyCommand(['--max-age-days' => 30])->expectsOutputToContain('No restaurants with website URLs to verify.')->run();

        Http::assertNothingSent();
    }
}
