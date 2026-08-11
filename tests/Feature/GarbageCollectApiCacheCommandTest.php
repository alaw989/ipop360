<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class GarbageCollectApiCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_expired_entries_reports_none(): void
    {
        ExternalApiCache::create([
            'source' => 'bizdata',
            'external_id' => 'test-1',
            'data' => ['name' => 'Test'],
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('apicache:gc');
        $command->assertSuccessful()
            ->expectsOutputToContain('No expired cache entries found.');
        $command->run();

        $this->assertSame(1, ExternalApiCache::count());
    }

    public function test_dry_run_shows_expired_without_deleting(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'exp-1',
            'data' => ['name' => 'Expired'],
            'fetched_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        ExternalApiCache::create([
            'source' => 'bizdata',
            'external_id' => 'exp-2',
            'data' => ['name' => 'Also Expired'],
            'fetched_at' => now()->subDays(5),
            'expires_at' => now()->subHour(),
        ]);

        ExternalApiCache::create([
            'source' => 'bizdata',
            'external_id' => 'fresh-1',
            'data' => ['name' => 'Fresh'],
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('apicache:gc --dry-run');
        $command->assertSuccessful()
            ->expectsOutputToContain('Dry run mode')
            ->expectsOutputToContain('Found 2 expired cache entries.');
        $command->run();

        $this->assertSame(3, ExternalApiCache::count());
    }

    public function test_dry_run_with_many_expired_shows_remaining_count(): void
    {
        for ($i = 0; $i < 15; $i++) {
            ExternalApiCache::create([
                'source' => 'bizdata',
                'external_id' => "exp-{$i}",
                'data' => ['name' => "Entry {$i}"],
                'fetched_at' => now()->subDays(2),
                'expires_at' => now()->subDay(),
            ]);
        }

        /** @var PendingCommand $command */
        $command = $this->artisan('apicache:gc --dry-run');
        $command->assertSuccessful()
            ->expectsOutputToContain('Found 15 expired cache entries.')
            ->expectsOutputToContain('... and 5 more expired entries.');
        $command->run();

        $this->assertSame(15, ExternalApiCache::count());
    }

    public function test_live_run_deletes_expired_entries(): void
    {
        ExternalApiCache::create([
            'source' => 'serpapi',
            'external_id' => 'exp-1',
            'data' => ['name' => 'Expired'],
            'fetched_at' => now()->subDays(3),
            'expires_at' => now()->subDay(),
        ]);

        ExternalApiCache::create([
            'source' => 'bizdata',
            'external_id' => 'exp-2',
            'data' => ['name' => 'Also Expired'],
            'fetched_at' => now()->subDays(2),
            'expires_at' => now()->subHour(),
        ]);

        ExternalApiCache::create([
            'source' => 'bizdata',
            'external_id' => 'fresh-1',
            'data' => ['name' => 'Fresh'],
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->assertSame(3, ExternalApiCache::count());

        /** @var PendingCommand $command */
        $command = $this->artisan('apicache:gc');
        $command->assertSuccessful()
            ->expectsOutputToContain('Found 2 expired cache entries.')
            ->expectsOutputToContain('Garbage collection complete. 2 expired entries deleted.');
        $command->run();

        $this->assertSame(1, ExternalApiCache::count());
        $this->assertNotNull(ExternalApiCache::where('external_id', 'fresh-1')->first());
        $this->assertNull(ExternalApiCache::where('external_id', 'exp-1')->first());
        $this->assertNull(ExternalApiCache::where('external_id', 'exp-2')->first());
    }

    public function test_live_run_is_idempotent(): void
    {
        ExternalApiCache::create([
            'source' => 'bizdata',
            'external_id' => 'exp-1',
            'data' => ['name' => 'Expired'],
            'fetched_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        ExternalApiCache::create([
            'source' => 'bizdata',
            'external_id' => 'fresh-1',
            'data' => ['name' => 'Fresh'],
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        // First run: delete the expired entry
        /** @var PendingCommand $command */
        $command = $this->artisan('apicache:gc');
        $command->run();
        $this->assertSame(1, ExternalApiCache::count());

        // Second run: no expired entries remain
        /** @var PendingCommand $command */
        $command = $this->artisan('apicache:gc');
        $command->assertSuccessful()
            ->expectsOutputToContain('No expired cache entries found.');
        $command->run();

        $this->assertSame(1, ExternalApiCache::count());
    }
}
