<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\BizDataApiService;
use App\Services\CuisineMatcher;
use App\Services\OverpassService;
use App\Services\PopularityScoreService;
use App\Services\RestaurantEnrichmentService;
use App\Services\RestaurantValidationService;
use App\Services\RestaurantWebsiteScraperService;
use App\Services\SerpApiService;
use App\Services\SocrataOpenDataService;
use App\Services\VenuePipeline;
use App\Services\WikidataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class RestaurantEnrichmentScoreBatchUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCES = [
        OverpassService::class,
        BizDataApiService::class,
        SerpApiService::class,
        SocrataOpenDataService::class,
        WikidataService::class,
        PopularityScoreService::class,
        RestaurantWebsiteScraperService::class,
        AiEnrichmentService::class,
        CuisineMatcher::class,
        VenuePipeline::class,
        RestaurantValidationService::class,
    ];

    /**
     * Drive the private applyScoreUpdateBatch() CASE WHEN update. All 11
     * collaborators are no-op mocks — this path only touches DB + inputs.
     */
    /** @param array<int, array<string, mixed>> $scores */
    private function applyBatch(array $scores, ?string $updatedAt = null): void
    {
        $mocks = [];
        foreach (self::SOURCES as $class) {
            $mocks[] = Mockery::mock($class)->shouldIgnoreMissing();
        }

        $service = new RestaurantEnrichmentService(...$mocks);

        $method = new ReflectionMethod(RestaurantEnrichmentService::class, 'applyScoreUpdateBatch');
        $method->invoke($service, $scores, $updatedAt ?? now()->toDateTimeString());
    }

    public function test_persists_score_and_breakdown_for_all_rows(): void
    {
        $restaurants = Restaurant::factory()->count(3)->create();

        $breakdown = ['total' => 0.75, 'quality' => 0.5, 'proximity' => 0.25];
        $scores = [];
        foreach ($restaurants as $i => $r) {
            $scores[$r->id] = [
                'popularity_score' => 0.40 + $i,
                'score_breakdown' => json_encode($breakdown),
            ];
        }

        $this->applyBatch($scores);

        foreach ($restaurants as $i => $r) {
            $fresh = $r->fresh();
            $this->assertSame(0.40 + $i, $fresh->popularity_score);
            $this->assertSame($breakdown, $fresh->score_breakdown);
        }
    }

    public function test_single_quote_in_breakdown_json_round_trips(): void
    {
        $restaurant = Restaurant::factory()->create();

        $breakdown = ['total' => 0.5, 'note' => "O'Brien's Cafe"];
        $this->applyBatch([
            $restaurant->id => [
                'popularity_score' => 0.5,
                'score_breakdown' => json_encode($breakdown),
            ],
        ]);

        $this->assertSame($breakdown, $restaurant->fresh()->score_breakdown);
    }

    public function test_missing_breakdown_or_absent_row_does_not_break_others(): void
    {
        $a = Restaurant::factory()->create();
        $b = Restaurant::factory()->create();

        $scores = [
            $a->id => ['popularity_score' => 0.1, 'score_breakdown' => json_encode(['total' => 0.1])],
            // id purposely not in the DB — WHERE IN just won't match, no error
            999999 => ['popularity_score' => 0.9, 'score_breakdown' => json_encode(['total' => 0.9])],
            $b->id => ['popularity_score' => 0.2, 'score_breakdown' => json_encode(['total' => 0.2])],
        ];

        $this->applyBatch($scores);

        $this->assertSame(0.1, $a->fresh()->popularity_score);
        $this->assertSame(0.2, $b->fresh()->popularity_score);
    }

    public function test_empty_map_is_noop(): void
    {
        $restaurant = Restaurant::factory()->create(['popularity_score' => 0.33]);

        $this->applyBatch([]);

        $this->assertSame(0.33, $restaurant->fresh()->popularity_score);
    }

    public function test_large_batch_chunks_into_multiple_queries_and_writes_all(): void
    {
        $restaurants = Restaurant::factory()->count(230)->create();

        $scores = [];
        foreach ($restaurants as $i => $r) {
            $scores[$r->id] = [
                'popularity_score' => round(0.01 * $i, 4),
                'score_breakdown' => json_encode(['total' => round(0.01 * $i, 4)]),
            ];
        }

        $this->applyBatch($scores);

        foreach ($restaurants as $i => $r) {
            $this->assertSame(round(0.01 * $i, 4), $r->fresh()->popularity_score);
        }
    }

    public function test_updates_updated_at_timestamp(): void
    {
        $restaurant = Restaurant::factory()->create();
        $marked = '2026-01-02 03:04:05';

        $this->applyBatch([
            $restaurant->id => [
                'popularity_score' => 0.6,
                'score_breakdown' => json_encode(['total' => 0.6]),
            ],
        ], $marked);

        $this->assertSame($marked, $restaurant->fresh()->updated_at->toDateTimeString());
    }
}
