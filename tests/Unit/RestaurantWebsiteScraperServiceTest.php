<?php

namespace Tests\Unit;

use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RestaurantWebsiteScraperServiceTest extends TestCase
{
    private RestaurantWebsiteScraperService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RestaurantWebsiteScraperService;
        Cache::flush();
        // These tests cover parsing / robots / caching logic, NOT the spec-075
        // SSRF guard (which resolves the host via DNS — non-deterministic in the
        // test env). The guard is exercised in WebsiteScraperSsrfGuardTest.
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
    }

    public function test_scrape_returns_null_for_empty_url(): void
    {
        $this->assertNull($this->service->scrape(''));
        $this->assertNull($this->service->scrape('   '));
    }

    public function test_scrape_returns_null_for_invalid_url(): void
    {
        Http::fake([
            'https://not-a-valid-domain/path' => Http::response([], 404),
        ]);

        $this->assertNull($this->service->scrape('not-a-valid-domain/path'));
    }

    public function test_scrape_respects_robots_txt_disallow(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /",
                200
            ),
        ]);

        $this->assertNull($this->service->scrape('https://example.com/'));
    }

    public function test_scrape_proceeds_when_robots_txt_allows(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /admin",
                200
            ),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('opening_hours', $result);
    }

    public function test_scrape_proceeds_when_robots_txt_missing(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response([], 404),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
    }

    public function test_scrape_caches_results(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $this->service->scrape('https://example.com/');
        $this->service->scrape('https://example.com/');

        // Should only fetch once due to caching
        Http::assertSentCount(2); // 1 robots.txt + 1 page fetch
    }

    public function test_scrape_extracts_opening_hours_from_microdata(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('opening_hours', $result);
        $this->assertNotNull($result['opening_hours']);
    }

    public function test_scrape_extracts_opening_hours_from_json_ld(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'openingHoursSpecification' => [
                [
                    'dayOfWeek' => 'Monday',
                    'opens' => '09:00',
                    'closes' => '17:00',
                ],
                [
                    'dayOfWeek' => 'Tuesday',
                    'opens' => '09:00',
                    'closes' => '17:00',
                ],
            ],
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><script type="application/ld+json">'.$jsonLd.'</script></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('opening_hours', $result);
        $this->assertNotNull($result['opening_hours']);
    }

    public function test_scrape_extracts_menu_url(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><a href="/menu">View our menu</a></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('menu_url', $result);
        $this->assertEquals('https://example.com/menu', $result['menu_url']);
    }

    public function test_scrape_returns_null_when_no_useful_data_found(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><p>Just some text</p></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertNull($result);
    }

    public function test_scrape_handles_http_errors_gracefully(): void
    {
        Http::fake([
            'https://example.com/' => Http::response([], 500),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertNull($result);
    }

    public function test_scrape_adds_https_scheme_if_missing(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('example.com/');

        $this->assertIsArray($result);
    }

    public function test_robots_txt_cache_works(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /admin",
                200
            ),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        $this->service->scrape('https://example.com/');
        $this->service->scrape('https://example.com/');

        // First call: robots.txt + page fetch = 2 requests
        // Second call: returns from website scrape cache (7 days) = 0 additional requests
        Http::assertSentCount(2);
    }

    public function test_normalize_day_name(): void
    {
        // This tests a private method indirectly through JSON-LD parsing
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'openingHoursSpecification' => [
                ['dayOfWeek' => 'mon', 'opens' => '09:00', 'closes' => '17:00'],
                ['dayOfWeek' => 'TUE', 'opens' => '09:00', 'closes' => '17:00'],
                ['dayOfWeek' => 'Wednesday', 'opens' => '09:00', 'closes' => '17:00'],
                ['dayOfWeek' => 'http://schema.org/Thursday', 'opens' => '09:00', 'closes' => '17:00'],
            ],
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><script type="application/ld+json">'.$jsonLd.'</script></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('opening_hours', $result);
    }

    public function test_resolve_relative_url(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div><a href="/menu">View our menu</a><a href="/food">Order food</a><a href="https://other.com/order">External</a></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('opening_hours', $result);
        $this->assertArrayHasKey('menu_url', $result);
        // The first matching link with "menu" text should be found and converted to absolute URL
        $this->assertEquals('https://example.com/menu', $result['menu_url']);
    }

    // ──── scrapeSocial tests ────

    public function test_scrape_social_returns_null_for_empty_url(): void
    {
        $this->assertNull($this->service->scrapeSocial(''));
        $this->assertNull($this->service->scrapeSocial('   '));
    }

    public function test_scrape_social_skips_known_non_restaurant_domains(): void
    {
        $this->assertNull(
            $this->service->scrapeSocial('https://www.facebook.com/SomeRestaurant')
        );
        $this->assertNull(
            $this->service->scrapeSocial('https://www.instagram.com/someaccount')
        );
        $this->assertNull(
            $this->service->scrapeSocial('https://www.yelp.com/biz/some-restaurant')
        );
    }

    public function test_scrape_social_extracts_platform_urls(): void
    {
        $html = '<html><body>
            <a href="https://www.instagram.com/testrestaurant">Instagram</a>
            <a href="https://www.facebook.com/testrestaurant">Facebook</a>
            <a href="https://www.tiktok.com/@testrestaurant">TikTok</a>
        </body></html>';

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response($html, 200),
        ]);

        $result = $this->service->scrapeSocial('https://example.com');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('instagram', $result);
        $this->assertArrayHasKey('facebook', $result);
        $this->assertArrayHasKey('tiktok', $result);
        $this->assertEquals('https://www.instagram.com/testrestaurant', $result['instagram']);
    }

    public function test_scrape_social_returns_null_when_no_links_found(): void
    {
        $html = '<html><body><p>No social links here</p></body></html>';

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response($html, 200),
            'https://example.com/contact' => Http::response($html, 200),
            'https://example.com/about' => Http::response($html, 200),
        ]);

        $result = $this->service->scrapeSocial('https://example.com');

        $this->assertNull($result);
    }

    public function test_scrape_social_handles_connection_errors(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response('', 500),
        ]);

        $result = $this->service->scrapeSocial('https://example.com');

        $this->assertNull($result);
    }

    public function test_scrape_social_stops_early_after_finding_enough_platforms(): void
    {
        $html = '<html><body>
            <a href="https://www.instagram.com/test">Instagram</a>
            <a href="https://www.facebook.com/test">Facebook</a>
            <a href="https://www.youtube.com/@test">YouTube</a>
            <a href="https://www.tiktok.com/@test">TikTok</a>
        </body></html>';

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response($html, 200),
        ]);

        $result = $this->service->scrapeSocial('https://example.com');

        $this->assertNotNull($result);
        // Should have found enough social platforms (early stop)
        $this->assertGreaterThanOrEqual(3, count($result));
    }

    public function test_scrape_social_skips_sharer_links(): void
    {
        $html = '<html><body>
            <a href="https://www.facebook.com/sharer/sharer.php">Share</a>
            <a href="https://www.facebook.com/RealPage">Real Page</a>
        </body></html>';

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response($html, 200),
        ]);

        $result = $this->service->scrapeSocial('https://example.com');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('facebook', $result);
        $this->assertEquals('https://www.facebook.com/RealPage', $result['facebook']);
    }
}
