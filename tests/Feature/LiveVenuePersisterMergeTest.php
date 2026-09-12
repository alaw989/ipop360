<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\CuisineMatcher;
use App\Services\GeolocationService;
use App\Services\LiveVenuePersister;
use App\Services\RestaurantValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * A live-search venue that matches a stored row (by google_place_id or slug)
 * must not erase what the row already has, nor reactivate a closed row.
 */
class LiveVenuePersisterMergeTest extends TestCase
{
    use RefreshDatabase;

    private LiveVenuePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->persister = new LiveVenuePersister(
            app(RestaurantValidationService::class),
            app(CuisineMatcher::class),
            Mockery::mock(GeolocationService::class),
        );
    }

    public function test_sparse_live_venue_keeps_the_stored_fields(): void
    {
        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_rich',
            'phone' => '5125550100',
            'website_url' => 'https://example-cafe.test',
            'address' => '100 Congress Ave',
            'google_rating' => 4.4,
            'google_review_count' => 812,
            'photos' => ['https://example-cafe.test/1.jpg'],
            'is_active' => false,
        ]);

        $this->persister->persist([
            'google_place_id' => 'place_rich',
            'name' => $restaurant->name,
            'popularity_score' => 0.42,
        ]);

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('5125550100', $fresh->phone);
        $this->assertSame('https://example-cafe.test', $fresh->website_url);
        $this->assertSame('100 Congress Ave', $fresh->address);
        $this->assertSame(4.4, $fresh->google_rating);
        $this->assertSame(812, $fresh->google_review_count);
        $this->assertSame(['https://example-cafe.test/1.jpg'], $fresh->photos);
        $this->assertFalse($fresh->is_active);
        $this->assertSame(0.42, $fresh->popularity_score, 'the live score is still written on update');
    }

    public function test_live_venue_refreshes_rating_and_fills_blanks(): void
    {
        $restaurant = Restaurant::factory()->create([
            'google_place_id' => 'place_thin',
            'phone' => null,
            'google_rating' => 4.0,
            'google_review_count' => 90,
        ]);

        $this->persister->persist([
            'google_place_id' => 'place_thin',
            'name' => $restaurant->name,
            'phone' => '(512) 555-0199',
            'google_rating' => 4.3,
            'google_review_count' => 120,
        ]);

        $fresh = $restaurant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('5125550199', $fresh->phone);
        $this->assertSame(4.3, $fresh->google_rating);
        $this->assertSame(120, $fresh->google_review_count);
    }
}
