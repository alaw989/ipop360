<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class VerifyRestaurantWebsitesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_warns_when_no_restaurants_with_website_urls(): void
    {
        Http::fake();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants with website URLs to verify.');
        $command->run();
    }

    public function test_counts_successful_response_as_verified(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 200),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 alive, 0 dead, 0 skipped (transient).');
        $command->run();
    }

    public function test_404_response_counts_as_dead_and_nulls_url(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 404),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 0 alive, 1 dead, 0 skipped (transient).');
        $command->run();

        $restaurant->refresh();

        $this->assertNull($restaurant->website_url);
    }

    public function test_410_response_counts_as_dead_and_nulls_url(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 410),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 0 alive, 1 dead, 0 skipped (transient).');
        $command->run();

        $restaurant->refresh();

        $this->assertNull($restaurant->website_url);
    }

    public function test_non_dead_error_counts_as_skipped_and_keeps_url(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 500),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 0 alive, 0 dead, 1 skipped (transient).');
        $command->run();

        $restaurant->refresh();

        $this->assertSame('https://example.com', $restaurant->website_url);
    }

    public function test_network_exception_counts_as_skipped_and_keeps_url(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => fn () => throw new ConnectionException('timeout'),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 0 alive, 0 dead, 1 skipped (transient).');
        $command->run();

        $restaurant->refresh();

        $this->assertSame('https://example.com', $restaurant->website_url);
    }

    public function test_dry_run_does_not_null_dead_url(): void
    {
        Log::shouldReceive('channel')->with('enrichment')->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Website URL verification complete' && ($context['dry_run'] ?? false) === true);

        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 404),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites', ['--dry-run' => true]);
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 0 alive, 1 dead, 0 skipped (transient).');
        $command->run();

        $restaurant->refresh();

        $this->assertSame('https://example.com', $restaurant->website_url);
    }

    public function test_excludes_inactive_restaurants(): void
    {
        Restaurant::factory()->create([
            'is_active' => false,
            'website_url' => 'https://example.com',
        ]);

        Http::fake();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants with website URLs to verify.');
        $command->run();

        Http::assertNothingSent();
    }

    public function test_excludes_null_website_url(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => null,
        ]);

        Http::fake();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants with website URLs to verify.');
        $command->run();

        Http::assertNothingSent();
    }

    public function test_excludes_empty_website_url(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => '',
        ]);

        Http::fake();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants with website URLs to verify.');
        $command->run();

        Http::assertNothingSent();
    }

    public function test_processes_multiple_restaurants_with_mixed_results(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://alive.com',
        ]);
        $deadRestaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://dead.com',
        ]);
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://transient.com',
        ]);

        Http::fake([
            'alive.com' => Http::response('', 200),
            'dead.com' => Http::response('', 404),
            'transient.com' => Http::response('', 503),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 alive, 1 dead, 1 skipped (transient).');
        $command->run();

        $deadRestaurant->refresh();

        $this->assertNull($deadRestaurant->website_url);
    }

    public function test_limit_option_restricts_count(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://first.com',
        ]);
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://second.com',
        ]);

        Http::fake([
            'first.com' => Http::response('', 200),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites', ['--limit' => 1]);
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 alive, 0 dead, 0 skipped (transient).');
        $command->run();
    }

    public function test_successful_check_stamps_verified_at(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'website_verified_at' => null,
        ]);

        Http::fake(['example.com' => Http::response('', 200)]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites');
        $command->run();

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->website_verified_at);
    }

    public function test_recently_verified_restaurant_is_excluded_by_max_age_days(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://recent.com',
            'website_verified_at' => now()->subDays(5),
        ]);

        Http::fake();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites', ['--max-age-days' => 30]);
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants with website URLs to verify.');
        $command->run();

        Http::assertNothingSent();
    }

    public function test_stale_restaurant_beyond_max_age_days_is_rechecked(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://stale.com',
            'website_verified_at' => now()->subDays(45),
        ]);

        Http::fake(['stale.com' => Http::response('', 200)]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites', ['--max-age-days' => 30]);
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 alive, 0 dead, 0 skipped (transient).');
        $command->run();

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->website_verified_at);
    }

    public function test_never_verified_restaurants_are_prioritized_over_previously_checked(): void
    {
        // Verified 45 days ago — stale, but still not the priority: a
        // never-checked row must be ordered ahead of it regardless.
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://neverchecked-would-lose.com',
            'website_verified_at' => now()->subDays(45),
        ]);
        // Never verified — must be checked first regardless of id order.
        $neverChecked = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://neverchecked.com',
            'website_verified_at' => null,
        ]);

        Http::fake([
            'neverchecked.com' => Http::response('', 200),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:verify-websites', ['--limit' => 1]);
        $command->assertSuccessful()
            ->expectsOutputToContain('Done. 1 alive, 0 dead, 0 skipped (transient).');
        $command->run();

        $fresh = $neverChecked->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->website_verified_at);
    }
}
