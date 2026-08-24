<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Restaurant::scopeTrendingQualified() gates the homepage "Trending
 * restaurants" section — see HomeController::getHomepageData(). Before this,
 * the only gate was is_active, letting a barely-populated, unrated,
 * photo-less row surface under "Top-ranked dining spots right now" purely by
 * having a recent updated_at (low decay).
 */
class RestaurantTrendingQualifiedScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_excludes_a_restaurant_below_the_score_floor(): void
    {
        Restaurant::factory()->create([
            'popularity_score' => 0.1,
            'photo_url' => 'https://example.com/photo.jpg',
        ]);

        $this->assertSame(0, Restaurant::query()->trendingQualified()->count());
    }

    public function test_includes_a_restaurant_at_or_above_the_score_floor_with_a_photo(): void
    {
        Restaurant::factory()->create([
            'popularity_score' => 0.4,
            'photo_url' => 'https://example.com/photo.jpg',
        ]);

        $this->assertSame(1, Restaurant::query()->trendingQualified()->count());
    }

    public function test_excludes_a_restaurant_with_no_photo_even_above_the_score_floor(): void
    {
        Restaurant::factory()->create([
            'popularity_score' => 0.9,
            'photo_url' => null,
        ]);

        $this->assertSame(0, Restaurant::query()->trendingQualified()->count());
    }

    public function test_excludes_a_restaurant_with_an_empty_string_photo_url(): void
    {
        Restaurant::factory()->create([
            'popularity_score' => 0.9,
            'photo_url' => '',
        ]);

        $this->assertSame(0, Restaurant::query()->trendingQualified()->count());
    }

    public function test_kill_switch_reverts_to_no_floor(): void
    {
        config(['restaurant-finder.trending.require_quality_floor' => false]);

        Restaurant::factory()->create([
            'popularity_score' => 0.0,
            'photo_url' => null,
        ]);

        $this->assertSame(1, Restaurant::query()->trendingQualified()->count());
    }

    public function test_require_photo_kill_switch_allows_score_only_gate(): void
    {
        config(['restaurant-finder.trending.require_photo' => false]);

        Restaurant::factory()->create([
            'popularity_score' => 0.5,
            'photo_url' => null,
        ]);

        $this->assertSame(1, Restaurant::query()->trendingQualified()->count());
    }

    public function test_custom_min_score_config_is_respected(): void
    {
        config(['restaurant-finder.trending.min_popularity_score' => 0.8]);

        Restaurant::factory()->create([
            'popularity_score' => 0.5,
            'photo_url' => 'https://example.com/photo.jpg',
        ]);

        $this->assertSame(0, Restaurant::query()->trendingQualified()->count());
    }
}
