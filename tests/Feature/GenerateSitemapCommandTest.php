<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class GenerateSitemapCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.url', 'http://example.com');
    }

    public function test_generates_valid_sitemap_xml(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful()
            ->expectsOutputToContain('Sitemap generated at:');
        $command->run();

        $sitemapPath = public_path('sitemap.xml');
        $this->assertFileExists($sitemapPath);
        $content = file_get_contents($sitemapPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $content);
        $this->assertStringContainsString('</urlset>', $content);
    }

    public function test_includes_static_pages(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);

        $this->assertStringContainsString('<loc>http://example.com/restaurants</loc>', $content);
        $this->assertStringContainsString('<loc>http://example.com/blog</loc>', $content);

        // spec-115: /leaderboard and /compare are public and SEO-tagged but
        // were missing from the sitemap.
        $this->assertStringContainsString('<loc>http://example.com/leaderboard</loc>', $content);
        $this->assertStringContainsString('<loc>http://example.com/compare</loc>', $content);

        // spec-115: /login and /register are crawl-budget noise with no
        // ranking value — no longer advertised (they also carry noindex).
        $this->assertStringNotContainsString('<loc>http://example.com/login</loc>', $content);
        $this->assertStringNotContainsString('<loc>http://example.com/register</loc>', $content);

        // spec-110: /favorites is auth-gated (crawlers get a login redirect), so
        // it must not be advertised. See the dedicated test below.
        $this->assertStringNotContainsString('/favorites', $content);

        // The homepage <loc> must match its canonical with a trailing slash.
        $this->assertStringContainsString('<loc>http://example.com/</loc>', $content);
    }

    public function test_excludes_auth_gated_pages_from_the_sitemap(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);

        // spec-110: /favorites requires auth (routes/web.php:71,77); /dashboard,
        // /profile and /admin are auth/role-gated too and were never listed.
        $this->assertStringNotContainsString('/favorites', $content);
        $this->assertStringNotContainsString('/dashboard', $content);
        $this->assertStringNotContainsString('/profile', $content);
    }

    public function test_excludes_noindex_pages_from_the_sitemap(): void
    {
        // spec-115: /search exposes a near-infinite parameter space
        // (?cuisine=&lat=&lng=) and is served with noindex, so advertising it
        // would invite crawl budget waste on URLs that can never rank.
        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);

        $this->assertStringNotContainsString('<loc>http://example.com/search</loc>', $content);
    }

    public function test_includes_cuisine_pages_from_database(): void
    {
        // The /cuisine/{category:slug} route binds against cuisine_categories
        // (not the finer-grained cuisines table), so the sitemap must be
        // built from the same table the route resolves against.
        DB::table('cuisine_categories')->insert([
            ['name' => 'Italian', 'slug' => 'italian', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Mexican', 'slug' => 'mexican', 'created_at' => now(), 'updated_at' => now()],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);

        $this->assertStringContainsString('<loc>http://example.com/cuisine/italian</loc>', $content);
        $this->assertStringContainsString('<loc>http://example.com/cuisine/mexican</loc>', $content);
    }

    public function test_includes_active_restaurants_excludes_inactive(): void
    {
        Restaurant::factory()->create(['slug' => 'active-bistro', 'is_active' => true]);
        Restaurant::factory()->create(['slug' => 'inactive-cafe', 'is_active' => false]);

        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);

        $this->assertStringContainsString('<loc>http://example.com/restaurants/active-bistro</loc>', $content);
        $this->assertStringNotContainsString('inactive-cafe', $content);
    }

    public function test_includes_published_blog_posts_excludes_drafts(): void
    {
        BlogPost::factory()->create([
            'slug' => 'my-published-post',
            'status' => 'published',
        ]);
        BlogPost::factory()->draft()->create([
            'slug' => 'my-draft-post',
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);

        $this->assertStringContainsString('<loc>http://example.com/blog/my-published-post</loc>', $content);
        $this->assertStringNotContainsString('my-draft-post', $content);
    }

    public function test_includes_lastmod_for_entities_with_timestamps(): void
    {
        Restaurant::factory()->create(['slug' => 'timely-eats']);
        BlogPost::factory()->create([
            'slug' => 'timely-post',
            'status' => 'published',
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);

        $this->assertMatchesRegularExpression(
            '#<loc>http://example\.com/restaurants/timely-eats</loc>(?:(?!</url>).)*?<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.*?</lastmod>#s',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '#<loc>http://example\.com/blog/timely-post</loc>(?:(?!</url>).)*?<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.*?</lastmod>#s',
            $content,
        );

        $this->assertDoesNotMatchRegularExpression(
            '#<loc>http://example\.com/</loc>(?:(?!</url>).)*?<lastmod>#s',
            $content,
        );
    }

    public function test_chunks_restaurants_into_a_sitemap_index_when_over_the_chunk_size(): void
    {
        // spec-110: the live corpus (41k) exceeds the single-file chunk size, so
        // the command must emit a sitemap index plus chunked children instead of
        // silently truncating at 5,000. Force a tiny chunk size to exercise the
        // index path in the test.
        Config::set('restaurant-finder.seo.sitemap_chunk_size', 2);

        $slugs = ['chunk-one', 'chunk-two', 'chunk-three'];
        foreach ($slugs as $slug) {
            Restaurant::factory()->create(['slug' => $slug, 'is_active' => true]);
        }

        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $index = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($index);
        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertStringContainsString('<loc>http://example.com/sitemaps/sitemap-restaurants-1.xml</loc>', $index);
        $this->assertStringContainsString('<loc>http://example.com/sitemaps/sitemap-restaurants-2.xml</loc>', $index);
        $this->assertStringContainsString('<loc>http://example.com/sitemaps/sitemap-pages.xml</loc>', $index);
        $this->assertStringContainsString('<loc>http://example.com/sitemaps/sitemap-blog.xml</loc>', $index);
        $this->assertStringNotContainsString('<urlset', $index);

        $chunkOne = file_get_contents(public_path('sitemaps/sitemap-restaurants-1.xml'));
        $chunkTwo = file_get_contents(public_path('sitemaps/sitemap-restaurants-2.xml'));
        $this->assertIsString($chunkOne);
        $this->assertIsString($chunkTwo);

        // Every active restaurant is represented across the chunks, split 2/1.
        $combined = $chunkOne.$chunkTwo;
        foreach ($slugs as $slug) {
            $this->assertStringContainsString('<loc>http://example.com/restaurants/'.$slug.'</loc>', $combined);
        }
        $this->assertSame(2, substr_count($chunkOne, '<loc>'));
        $this->assertSame(1, substr_count($chunkTwo, '<loc>'));
    }

    public function test_removes_stale_chunk_files_from_a_previous_run(): void
    {
        // spec-110: a chunk that no longer has content (corpus shrank, chunk
        // count changed) must not linger as a stale, crawlable file.
        @mkdir(public_path('sitemaps'), 0777, true);
        file_put_contents(public_path('sitemaps/sitemap-restaurants-99.xml'), '<urlset></urlset>');

        Restaurant::factory()->create(['slug' => 'lonely-bistro', 'is_active' => true]);

        /** @var PendingCommand $command */
        $command = $this->artisan('seo:sitemap');
        $command->assertSuccessful();
        $command->run();

        $content = file_get_contents(public_path('sitemap.xml'));
        $this->assertIsString($content);
        $this->assertStringContainsString('<urlset', $content);
        $this->assertFileDoesNotExist(public_path('sitemaps/sitemap-restaurants-99.xml'));
    }
}
