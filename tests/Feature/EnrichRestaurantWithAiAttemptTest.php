<?php

namespace Tests\Feature;

use App\Exceptions\AiProvidersUnavailableException;
use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\CuisineTagMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Which outcomes mark a row as tried. A provider outage must leave the row
 * eligible (it was never looked at); an answer with nothing usable must mark
 * it, so the scheduler rotates on instead of re-sending the same rows.
 */
class EnrichRestaurantWithAiAttemptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.ai.api_key', 'test-key');
    }

    private function runJob(Restaurant $restaurant, \Closure $answer): Restaurant
    {
        /** @var AiEnrichmentService&Mockery\MockInterface $ai */
        $ai = Mockery::mock(AiEnrichmentService::class);
        $ai->shouldReceive('enrichRestaurant')->once()->andReturnUsing($answer);

        (new EnrichRestaurantWithAi($restaurant->id))->handle($ai, app(CuisineTagMapper::class));

        return Restaurant::query()->whereKey($restaurant->id)->firstOrFail();
    }

    public function test_provider_outage_leaves_the_row_untouched_and_logs_nothing(): void
    {
        $log = Log::spy();
        $metadata = ['enriched_at' => now()->subDays(40)->toISOString(), 'fields_updated' => ['description']];
        $restaurant = Restaurant::factory()->create(['ai_metadata' => $metadata]);

        $fresh = $this->runJob($restaurant, fn () => throw new AiProvidersUnavailableException);

        $this->assertSame($metadata, $fresh->ai_metadata);
        $log->shouldNotHaveReceived('warning');
    }

    public function test_unusable_answer_stamps_the_attempt_and_keeps_provenance(): void
    {
        $this->freezeTime();
        $enrichedAt = now()->subDays(40)->toISOString();
        $restaurant = Restaurant::factory()->create([
            'ai_metadata' => ['enriched_at' => $enrichedAt, 'fields_updated' => ['description']],
        ]);

        $fresh = $this->runJob($restaurant, fn () => null);

        $this->assertSame(now()->toISOString(), $fresh->ai_metadata['attempted_at'] ?? null);
        $this->assertSame($enrichedAt, $fresh->ai_metadata['enriched_at'] ?? null);
        $this->assertSame(['description'], $fresh->ai_metadata['fields_updated'] ?? null);
        $this->assertSame(now()->toISOString(), $fresh->lastAiAttemptAt()?->toISOString());
    }

    public function test_without_a_key_nothing_is_stamped(): void
    {
        Config::set('services.ai.api_key', null);
        $restaurant = Restaurant::factory()->create(['ai_metadata' => null]);

        $fresh = $this->runJob($restaurant, fn () => null);

        $this->assertNull($fresh->ai_metadata);
        $this->assertNull($fresh->lastAiAttemptAt());
    }

    public function test_last_attempt_is_the_later_of_enrichment_and_attempt(): void
    {
        $restaurant = new Restaurant([
            'ai_metadata' => [
                'enriched_at' => '2026-08-01T00:00:00.000000Z',
                'attempted_at' => '2026-07-01T00:00:00.000000Z',
            ],
        ]);

        $this->assertSame('2026-08-01', $restaurant->lastAiAttemptAt()?->toDateString());

        $restaurant->ai_metadata = ['attempted_at' => '2026-09-01T00:00:00.000000Z', 'enriched_at' => '2026-08-01T00:00:00.000000Z'];
        $this->assertSame('2026-09-01', $restaurant->lastAiAttemptAt()?->toDateString());
    }
}
