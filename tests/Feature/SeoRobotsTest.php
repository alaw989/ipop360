<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * spec-115: crawler directives on pages that can never rank — auth pages,
 * the parameter-heavy search results, and now /favorites. Emitted
 * server-side (app.blade.php) so a crawler sees them without executing JS,
 * and shared as an Inertia prop so client-side SEO logic agrees.
 */
class SeoRobotsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function noindexPaths(): array
    {
        return [
            '/search' => ['/search'],
            '/login' => ['/login'],
            '/register' => ['/register'],
        ];
    }

    #[DataProvider('noindexPaths')]
    public function test_noindex_directive_is_rendered_server_side(string $path): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, nofollow" />', false);
    }

    #[DataProvider('noindexPaths')]
    public function test_noindex_flag_is_shared_as_an_inertia_prop(string $path): void
    {
        $response = $this->get($path);

        $response->assertInertia(fn (AssertableInertia $page) => $page->where('seo.noindex', true));
    }

    public function test_favorites_is_noindex_for_authenticated_users(): void
    {
        // /favorites is auth-gated (guests get a 302), so the directive only
        // matters — and is only renderable — once the user is in.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/favorites');

        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, nofollow" />', false);
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('seo.noindex', true));
    }

    public function test_public_pages_are_indexable(): void
    {
        $response = $this->get('/restaurants');

        $response->assertOk();
        $response->assertDontSee('name="robots"', false);
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('seo.noindex', false));
    }

    public function test_canonical_origin_comes_from_config_not_a_hardcoded_domain(): void
    {
        config(['app.url' => 'https://staging.example.test']);

        $response = $this->get('/restaurants');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('seo.base_url', 'https://staging.example.test'));
    }

    public function test_robots_txt_is_served_dynamically_from_config(): void
    {
        config(['app.url' => 'https://staging.example.test']);

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Sitemap: https://staging.example.test/sitemap.xml', false);
        $response->assertSee('Disallow: /search', false);
        $response->assertSee('Disallow: /favorites', false);
    }
}
