<?php

namespace Tests\Feature;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use App\Services\CuisineMatcher;
use App\Services\GeolocationService;
use App\Services\LiveVenuePersister;
use App\Services\RestaurantValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Pin ingestion-time AI enrichment dispatch in LiveVenuePersister::persist.
 * A freshly CREATED row missing any of description/price_range/phone queues
 * EnrichRestaurantWithAi so it is rich within minutes, but an update (or a
 * create with all three present) never queues it.
 */
class LiveVenuePersisterAiEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private LiveVenuePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $geo = Mockery::mock(GeolocationService::class);

        $this->persister = new LiveVenuePersister(
            app(RestaurantValidationService::class),
            app(CuisineMatcher::class),
            $geo,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function venue(string $googlePlaceId, string $name, array $overrides = []): array
    {
        return array_merge([
            'google_place_id' => $googlePlaceId,
            'slug' => Str::slug($name),
            'name' => $name,
            'popularity_score' => 0.5,
        ], $overrides);
    }

    public function test_create_missing_fields_queues_ai_enrichment(): void
    {
        $result = $this->persister->persist($this->venue('place_ai', 'Enrich Me'));

        $this->assertTrue($result['created']);

        Queue::assertPushed(EnrichRestaurantWithAi::class, fn ($job) => $job->restaurantId === $result['restaurant']->id);
    }

    public function test_create_with_all_fields_does_not_queue_ai_enrichment(): void
    {
        $this->persister->persist($this->venue('place_full', 'Fully Enriched', [
            'description' => 'A cozy neighborhood bistro.',
            'price_range' => '$$',
            'phone' => '+1 813 555 0123',
        ]));

        Queue::assertNotPushed(EnrichRestaurantWithAi::class);
    }

    public function test_update_does_not_queue_ai_enrichment(): void
    {
        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_update',
            'description' => null,
            'price_range' => null,
            'phone' => null,
        ]);

        $this->persister->persist($this->venue('place_update', $restaurant->name));

        Queue::assertNotPushed(EnrichRestaurantWithAi::class);
    }
}
