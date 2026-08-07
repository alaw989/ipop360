<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\LiveVenuePersister;
use App\Services\RestaurantValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * spec-104 audit: live-search persistence must never clobber has_award set by
 * enrichment. Source arrays hardcode has_award => false, so the update path
 * must exclude it.
 */
class LiveVenuePersisterAwardTest extends TestCase
{
    use RefreshDatabase;

    private LiveVenuePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persister = new LiveVenuePersister(
            app(RestaurantValidationService::class)
        );
    }

    /** @return array<string, mixed> */
    private function venue(string $googlePlaceId, string $name, bool $hasAward): array
    {
        return [
            'google_place_id' => $googlePlaceId,
            'slug' => Str::slug($name),
            'name' => $name,
            'has_award' => $hasAward,
            'popularity_score' => 0.5,
        ];
    }

    public function test_update_preserves_has_award_true(): void
    {
        $restaurant = Restaurant::factory()->create([
            'has_award' => true,
            'google_place_id' => 'place_123',
        ]);

        $this->persister->persist($this->venue('place_123', $restaurant->name, false));

        $this->assertTrue($restaurant->fresh()->has_award, 'enrichment award must survive a live-search update');
    }

    public function test_create_sets_has_award_from_source(): void
    {
        $result = $this->persister->persist($this->venue('place_new', 'New Place', true));

        $this->assertTrue($result['created']);
        $this->assertTrue($result['restaurant']->has_award);
    }

    public function test_create_defaults_has_award_false_when_absent(): void
    {
        $venue = $this->venue('place_new2', 'New Place 2', false);
        unset($venue['has_award']);

        $result = $this->persister->persist($venue);

        $this->assertFalse($result['restaurant']->has_award);
    }
}
