<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ScoreRestaurantsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_active_restaurants_returns_success_as_noop(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:score');
        $command->assertSuccessful()
            ->expectsOutputToContain('No restaurants found to score.');
        $command->run();
    }

    public function test_scores_active_restaurant_with_breakdown_and_rank_change(): void
    {
        $restaurant = Restaurant::factory()->create([
            'is_active' => true,
            'google_rating' => 4.5,
            'google_review_count' => 200,
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:score');
        $command->assertSuccessful()
            ->expectsOutputToContain('Scoring complete');
        $command->run();

        $restaurant->refresh();

        $this->assertGreaterThan(0, $restaurant->popularity_score);
        $this->assertIsArray($restaurant->score_breakdown);
        $this->assertArrayHasKey('total', $restaurant->score_breakdown);
        $this->assertArrayHasKey('signals', $restaurant->score_breakdown);
        $this->assertNotNull($restaurant->rank_change);
    }

    public function test_excludes_inactive_restaurants(): void
    {
        $active = Restaurant::factory()->create(['is_active' => true]);
        $inactive = Restaurant::factory()->create(['is_active' => false]);

        $activeOldScore = $active->popularity_score;
        $inactiveOldScore = $inactive->popularity_score;

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:score');
        $command->assertSuccessful()
            ->expectsOutputToContain('1 restaurants scored');
        $command->run();

        $active->refresh();
        $inactive->refresh();

        $this->assertNotSame($activeOldScore, $active->popularity_score);
        $this->assertSame($inactiveOldScore, $inactive->popularity_score);
        $this->assertNotNull($active->score_breakdown);
        $this->assertNull($inactive->score_breakdown);
    }

    public function test_city_option_filters_restaurants(): void
    {
        $nashville = Restaurant::factory()->create(['is_active' => true, 'city' => 'Nashville']);
        $atlanta = Restaurant::factory()->create(['is_active' => true, 'city' => 'Atlanta']);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:score', ['--city' => 'Atlanta']);
        $command->assertSuccessful()
            ->expectsOutputToContain('1 restaurants scored');
        $command->run();

        $nashville->refresh();
        $atlanta->refresh();

        $this->assertNotNull($atlanta->score_breakdown);
        $this->assertNull($nashville->score_breakdown);
    }

    public function test_rank_change_reflects_reordering(): void
    {
        $a = Restaurant::factory()->create(['is_active' => true, 'popularity_score' => 0.1]);
        $b = Restaurant::factory()->create(['is_active' => true, 'popularity_score' => 0.5]);
        $c = Restaurant::factory()->create(['is_active' => true, 'popularity_score' => 0.3]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:score');
        $command->assertSuccessful();
        $command->run();

        $a->refresh();
        $b->refresh();
        $c->refresh();

        $this->assertNotNull($a->rank_change);
        $this->assertNotNull($b->rank_change);
        $this->assertNotNull($c->rank_change);
    }
}
