<?php

namespace Tests\Unit;

use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_scrape_ignores_stale_legacy_cache_from_before_new_extraction_fields(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><div itemprop="openingHours">Mo-Fr 09:00-17:00</div></body></html>',
                200
            ),
        ]);

        // Seed the old unversioned cache key with a pre-extraction payload that
        // carries NONE of the fields added in the description/price iterations.
        // A versionless key would return this stale blob for up to 7 days and
        // the daily scrape budget would be wasted on rows that never gain the
        // new fields.
        $legacyKey = 'website_scrape:'.md5('https://example.com/');
        Cache::put($legacyKey, ['opening_hours' => null, 'menu_url' => null], now()->addDays(7));

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        // robots.txt (1) + page fetch (1) = the legacy entry must NOT be reused.
        Http::assertSentCount(2);
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

    public function test_scrape_extracts_photo_gallery_and_og_image(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><head>'
                .'<meta property="og:image" content="https://cdn.example.com/og.jpg">'
                .'<meta property="og:image:secure_url" content="https://cdn.example.com/og-secure.jpg">'
                .'<meta name="twitter:image" content="https://cdn.example.com/tw.jpg">'
                .'</head><body>'
                .'<img src="https://cdn.example.com/photo1.jpg">'
                .'<img src="/photo2.jpg">'
                .'<img src="https://cdn.example.com/logo.png">'
                .'<img src="data:image/png;base64,xxxx">'
                .'<img src="https://cdn.example.com/banner.svg">'
                .'</body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertEquals('https://cdn.example.com/og.jpg', $result['photo_url']);
        $this->assertArrayHasKey('photos', $result);

        $photos = $result['photos'];
        $this->assertContains('https://cdn.example.com/og.jpg', $photos);
        $this->assertContains('https://cdn.example.com/tw.jpg', $photos);
        // Relative <img> resolved to absolute.
        $this->assertContains('https://example.com/photo2.jpg', $photos);
        // Data-URIs, .svg/.png logos are excluded.
        $this->assertNotContains('data:image/png;base64,xxxx', $photos);
        $this->assertNotContains('https://cdn.example.com/logo.png', $photos);
        $this->assertNotContains('https://cdn.example.com/banner.svg', $photos);
        // Deduped: og.jpg appears once.
        $this->assertSame(1, count(array_filter($photos, fn ($p) => $p === 'https://cdn.example.com/og.jpg')));
        // Capped at the gallery max (6 default).
        $this->assertLessThanOrEqual(6, count($photos));
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

    public function test_scrape_extracts_description_from_og_description(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><head>'
                .'<meta property="og:description" content="Family-owned Mexican taqueria serving handmade tortillas since 1985.">'
                .'</head><body></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertSame('Family-owned Mexican taqueria serving handmade tortillas since 1985.', $result['description']);
    }

    public function test_scrape_extracts_description_from_meta_name_fallback(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><head>'
                .'<meta name="description" content="Neighborhood Italian bistro with wood-fired pizza and fresh pasta.">'
                .'</head><body></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertSame('Neighborhood Italian bistro with wood-fired pizza and fresh pasta.', $result['description']);
    }

    public function test_scrape_extracts_description_from_json_ld(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'name' => 'Example Bistro',
            'description' => 'Neighborhood Italian bistro with wood-fired pizza and hand-rolled pasta made fresh daily.',
            'priceRange' => '$$',
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
        $this->assertSame(
            'Neighborhood Italian bistro with wood-fired pizza and hand-rolled pasta made fresh daily.',
            $result['description']
        );
    }

    public function test_scrape_extracts_description_from_json_ld_array(): void
    {
        $jsonLd = json_encode([
            ['@type' => 'WebSite', 'name' => 'Example'],
            ['@type' => 'Restaurant', 'description' => 'Family-owned taqueria serving handmade tortillas and slow-roasted carnitas since 1985.'],
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
        $this->assertSame(
            'Family-owned taqueria serving handmade tortillas and slow-roasted carnitas since 1985.',
            $result['description']
        );
    }

    public function test_scrape_rejects_short_or_non_string_json_ld_description(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'description' => 'Tiny blurb',
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><script type="application/ld+json">'.$jsonLd.'</script></body></html>',
                200
            ),
        ]);

        // A too-short JSON-LD blurb is not a useful description; when it is the
        // only extractable field the scrape returns null rather than junk.
        $result = $this->service->scrape('https://example.com/');

        $this->assertNull($result);
    }

    public function test_scrape_prefers_meta_description_over_json_ld(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'description' => 'JSON-LD blurb that is definitely long enough to qualify as a real restaurant description.',
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><head>'
                .'<meta name="description" content="Meta blurb that is also long enough to count as a real description text.">'
                .'</head><body><script type="application/ld+json">'.$jsonLd.'</script></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        $this->assertIsArray($result);
        $this->assertSame(
            'Meta blurb that is also long enough to count as a real description text.',
            $result['description'],
            'the meta description source must stay the preferred source over JSON-LD'
        );
    }

    public function test_scrape_extracts_price_range_from_json_ld(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'name' => 'Example Bistro',
            'priceRange' => '$$',
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
        $this->assertSame('$$', $result['price_range']);
    }

    public function test_scrape_extracts_price_range_from_numeric_json_ld(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'name' => 'Example Diner',
            'priceRange' => '$10-20',
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
        $this->assertSame('$$', $result['price_range']);
    }

    public function test_scrape_rejects_bogus_json_ld_price_range(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Restaurant',
            'name' => 'Example Lounge',
            'priceRange' => 'Price on request',
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><body><script type="application/ld+json">'.$jsonLd.'</script></body></html>',
                200
            ),
        ]);

        // A non-dollars, non-numeric price value is not a usable price range;
        // when it is the only extractable field the scrape returns null.
        $result = $this->service->scrape('https://example.com/');

        $this->assertNull($result);
    }

    public function test_scrape_does_not_extract_short_or_empty_description(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(
                '<html><head>'
                .'<meta name="description" content="Just a few words">'
                .'</head><body></body></html>',
                200
            ),
        ]);

        $result = $this->service->scrape('https://example.com/');

        // A too-short meta blurb is not a useful description; when it is the
        // only extractable field the scrape returns null rather than junk.
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

    public function test_verify_profile_url_returns_true_for_a_reachable_url(): void
    {
        Http::fake([
            'https://instagram.com/realvenue' => Http::response('', 200),
        ]);

        $this->assertTrue($this->service->verifyProfileUrl('https://instagram.com/realvenue'));
    }

    public function test_verify_profile_url_returns_true_for_a_redirect(): void
    {
        // allow_redirects auto-follows the 301 via Guzzle, so the redirect
        // target must be faked too — otherwise an unfaked target falls
        // through to a REAL outbound request (flaky in CI: sometimes passes,
        // sometimes fails/times out depending on network reachability).
        Http::fake([
            'https://instagram.com/realvenue' => Http::response('', 301, ['Location' => 'https://instagram.com/realvenue/']),
            'https://instagram.com/realvenue/' => Http::response('', 200),
        ]);

        $this->assertTrue($this->service->verifyProfileUrl('https://instagram.com/realvenue'));
    }

    public function test_verify_profile_url_returns_false_for_a_dead_link(): void
    {
        Http::fake([
            'https://instagram.com/deadhandle' => Http::response('', 404),
        ]);

        $this->assertFalse($this->service->verifyProfileUrl('https://instagram.com/deadhandle'));
    }

    public function test_verify_profile_url_falls_back_to_ranged_get_when_head_is_disallowed(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'HEAD') {
                return Http::response('', 405);
            }

            return Http::response('<html>profile page</html>', 200);
        });

        $this->assertTrue($this->service->verifyProfileUrl('https://instagram.com/realvenue'));
    }

    public function test_verify_profile_url_returns_false_on_connection_failure(): void
    {
        Http::fake([
            'https://instagram.com/*' => fn () => throw new ConnectionException('timed out'),
        ]);

        $this->assertFalse($this->service->verifyProfileUrl('https://instagram.com/realvenue'));
    }

    public function test_verify_profile_url_returns_false_when_ssrf_guard_blocks_the_url(): void
    {
        Config::set('restaurant-finder.website_scraper.ssrf_guard', true);

        $this->assertFalse($this->service->verifyProfileUrl('http://127.0.0.1/social'));
    }
}
