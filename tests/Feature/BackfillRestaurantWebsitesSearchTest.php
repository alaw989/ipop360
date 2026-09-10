<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillRestaurantWebsites;
use App\Models\Restaurant;
use App\Services\WebsiteIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillRestaurantWebsitesSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $patterns */
    private function fakeHttp(array $patterns): void
    {
        Http::fake(array_merge($patterns, ['*' => Http::response('', 404)]));
    }

    /**
     * Invoke the private searchCandidates method on the command via reflection
     * and return its best candidate (the backfill identity-verifies each
     * candidate before saving; these tests pin only the search/parse layer).
     */
    private function callSearchWeb(string $name, ?string $city, ?string $state): ?string
    {
        return $this->callSearchCandidates($name, $city, $state)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function callSearchCandidates(string $name, ?string $city, ?string $state): array
    {
        $command = new BackfillRestaurantWebsites;
        $ref = new \ReflectionMethod($command, 'searchCandidates');

        return $ref->invoke($command, $name, $city, $state);
    }

    public function test_bing_search_finds_url(): void
    {
        // Bing wraps result URLs in a redirect with ?u=a1<base64> parameter
        $bingHtml = '<html><body>'
            .'<a href="https://www.bing.com/ck/a?u=a1aHR0cHM6Ly93d3cudGVzdHJlc3RhdXJhbnQuY29tLw">result</a>'
            .'</body></html>';

        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response($bingHtml, 200),
        ]);

        $url = $this->callSearchWeb('Test Restaurant', 'Mobile', 'AL');

        $this->assertNotNull($url);
        $this->assertStringContainsString('testrestaurant.com', $url);
    }

    public function test_bing_fails_and_ddg_fallback_succeeds(): void
    {
        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response('', 500),
            'https://html.duckduckgo.com/html/*' => Http::response(
                '<html><body><a class="result__a" href="//duckduckgo.com/l/?uddg=aHR0cHM6Ly93d3cudGVzdHJlc3RhdXJhbnQuY29tLw">result</a></body></html>',
                200
            ),
        ]);

        $url = $this->callSearchWeb('Test Restaurant', 'Mobile', 'AL');

        $this->assertNotNull($url);
        $this->assertStringContainsString('testrestaurant.com', $url);
    }

    public function test_bing_returns_no_results_and_ddg_fallback_succeeds(): void
    {
        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response('<html><body>No results</body></html>', 200),
            'https://html.duckduckgo.com/html/*' => Http::response(
                '<html><body><a class="result__a" href="//duckduckgo.com/l/?uddg=aHR0cHM6Ly93d3cuZm91bmRpdC5jb20v">result</a></body></html>',
                200
            ),
        ]);

        $url = $this->callSearchWeb('Test Restaurant', 'Mobile', 'AL');

        $this->assertNotNull($url);
    }

    public function test_both_search_engines_fail(): void
    {
        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response('', 500),
            'https://html.duckduckgo.com/html/*' => Http::response('', 500),
        ]);

        $url = $this->callSearchWeb('Test Restaurant', 'Mobile', 'AL');

        $this->assertNull($url);
    }

    public function test_ddg_fallback_parses_result_a_links_directly(): void
    {
        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response('<html><body>no results</body></html>', 200),
            'https://html.duckduckgo.com/html/*' => Http::response(
                '<html><body><a class="result__a" href="https://www.testrestaurant.com">result</a></body></html>',
                200
            ),
        ]);

        $url = $this->callSearchWeb('Test Restaurant', 'Mobile', 'AL');

        $this->assertNotNull($url);
        $this->assertStringContainsString('testrestaurant.com', $url);
    }

    public function test_skip_domain_urls_are_filtered_from_bing(): void
    {
        $bingHtml = '<html><body>'
            .'<a href="https://www.bing.com/ck/a?u=a1aHR0cHM6Ly93d3cuZmFjZWJvb2suY29tL1Rlc3RSZXN0YXVyYW50Lw">facebook</a>'
            .'<a href="https://www.bing.com/ck/a?u=a1aHR0cHM6Ly93d3cudGVzdHJlc3RhdXJhbnQuY29tLw">real site</a>'
            .'</body></html>';

        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response($bingHtml, 200),
        ]);

        $url = $this->callSearchWeb('Test Restaurant', 'Mobile', 'AL');

        $this->assertNotNull($url);
        $this->assertStringContainsString('testrestaurant.com', $url);
        $this->assertStringNotContainsString('facebook.com', $url);
    }

    public function test_returns_every_non_skipped_result_in_rank_order(): void
    {
        // The backfill identity-checks several candidates (the #1 result for a
        // generic name is often a dictionary page), so all of them are kept.
        $bingHtml = '<html><body>'
            .'<a href="https://www.bing.com/ck/a?u=a1'.base64_encode('https://www.merriam-webster.com/dictionary/taqueria').'">dictionary</a>'
            .'<a href="https://www.bing.com/ck/a?u=a1'.base64_encode('https://www.yelp.com/biz/taqueria-el-sol').'">yelp</a>'
            .'<a href="https://www.bing.com/ck/a?u=a1'.base64_encode('https://taqueriaelsol.example/').'">real site</a>'
            .'<a href="https://www.bing.com/ck/a?u=a1'.base64_encode('https://taqueriaelsol.example/').'">dupe</a>'
            .'</body></html>';

        $this->fakeHttp(['https://www.bing.com/search*' => Http::response($bingHtml, 200)]);

        $this->assertSame(
            ['https://www.merriam-webster.com/dictionary/taqueria', 'https://taqueriaelsol.example/'],
            $this->callSearchCandidates('Taqueria El Sol', 'Dallas', 'TX')
        );
    }

    public function test_backfill_saves_only_the_identity_verified_candidate(): void
    {
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);

        $bingHtml = '<html><body>'
            .'<a href="https://www.bing.com/ck/a?u=a1'.base64_encode('https://www.merriam-webster.com/dictionary/taqueria').'">dictionary</a>'
            .'<a href="https://www.bing.com/ck/a?u=a1'.base64_encode('https://othertaqueria.example/').'">another business</a>'
            .'<a href="https://www.bing.com/ck/a?u=a1'.base64_encode('https://taqueriaelsol.example/').'">real site</a>'
            .'</body></html>';

        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response($bingHtml, 200),
            'https://othertaqueria.example/' => Http::response('<html><head><title>Taqueria Guadalajara | Houston</title></head><body>'.str_repeat('<p>Street tacos, open late. 713-555-0100.</p>', 8).'</body></html>'),
            'https://taqueriaelsol.example/' => Http::response('<html><head><title>Taqueria El Sol</title></head><body><p>Visit us: 2811 Ross Ave, Dallas TX. (214) 555-0177</p></body></html>'),
        ]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Taqueria El Sol',
            'address' => '2811 Ross Ave',
            'city' => 'Dallas',
            'state' => 'TX',
            'phone' => '2145550177',
            'website_url' => null,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-cache' => true]);

        $fresh = Restaurant::query()->whereKey($restaurant->id)->firstOrFail();
        $this->assertSame('https://taqueriaelsol.example/', $fresh->website_url);
        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $fresh->website_identity);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'merriam-webster.com'));
    }

    public function test_guessed_parked_domain_is_never_saved(): void
    {
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);

        // No search results, so the guess phase tries https://www.{name}.com —
        // which answers 200 with a parking page (a bare HEAD used to be enough).
        $this->fakeHttp([
            'https://www.bing.com/search*' => Http::response('<html><body>no results</body></html>', 200),
            'https://html.duckduckgo.com/html/*' => Http::response('<html><body>no results</body></html>', 200),
            'https://www.bluehavencafe.com*' => Http::response('<html><head><title>bluehavencafe.com is for sale</title></head><body>Buy this domain. Related searches: cafe, coffee</body></html>'),
        ]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Blue Haven Cafe',
            'city' => 'Mobile',
            'state' => 'AL',
            'website_url' => null,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-cache' => true]);

        $this->assertNull(Restaurant::query()->whereKey($restaurant->id)->value('website_url'));
    }
}
