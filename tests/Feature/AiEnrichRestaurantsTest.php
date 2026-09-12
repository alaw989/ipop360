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

    public function test_rows_the_ai_tried_recently_wait_out_the_retry_window(): void
    {
        // Missing fields no longer bring a row back after a day: the model
        // has no browsing, so an early retry just repeats the last answer.
        $enriched = Restaurant::factory()->create([
            'description' => null,
            'ai_metadata' => ['enriched_at' => now()->subDays(29)->toISOString()],
        ]);
        $unusable = Restaurant::factory()->create([
            'description' => null,
            'ai_metadata' => ['attempted_at' => now()->subDays(2)->toISOString()],
        ]);
        $due = Restaurant::factory()->create([
            'description' => null,
            'ai_metadata' => ['enriched_at' => now()->subDays(31)->toISOString()],
        ]);

        $this->runCommand();

        $this->assertSame([$due->id], $this->pushedIds());
        Queue::assertNotPushed(EnrichRestaurantWithAi::class, fn ($job) => in_array($job->restaurantId, [$enriched->id, $unusable->id], true));
    }

    public function test_never_tried_rows_go_before_rows_due_for_a_retry(): void
    {
        $retry = Restaurant::factory()->create([
            'description' => null,
            'website_url' => null,
            'address' => null,
            'popularity_score' => 0.9,
            'ai_metadata' => ['enriched_at' => now()->subDays(40)->toISOString()],
        ]);
        $fresh = Restaurant::factory()->create([
            'description' => null,
            'website_url' => 'https://fresh.example.com',
            'popularity_score' => 0.1,
        ]);

        $this->runCommand();

        $this->assertSame([$fresh->id, $retry->id], $this->pushedIds());
    }

    public function test_dispatches_at_most_the_per_run_cap_spread_over_six_hours(): void
    {
        Config::set('services.ai.enrich_per_run', 4);
        $this->freezeTime();
        Restaurant::factory()->count(6)->create(['description' => null]);

        $this->runCommand();

        /** @var array<int, EnrichRestaurantWithAi> $jobs */
        $jobs = array_values(Queue::pushed(EnrichRestaurantWithAi::class)->all()); /* @phpstan-ignore staticMethod.notFound */
        $this->assertCount(4, $jobs);

        $delays = [];
        foreach ($jobs as $job) {
            $this->assertInstanceOf(\DateTimeInterface::class, $job->delay);
            $delays[] = (int) now()->diffInSeconds($job->delay);
        }
        $this->assertSame([0, 5400, 10800, 16200], $delays);
    }

    public function test_limit_option_overrides_the_per_run_cap(): void
    {
        Restaurant::factory()->count(3)->create(['description' => null]);

        $this->runCommand(['--limit' => 2]);

        $this->assertCount(2, $this->pushedIds());
    }

    public function test_specific_ids_dispatch_immediately(): void
    {
        $this->freezeTime();
        $restaurants = Restaurant::factory()->count(2)->create([
            'ai_metadata' => ['enriched_at' => now()->subDay()->toISOString()],
        ]);

        $this->runCommand(['--id' => $restaurants->pluck('id')->all()]);

        /** @var array<int, EnrichRestaurantWithAi> $jobs */
        $jobs = array_values(Queue::pushed(EnrichRestaurantWithAi::class)->all()); /* @phpstan-ignore staticMethod.notFound */
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertInstanceOf(\DateTimeInterface::class, $job->delay);
            $this->assertSame(0, (int) now()->diffInSeconds($job->delay));
        }
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
            'ai_metadata' => ['enriched_at' => now()->subDays(40)->toISOString()],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:ai-enrich');
        $command->assertExitCode(0);
        $command->run();

        /** @var array<int, EnrichRestaurantWithAi> $jobs */
        $jobs = Queue::pushed(EnrichRestaurantWithAi::class); /* @phpstan-ignore staticMethod.notFound */
        $pushed = collect($jobs)->map->restaurantId->all();

        /** @var array<int, int> $expectedIds */
        $expectedIds = [$sparse->id, $mid->id, $complete->id];
        $this->assertSame($expectedIds, $pushed);
    }

    public function test_among_equally_needy_higher_popularity_dispatches_first(): void
    {
        $low = Restaurant::factory()->create([
            'price_range' => null,
            'description' => null,
            'phone' => null,
            'website_url' => null,
            'popularity_score' => 0.1,
        ]);
        $high = Restaurant::factory()->create([
            'price_range' => null,
            'description' => null,
            'phone' => null,
            'website_url' => null,
            'popularity_score' => 0.9,
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:ai-enrich');
        $command->assertExitCode(0);
        $command->run();

        /** @var array<int, EnrichRestaurantWithAi> $jobs */
        $jobs = Queue::pushed(EnrichRestaurantWithAi::class); /* @phpstan-ignore staticMethod.notFound */
        $pushed = collect($jobs)->map->restaurantId->all();

        $this->assertSame([$high->id, $low->id], $pushed);
    }

    /** @param array<string, mixed> $options */
    private function runCommand(array $options = []): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:ai-enrich', $options);
        $command->assertExitCode(0);
        $command->run();
    }

    /** @return array<int, int> */
    private function pushedIds(): array
    {
        /** @var array<int, EnrichRestaurantWithAi> $jobs */
        $jobs = Queue::pushed(EnrichRestaurantWithAi::class); /* @phpstan-ignore staticMethod.notFound */

        return collect($jobs)->map->restaurantId->values()->all();
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
