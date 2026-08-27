<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract for context-first restaurant image search
 * (searchImageForRestaurant).
 *
 * Live audit: 4,101 photo-less restaurants, but most carry verified context we
 * use shallowly — 8,195 have a website (scrape only fetches the homepage og),
 * 4,301 have social handles (never used for images), OSM image= tags are
 * dropped by the Overpass normalizer, Wikidata P18 is coord-queryable, and
 * Google CSE is 429-exhausted (100/day) so it must stay the LAST resort.
 *
 * The chain uses each step's verified context (its own website pages, its own
 * social handle) before falling back to keyword/search sources.
 */
class ContextImageSearchTest extends TestCase
{
    use RefreshDatabase;

    private RestaurantWebsiteScraperService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // The fake `.example` domains don't resolve — disable the fail-closed
        // SSRF guard so the multi-page crawl reaches the faked pages.
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
        $this->service = $this->app->make(RestaurantWebsiteScraperService::class);
    }

    public function test_multi_page_crawl_finds_image_on_menu_page_when_homepage_lacks_one(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Pasta Palace',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => 'https://pastapalace.example',
            'photo_url' => null,
            'photos' => [],
        ]);

        // Homepage: no og:image, no img. /menu: has an img.
        Http::fake([
            'pastapalace.example/' => Http::response('<html><body><h1>Pasta Palace</h1></body></html>', 200),
            'pastapalace.example/menu' => Http::response(
                '<html><body><img src="/images/fettuccine.jpg" /></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'pastapalace.example/gallery' => Http::response('<html><body></body></html>', 200),
        ]);

        $result = $this->service->searchImageForRestaurant($restaurant);

        $this->assertSame('https://pastapalace.example/images/fettuccine.jpg', $result['url'] ?? null, 'a photo on a sub-page must be found when the homepage lacks one');
        $this->assertSame('website', $result['source']);
    }

    public function test_social_handle_yields_profile_image(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Curry House',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => null,
            'photo_url' => null,
            'photos' => [],
        ]);

        RestaurantSocialLink::create([
            'restaurant_id' => $restaurant->id,
            'platform' => 'instagram',
            'url' => 'https://www.instagram.com/curryhouseatx',
        ]);

        // No website, no OSM tag → falls to the stored social handle.
        Http::fake([
            'instagram.com/*' => Http::response(
                '<html><head><meta property="og:image" content="https://scontent.example/prof.jpg" /></head></html>',
                200
            ),
        ]);

        $result = $this->service->searchImageForRestaurant($restaurant);

        $this->assertSame('https://scontent.example/prof.jpg', $result['url'] ?? null, 'the stored instagram handle must yield a profile image');
        $this->assertSame('social', $result['source']);
    }

    public function test_osm_image_tag_is_used_before_keyword_search(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Taco Stand',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => null,
            'photo_url' => null,
            'photos' => [],
        ]);

        // No website, no social link. The OSM image tag (simulated as the only
        // context source) must win over keyword search — which would otherwise
        // match a wrong Wikimedia image.
        Http::fake([
            'upload.wikimedia.org/*' => Http::response('', 200),
        ]);

        // searchImageForRestaurant accepts an OSM image URL directly.
        $result = $this->service->searchImageForRestaurant($restaurant, 'https://osm.example/taco.jpg');

        $this->assertSame('https://osm.example/taco.jpg', $result['url'] ?? null, 'the OSM image= tag must be used as verified context');
        $this->assertSame('osm', $result['source']);
    }

    public function test_falls_through_to_guarded_wikimedia_when_no_context(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Burger Barn',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => null,
            'latitude' => null,
            'longitude' => null,
            'photo_url' => null,
            'photos' => [],
        ]);

        // No website, no social, no OSM tag → keyword search with the
        // name-relevance guard (PR #110): a title lacking the restaurant name
        // must be rejected, and CSE (no key) returns null.
        Http::fake([
            'commons.wikimedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => [['title' => 'Songs of the barn']]]])
                ->push(['query' => ['pages' => [['imageinfo' => [['url' => 'https://upload.wikimedia.org/wrong.jpg']]]]]]),
        ]);

        $result = $this->service->searchImageForRestaurant($restaurant);

        $this->assertNull($result, 'an off-name Wikimedia hit must be rejected (name-relevance guard)');
    }

    public function test_wikidata_image_used_when_coords_present_and_no_other_context(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Atelier Crenn',
            'city' => 'San Francisco',
            'state' => 'CA',
            'website_url' => null,
            'latitude' => 37.7984,
            'longitude' => -122.436,
            'photo_url' => null,
            'photos' => [],
        ]);

        // No website, no social, no OSM tag → the coord-verified Wikidata
        // wdt:P18 lookup (step 4) must win before keyword search.
        Http::fake([
            'query.wikidata.org/*' => Http::response([
                'results' => [
                    'bindings' => [
                        [
                            'item' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q60766970'],
                            'itemLabel' => ['type' => 'literal', 'value' => 'Atelier Crenn'],
                            'coord' => ['type' => 'literal', 'value' => 'Point(-122.436 37.7984)'],
                            'image' => ['type' => 'literal', 'value' => 'Atelier Crenn.jpg'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->searchImageForRestaurant($restaurant);

        $this->assertSame('https://commons.wikimedia.org/wiki/Special:FilePath/Atelier_Crenn.jpg?width=800', $result['url'] ?? null, 'coord-verified Wikidata P18 must supply the image');
        $this->assertSame('wikidata', $result['source']);
    }

    public function test_google_cse_is_the_last_resort_after_free_context(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Pho Queen',
            'city' => 'Austin',
            'state' => 'TX',
            'website_url' => null,
            'latitude' => null,
            'longitude' => null,
            'photo_url' => null,
            'photos' => [],
        ]);

        // All free context misses → Google CSE (num=5, pick best) is the last
        // resort. Config a fake CSE key + a real API response.
        config(['services.google_custom_search.api_key' => 'fake-key', 'services.google_custom_search.cx' => 'fake-cx']);

        Http::fake([
            'commons.wikimedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => []]])
                ->push([]),
            'en.wikipedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => []]])
                ->push([]),
            'googleapis.com/customsearch/*' => Http::response([
                'items' => [
                    ['link' => 'https://cdn.example/meh.jpg'],
                    ['link' => 'https://cdn.example/pho-queen.jpg'],
                ],
            ], 200),
        ]);

        $result = $this->service->searchImageForRestaurant($restaurant);

        $this->assertSame('https://cdn.example/pho-queen.jpg', $result['url'] ?? null, 'CSE must pick the best of num=5 results, and only run after free sources');
        $this->assertSame('google_cse', $result['source']);
    }
}
