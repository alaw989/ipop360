<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ReverifySocialLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_decays_a_previously_verified_link_that_now_fails(): void
    {
        $restaurant = Restaurant::factory()->create(['social_links_count' => 1]);
        RestaurantSocialLink::create([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/now-dead',
            'verified_at' => now()->subWeek(),
        ]);

        $mock = $this->createMock(RestaurantWebsiteScraperService::class);
        $mock->method('verifyProfileUrl')->willReturn(false);
        $this->app->instance(RestaurantWebsiteScraperService::class, $mock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:reverify-social-links');
        $command->assertSuccessful()->run();

        $restaurant->refresh();
        $this->assertSame(0, $restaurant->social_links_count);
        $link = RestaurantSocialLink::where('restaurant_id', $restaurant->id)->firstOrFail();
        $this->assertNull($link->verified_at);
        $this->assertNotNull($link->last_check_failed_at);
    }

    public function test_gives_a_previously_failed_link_another_chance(): void
    {
        $restaurant = Restaurant::factory()->create(['social_links_count' => 0]);
        RestaurantSocialLink::create([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/back-up',
            'last_check_failed_at' => now()->subWeek(),
        ]);

        $mock = $this->createMock(RestaurantWebsiteScraperService::class);
        $mock->method('verifyProfileUrl')->willReturn(true);
        $this->app->instance(RestaurantWebsiteScraperService::class, $mock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:reverify-social-links');
        $command->assertSuccessful()->run();

        $restaurant->refresh();
        $this->assertSame(1, $restaurant->social_links_count);
        $link = RestaurantSocialLink::where('restaurant_id', $restaurant->id)->firstOrFail();
        $this->assertNotNull($link->verified_at);
        $this->assertNull($link->last_check_failed_at);
    }

    public function test_reports_no_links_due_when_none_exist(): void
    {
        $mock = $this->createMock(RestaurantWebsiteScraperService::class);
        $mock->expects($this->never())->method('verifyProfileUrl');
        $this->app->instance(RestaurantWebsiteScraperService::class, $mock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:reverify-social-links');
        $command->assertSuccessful()
            ->expectsOutputToContain('No social links due for re-verification.');
        $command->run();
    }

    public function test_limit_option_bounds_the_number_of_links_checked(): void
    {
        $restaurant = Restaurant::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            RestaurantSocialLink::create([
                'restaurant_id' => $restaurant->id,
                'platform' => "platform{$i}",
                'url' => "https://example.com/{$i}",
                'verified_at' => now()->subDays($i),
            ]);
        }

        $mock = $this->createMock(RestaurantWebsiteScraperService::class);
        $mock->expects($this->exactly(2))->method('verifyProfileUrl')->willReturn(true);
        $this->app->instance(RestaurantWebsiteScraperService::class, $mock);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:reverify-social-links', ['--limit' => 2]);
        $command->assertSuccessful()->run();
    }
}
