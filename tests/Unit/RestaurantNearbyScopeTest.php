<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * scopeNearby() is the DB read-path for list/search contexts (UnifiedSearchService
 * fetchDbRows, RestaurantController::leaderboard). Spec-095 trimmed its `SELECT *`
 * to an explicit column list so bbox candidates don't pull heavy unused JSON
 * (photos gallery, score_breakdown, ai_metadata, opening_hours). This test locks
 * the contract: every field the ranking/scoring path and the card/list UI read
 * must survive, and the heavy JSON must be gone.
 */
class RestaurantNearbyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function scopedNearby(float $lat, float $lng): ?Restaurant
    {
        return Restaurant::query()->nearby($lat, $lng)->first();
    }

    public function test_nearby_keeps_every_ranking_and_card_field_populated(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Test Kitchen',
            'slug' => 'test-kitchen',
            'description' => 'A real description',
            'address' => '123 Main St',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94110',
            'latitude' => 37.75,
            'longitude' => -122.45,
            'phone' => '(415) 555-0100',
            'website_url' => 'https://testkitchen.example.com',
            'price_range' => '$$',
            'photo_url' => 'https://cdn.example.com/kitchen.jpg',
            'source' => 'bizdata',
            'google_place_id' => 'g-123',
            'yelp_business_id' => 'yelp-123',
            'google_rating' => 4.5,
            'google_review_count' => 120,
            'yelp_rating' => 4.3,
            'yelp_review_count' => 80,
            'popular_times_avg_busyness' => 45.5,
            'has_award' => true,
            'popularity_score' => 0.85,
            'rank_change' => 2,
            'features' => ['outdoor_seating', 'takeout'],
            'place_types' => ['Restaurant'],
            'is_active' => true,
            'social_links_count' => 3,
            'website_clicks_count' => 500,
            'pageviews_count' => 1000,
            'social_link_clicks_count' => 10,
            'menu_click_count' => 20,
            'directions_clicks_count' => 30,
            'call_clicks_count' => 40,
        ]);

        $nearby = $this->scopedNearby(37.75, -122.45);

        $this->assertNotNull($nearby, 'A populated restaurant in the radius must be returned');
        $this->assertSame($restaurant->id, $nearby->id);

        $scoringFields = [
            'name', 'address', 'phone', 'latitude', 'longitude', 'price_range',
            'website_url', 'photo_url', 'features', 'social_links_count',
            'google_rating', 'google_review_count',
            'website_clicks_count', 'pageviews_count', 'social_link_clicks_count',
            'menu_click_count', 'directions_clicks_count', 'call_clicks_count',
            'has_award', 'distance',
        ];
        foreach ($scoringFields as $field) {
            $this->assertNotNull($nearby->getAttribute($field), "{$field} must stay populated after the select trim");
        }

        $cardFields = [
            'id', 'slug', 'description', 'city', 'state', 'postal_code',
            'popularity_score', 'rank_change', 'yelp_rating', 'yelp_review_count',
            'place_types', 'source', 'is_active', 'created_at', 'updated_at',
        ];
        foreach ($cardFields as $field) {
            $this->assertNotNull($nearby->getAttribute($field), "{$field} must stay populated after the select trim");
        }
    }

    public function test_nearby_drops_heavy_json_columns_from_the_select(): void
    {
        Restaurant::factory()->create([
            'photos' => ['https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg'],
            'score_breakdown' => ['signals' => [], 'total' => 0.5],
            'ai_metadata' => ['summary' => 'heavy AI output'],
            'opening_hours' => ['mon' => '9-5'],
            'latitude' => 37.75,
            'longitude' => -122.45,
        ]);

        $nearby = $this->scopedNearby(37.75, -122.45);

        $this->assertNotNull($nearby);
        $this->assertFalse($nearby->getAttribute('photos') !== null, 'photos JSON must not be selected for list rows');
        $this->assertFalse($nearby->getAttribute('score_breakdown') !== null, 'score_breakdown JSON must not be selected for list rows');
        $this->assertFalse($nearby->getAttribute('ai_metadata') !== null, 'ai_metadata JSON must not be selected for list rows');
        $this->assertFalse($nearby->getAttribute('opening_hours') !== null, 'opening_hours JSON must not be selected for list rows');
    }

    public function test_nearby_returns_distance_in_km(): void
    {
        Restaurant::factory()->create([
            'latitude' => 37.75,
            'longitude' => -122.45,
        ]);

        $nearby = $this->scopedNearby(37.75, -122.45);

        $this->assertNotNull($nearby);
        $this->assertIsFloat($nearby->getAttribute('distance'));
        $this->assertGreaterThanOrEqual(0.0, $nearby->getAttribute('distance'));
        $this->assertLessThan(1.0, $nearby->getAttribute('distance'), 'A co-located venue must be well inside the radius');
    }

    public function test_nearby_clamps_extreme_latitude_for_bbox_math(): void
    {
        $builder = Restaurant::query()->nearby(90.0, 0.0);

        $longitudeBounds = collect($builder->getQuery()->wheres)
            ->first(fn (array $where) => ($where['type'] ?? null) === 'between' && ($where['column'] ?? null) === 'longitude');

        $this->assertNotNull($longitudeBounds, 'longitude bbox whereBetween must exist');

        [$minLng, $maxLng] = $longitudeBounds['values'];

        $this->assertTrue(is_finite($minLng), 'min longitude bound must be finite');
        $this->assertTrue(is_finite($maxLng), 'max longitude bound must be finite');
        $this->assertLessThan(1000.0, abs($minLng), 'min longitude bound must not blow out toward a full-table scan');
        $this->assertLessThan(1000.0, abs($maxLng), 'max longitude bound must not blow out toward a full-table scan');
    }
}
