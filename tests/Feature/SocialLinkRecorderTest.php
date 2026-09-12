<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\SocialLinkRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SocialLinkRecorder is the single write path for scraped social links.
 * Pins brand scoping: a corporate account linked from every location's site
 * (528 prod Domino's rows carried @dominos) is kept but never scored, so
 * chains stop outranking independents on corporate marketing.
 */
class SocialLinkRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
        Config::set('restaurant-finder.data_integrity.social_brand_min_restaurants', 3);
    }

    private function profilesReachable(bool $reachable = true): void
    {
        Http::fake(['*' => Http::response('', $reachable ? 200 : 404)]);
    }

    private function recorder(): SocialLinkRecorder
    {
        return $this->app->make(SocialLinkRecorder::class);
    }

    public function test_records_verified_location_links_and_counts_them(): void
    {
        $this->profilesReachable();
        $restaurant = Restaurant::factory()->create(['social_links_count' => 0]);

        $labels = $this->recorder()->record($restaurant, [
            'instagram' => 'https://www.instagram.com/blueheronbistro',
            'facebook' => 'https://www.facebook.com/BlueHeronBistro',
        ]);

        $this->assertSame(['instagram', 'facebook'], $labels);
        $this->assertSame(2, $restaurant->fresh()?->social_links_count);
        $this->assertSame(2, RestaurantSocialLink::query()->where('scope', RestaurantSocialLink::SCOPE_LOCATION)->count());
    }

    public function test_replaces_previous_links(): void
    {
        $this->profilesReachable();
        $restaurant = Restaurant::factory()->create();
        $restaurant->socialLinks()->create(['platform' => 'facebook', 'url' => 'http://www.facebook.com/2008', 'verified_at' => now()]);

        $this->recorder()->record($restaurant, ['instagram' => 'https://www.instagram.com/blueheronbistro']);

        $this->assertSame(['https://www.instagram.com/blueheronbistro'], $restaurant->socialLinks()->pluck('url')->all());
    }

    public function test_url_shared_across_enough_restaurants_becomes_brand_scoped_for_all(): void
    {
        $this->profilesReachable();
        $locations = Restaurant::factory()->count(3)->create(['name' => "Domino's"])->all();

        foreach (array_slice($locations, 0, 2) as $location) {
            $this->recorder()->record($location, ['instagram' => 'https://www.instagram.com/dominos']);
        }
        // Below the threshold the account still counts.
        $this->assertSame(1, $locations[0]->fresh()?->social_links_count);

        $labels = $this->recorder()->record($locations[2], [
            'instagram' => 'https://www.instagram.com/dominos',
            'facebook' => 'https://www.facebook.com/DominosMainStreet',
        ]);

        $this->assertSame(['instagram:brand', 'facebook'], $labels);
        foreach ($locations as $i => $location) {
            $this->assertSame(
                $i === 2 ? 1 : 0,
                $location->fresh()?->social_links_count,
                'the shared corporate account no longer counts for any location; the location page still does'
            );
        }
        $this->assertSame(3, RestaurantSocialLink::query()->where('scope', RestaurantSocialLink::SCOPE_BRAND)->count());
    }

    public function test_unverified_links_are_stored_but_not_counted(): void
    {
        $this->profilesReachable(false);
        $restaurant = Restaurant::factory()->create();

        $labels = $this->recorder()->record($restaurant, ['instagram' => 'https://www.instagram.com/gone_account']);

        $this->assertSame(['instagram:unverified'], $labels);
        $this->assertSame(0, $restaurant->fresh()?->social_links_count);
    }
}
