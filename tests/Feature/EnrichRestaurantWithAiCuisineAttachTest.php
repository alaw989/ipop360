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
 * are attached to the cuisine_restaurant pivot only when the venue's own
 * name/place types corroborate them (the AI's word alone once tagged a bail
 * bonds office "Southern"). Free-text AI names are normalized to seeded
 * cuisine slugs ("Pizza" is not a seeded cuisine and is skipped), all AI
 * cuisines stay recorded in ai_metadata, and existing tags are never detached.
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

    /** @param array<string, mixed> $result */
    private function runJob(Restaurant $restaurant, array $result): void
    {
        /** @var AiEnrichmentService&Mockery\MockInterface $ai */
        $ai = Mockery::mock(AiEnrichmentService::class);
        $ai->shouldReceive('enrichRestaurant')->once()->andReturn($result);

        (new EnrichRestaurantWithAi($restaurant->id))->handle($ai, app(CuisineTagMapper::class));
    }

    public function test_attaches_only_cuisines_the_venue_corroborates(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => "Luigi's Italian Trattoria",
            'description' => null,
            'place_types' => ['Cajun restaurant'],
        ]);

        $this->runJob($restaurant, ['cuisines' => ['Italian', 'Pizza', 'Cajun/Creole', 'Thai']]);

        // Italian (name) + Cajun/Creole (place type) are corroborated; Thai is
        // the AI's word alone; Pizza isn't a seeded cuisine.
        $this->assertSame(['cajun-creole', 'italian'], $this->tags($restaurant->id));
        $this->assertSame(['Italian', 'Pizza', 'Cajun/Creole', 'Thai'], $restaurant->fresh()?->ai_metadata['cuisines'] ?? null);
    }

    public function test_uncorroborated_ai_cuisine_is_not_attached(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Southern Bail Bonds',
            'description' => null,
            'place_types' => null,
        ]);

        $this->runJob($restaurant, ['cuisines' => ['Thai']]);

        $this->assertSame([], $this->tags($restaurant->id));
        $this->assertNotContains('cuisines', $restaurant->fresh()?->ai_metadata['fields_updated'] ?? []);
    }

    public function test_keeps_existing_tags_when_attaching_ai_cuisines(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Casa Italiana Ristorante']);
        $mexican = Cuisine::where('slug', 'mexican')->firstOrFail();
        $restaurant->cuisines()->attach($mexican->id);

        $this->runJob($restaurant, ['cuisines' => ['Italian']]);

        $this->assertSame(['italian', 'mexican'], $this->tags($restaurant->id));
    }

    public function test_leaves_pivot_untouched_when_ai_returns_no_cuisines(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->runJob($restaurant, []);

        $this->assertSame([], $this->tags($restaurant->id));
    }
}
