<?php

namespace Tests\Feature;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\CuisineTagMapper;
use Database\Seeders\CuisineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pin the AI enrichment job's cuisine-tag side effect: AI-detected cuisines
 * are attached to the cuisine_restaurant pivot so cuisine_match (0.50) can
 * fire for previously untagged rows. Free-text AI names are normalized to
 * seeded cuisine slugs ("Pizza" is not a seeded cuisine and is skipped, mirror
 * of the audit sweep), and existing tags are never detached.
 */
class EnrichRestaurantWithAiCuisineAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CuisineSeeder::class);
    }

    /**
     * @return array<int, string>
     */
    private function tags(int $restaurantId): array
    {
        return Restaurant::query()->whereKey($restaurantId)->firstOrFail()->cuisines
            ->pluck('slug')->sort()->values()->all();
    }

    public function test_attaches_ai_cuisines_to_the_pivot(): void
    {
        $restaurant = Restaurant::factory()->create(['description' => null]);

        /** @var AiEnrichmentService&Mockery\MockInterface $ai */
        $ai = Mockery::mock(AiEnrichmentService::class);
        $ai->shouldReceive('enrichRestaurant')->once()->andReturn([
            'cuisines' => ['Italian', 'Pizza', 'Cajun/Creole'],
        ]);

        $job = new EnrichRestaurantWithAi($restaurant->id);
        $job->handle($ai, app(CuisineTagMapper::class));

        $this->assertSame(['cajun-creole', 'italian'], $this->tags($restaurant->id));
    }

    public function test_keeps_existing_tags_when_attaching_ai_cuisines(): void
    {
        $restaurant = Restaurant::factory()->create();
        $mexican = Cuisine::where('slug', 'mexican')->firstOrFail();
        $restaurant->cuisines()->attach($mexican->id);

        /** @var AiEnrichmentService&Mockery\MockInterface $ai */
        $ai = Mockery::mock(AiEnrichmentService::class);
        $ai->shouldReceive('enrichRestaurant')->once()->andReturn([
            'cuisines' => ['Italian'],
        ]);

        $job = new EnrichRestaurantWithAi($restaurant->id);
        $job->handle($ai, app(CuisineTagMapper::class));

        $this->assertSame(['italian', 'mexican'], $this->tags($restaurant->id));
    }

    public function test_leaves_pivot_untouched_when_ai_returns_no_cuisines(): void
    {
        $restaurant = Restaurant::factory()->create();

        /** @var AiEnrichmentService&Mockery\MockInterface $ai */
        $ai = Mockery::mock(AiEnrichmentService::class);
        $ai->shouldReceive('enrichRestaurant')->once()->andReturn([]);

        $job = new EnrichRestaurantWithAi($restaurant->id);
        $job->handle($ai, app(CuisineTagMapper::class));

        $this->assertSame([], $this->tags($restaurant->id));
    }
}
