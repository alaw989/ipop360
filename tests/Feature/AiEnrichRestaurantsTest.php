<?php

namespace Tests\Feature;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class AiEnrichRestaurantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.ai.api_key', 'test-key');
        Queue::fake();
    }

    public function test_skips_restaurants_enriched_within_window(): void
    {
        $fresh = Restaurant::factory()->create([
            'website_url' => 'https://fresh.example.com',
            'ai_metadata' => ['enriched_at' => now()->subDays(2)->toISOString()],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:ai-enrich');
        $command->assertExitCode(0);
        $command->run();

        Queue::assertNotPushed(EnrichRestaurantWithAi::class, fn ($job) => $job->restaurantId === $fresh->id);
    }

    public function test_re_enriches_missing_field_rows_after_one_day(): void
    {
        $needy = Restaurant::factory()->create([
            'price_range' => null,
            'description' => null,
            'phone' => null,
            'ai_metadata' => ['enriched_at' => now()->subDays(2)->toISOString()],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:ai-enrich');
        $command->assertExitCode(0);
        $command->run();

        Queue::assertPushed(EnrichRestaurantWithAi::class, fn ($job) => $job->restaurantId === $needy->id);
    }

    public function test_dispatches_neediest_restaurants_first(): void
    {
        $sparse = Restaurant::factory()->create([
            'price_range' => null,
            'description' => null,
            'phone' => null,
            'website_url' => null,
        ]);

        $mid = Restaurant::factory()->create([
            'price_range' => null,
            'description' => null,
            'phone' => '555-0100',
            'website_url' => 'https://example.com',
        ]);

        $complete = Restaurant::factory()->create([
            'price_range' => '$$',
            'description' => 'Complete.',
            'phone' => '555-0200',
            'website_url' => 'https://full.example.com',
            'ai_metadata' => ['enriched_at' => now()->subDays(20)->toISOString()],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:ai-enrich');
        $command->assertExitCode(0);
        $command->run();

        /** @var array<int, EnrichRestaurantWithAi> $jobs */
        $jobs = Queue::pushed(EnrichRestaurantWithAi::class);
        $pushed = collect($jobs)->map->restaurantId->all();

        $this->assertSame([$sparse->id, $mid->id, $complete->id], $pushed);
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        Restaurant::factory()->create();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:ai-enrich', ['--dry-run' => true]);
        $command->assertExitCode(0);
        $command->run();

        Queue::assertNothingPushed();
    }
}
