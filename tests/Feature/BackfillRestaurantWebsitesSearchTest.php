<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillRestaurantWebsites;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillRestaurantWebsitesSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fakeHttp(array $patterns): void
    {
        Http::fake(array_merge($patterns, ['*' => Http::response('', 404)]));
    }

    /**
     * Invoke the private searchWeb method on the command via reflection.
     */
    private function callSearchWeb(string $name, ?string $city, ?string $state): ?string
    {
        $command = new BackfillRestaurantWebsites;
        $scraper = $this->app->make(RestaurantWebsiteScraperService::class);
        $ref = new \ReflectionMethod($command, 'searchWeb');
        $ref->setAccessible(true);

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
}
