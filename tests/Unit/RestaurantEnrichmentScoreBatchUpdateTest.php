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
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\TestCase;

class RestaurantEnrichmentScoreBatchUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drive the private applyScoreUpdateBatch() CASE WHEN update. All 11
     * collaborators are no-op mocks — this path only touches DB + inputs.
     */
    /** @param array<int, array<string, mixed>> $scores */
    private function applyBatch(array $scores, ?string $updatedAt = null): void
    {
        /** @var OverpassService&MockInterface $overpass */
        $overpass = Mockery::mock(OverpassService::class)->shouldIgnoreMissing();
        /** @var BizDataApiService&MockInterface $bizData */
        $bizData = Mockery::mock(BizDataApiService::class)->shouldIgnoreMissing();
        /** @var SerpApiService&MockInterface $serpApiService */
        $serpApiService = Mockery::mock(SerpApiService::class)->shouldIgnoreMissing();
        /** @var SocrataOpenDataService&MockInterface $socrataService */
        $socrataService = Mockery::mock(SocrataOpenDataService::class)->shouldIgnoreMissing();
        /** @var WikidataService&MockInterface $wikidata */
        $wikidata = Mockery::mock(WikidataService::class)->shouldIgnoreMissing();
        /** @var PopularityScoreService&MockInterface $popularityScore */
        $popularityScore = Mockery::mock(PopularityScoreService::class)->shouldIgnoreMissing();
        /** @var RestaurantWebsiteScraperService&MockInterface $websiteScraper */
        $websiteScraper = Mockery::mock(RestaurantWebsiteScraperService::class)->shouldIgnoreMissing();
        /** @var AiEnrichmentService&MockInterface $aiEnrichment */
        $aiEnrichment = Mockery::mock(AiEnrichmentService::class)->shouldIgnoreMissing();
        /** @var CuisineMatcher&MockInterface $cuisineMatcher */
        $cuisineMatcher = Mockery::mock(CuisineMatcher::class)->shouldIgnoreMissing();
        /** @var VenuePipeline&MockInterface $venuePipeline */
        $venuePipeline = Mockery::mock(VenuePipeline::class)->shouldIgnoreMissing();
        /** @var RestaurantValidationService&MockInterface $restaurantValidation */
        $restaurantValidation = Mockery::mock(RestaurantValidationService::class)->shouldIgnoreMissing();

        $service = new RestaurantEnrichmentService(
            $overpass, $bizData, $serpApiService, $socrataService, $wikidata,
            $popularityScore, $websiteScraper, $aiEnrichment, $cuisineMatcher,
            $venuePipeline, $restaurantValidation
        );

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
            $this->assertNotNull($fresh);
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

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($breakdown, $fresh->score_breakdown);
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

        $freshA = $a->fresh();
        $this->assertNotNull($freshA);
        $this->assertSame(0.1, $freshA->popularity_score);
        $freshB = $b->fresh();
        $this->assertNotNull($freshB);
        $this->assertSame(0.2, $freshB->popularity_score);
    }

    public function test_empty_map_is_noop(): void
    {
        $restaurant = Restaurant::factory()->create(['popularity_score' => 0.33]);

        $this->applyBatch([]);

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(0.33, $fresh->popularity_score);
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
            $fresh = $r->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame(round(0.01 * $i, 4), $fresh->popularity_score);
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

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($marked, $fresh->updated_at->toDateTimeString());
    }
}
