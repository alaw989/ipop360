<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class UpdateEngagementCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_engagement_counters_correctly(): void
    {
        $restaurant = Restaurant::factory()->create();

        DB::table('restaurant_engagement')->insert([
            ['restaurant_id' => $restaurant->id, 'action_type' => 'website_click'],
            ['restaurant_id' => $restaurant->id, 'action_type' => 'website_click'],
            ['restaurant_id' => $restaurant->id, 'action_type' => 'directions_click'],
            ['restaurant_id' => $restaurant->id, 'action_type' => 'pageview'],
            ['restaurant_id' => $restaurant->id, 'action_type' => 'pageview'],
            ['restaurant_id' => $restaurant->id, 'action_type' => 'pageview'],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:update-engagement');
        $command->assertSuccessful();
        $command->run();

        $restaurant->refresh();

        $this->assertSame(2, $restaurant->website_clicks_count);
        $this->assertSame(1, $restaurant->directions_clicks_count);
        $this->assertSame(3, $restaurant->pageviews_count);
        $this->assertSame(0, $restaurant->call_clicks_count);
        $this->assertSame(0, $restaurant->social_link_clicks_count);
        $this->assertSame(0, $restaurant->menu_click_count);
        $this->assertSame(6, $restaurant->total_engagement);
    }

    public function test_handles_empty_engagement_table(): void
    {
        Restaurant::factory()->create();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:update-engagement');
        $command->assertSuccessful()
            ->expectsOutputToContain('Engagement counters updated successfully.');
        $command->run();
    }

    public function test_updates_multiple_restaurants_independently(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();

        DB::table('restaurant_engagement')->insert([
            ['restaurant_id' => $restaurantA->id, 'action_type' => 'website_click'],
            ['restaurant_id' => $restaurantA->id, 'action_type' => 'website_click'],
            ['restaurant_id' => $restaurantB->id, 'action_type' => 'call_click'],
            ['restaurant_id' => $restaurantB->id, 'action_type' => 'call_click'],
            ['restaurant_id' => $restaurantB->id, 'action_type' => 'call_click'],
            ['restaurant_id' => $restaurantB->id, 'action_type' => 'pageview'],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:update-engagement');
        $command->assertSuccessful();
        $command->run();

        $restaurantA->refresh();
        $restaurantB->refresh();

        $this->assertSame(2, $restaurantA->website_clicks_count);
        $this->assertSame(2, $restaurantA->total_engagement);

        $this->assertSame(3, $restaurantB->call_clicks_count);
        $this->assertSame(1, $restaurantB->pageviews_count);
        $this->assertSame(4, $restaurantB->total_engagement);
    }

    public function test_ignores_restaurants_without_engagement(): void
    {
        $restaurantWithEngagement = Restaurant::factory()->create();
        $restaurantWithout = Restaurant::factory()->create();

        DB::table('restaurant_engagement')->insert([
            ['restaurant_id' => $restaurantWithEngagement->id, 'action_type' => 'website_click'],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:update-engagement');
        $command->assertSuccessful();
        $command->run();

        $restaurantWithout->refresh();

        $this->assertSame(0, $restaurantWithout->total_engagement);
        $this->assertSame(1, $restaurantWithEngagement->fresh()?->total_engagement);
    }
}
