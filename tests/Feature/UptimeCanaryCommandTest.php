<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class UptimeCanaryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_ok_when_all_healthy(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 2,
        ]);

        DB::table('restaurant_social_links')->insert([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/test',
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subHours(1),
        ]);

        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'test-1',
            'data' => ['name' => 'Test'],
            'fetched_at' => now()->subDays(5),
            'expires_at' => now()->addDays(25),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('uptime:canary');
        $command->assertSuccessful()
            ->expectsOutputToContain('Database: connected')
            ->expectsOutputToContain('Overall status: ok');
        $command->run();
    }

    public function test_command_degrades_when_no_social_links_found(): void
    {
        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 0,
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('uptime:canary');
        // "degraded" no longer fails the run (only "critical" does) — a soft
        // condition like this shouldn't pollute scheduler-health telemetry.
        $command->assertSuccessful()
            ->expectsOutputToContain('no social links found');
        $command->run();
    }

    public function test_command_degrades_when_social_scrape_is_stale(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 1,
        ]);

        $staleDate = Carbon::now()->subDays(5)->toDateTimeString();

        DB::table('restaurant_social_links')->insert([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/test',
            'created_at' => $staleDate,
            'updated_at' => $staleDate,
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('uptime:canary');
        $command->assertSuccessful()
            ->expectsOutputToContain('not run in');
        $command->run();
    }

    public function test_command_degrades_when_serpapi_exhausted(): void
    {
        Config::set('restaurant-finder.serpapi.free_quota', 3);

        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 1,
        ]);

        DB::table('restaurant_social_links')->insert([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/test',
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subHours(1),
        ]);

        for ($i = 0; $i < 3; $i++) {
            ExternalApiCache::create([
                'source' => 'serpapi',
                'external_id' => "serp-{$i}",
                'data' => ['name' => "Entry {$i}"],
                'fetched_at' => now()->subDays(2),
                'expires_at' => now()->addDays(28),
            ]);
        }

        /** @var PendingCommand $command */
        $command = $this->artisan('uptime:canary');
        $command->assertSuccessful()
            ->expectsOutputToContain('exhausted');
        $command->run();
    }

    public function test_command_degrades_when_serpapi_near_circuit_breaker(): void
    {
        Config::set('restaurant-finder.serpapi.free_quota', 10);
        Config::set('restaurant-finder.serpapi.circuit_breaker_fraction', 0.6);

        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 1,
        ]);

        DB::table('restaurant_social_links')->insert([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/test',
            'created_at' => now()->subHours(1),
            'updated_at' => now()->subHours(1),
        ]);

        for ($i = 0; $i < 7; $i++) {
            ExternalApiCache::create([
                'source' => 'serpapi',
                'external_id' => "serp-{$i}",
                'data' => ['name' => "Entry {$i}"],
                'fetched_at' => now()->subDays(2),
                'expires_at' => now()->addDays(28),
            ]);
        }

        /** @var PendingCommand $command */
        $command = $this->artisan('uptime:canary');
        $command->assertSuccessful()
            ->expectsOutputToContain('near limit');
        $command->run();
    }

    public function test_degraded_cache_count_increments_on_consecutive_failures(): void
    {
        Config::set('restaurant-finder.serpapi.free_quota', 1);
        Config::set('services.alerting.webhook_url', null);

        Restaurant::factory()->create([
            'is_active' => true,
            'website_url' => 'https://example.com',
            'social_links_count' => 0,
        ]);

        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'serp-1',
            'data' => ['name' => 'Entry'],
            'fetched_at' => now()->subDays(2),
            'expires_at' => now()->addDays(28),
        ]);

        $this->assertSame(0, (int) Cache::get('uptime:degraded_count', 0));

        /** @var PendingCommand $command */
        $command = $this->artisan('uptime:canary');
        $command->run();
        $this->assertSame(1, (int) Cache::get('uptime:degraded_count', 0));

        /** @var PendingCommand $command2 */
        $command2 = $this->artisan('uptime:canary');
        $command2->run();
        $this->assertSame(2, (int) Cache::get('uptime:degraded_count', 0));
    }
}
