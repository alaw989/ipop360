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
        $this->assertStringContainsString('<loc>http://example.com/login</loc>', $content);
        $this->assertStringContainsString('<loc>http://example.com/register</loc>', $content);
        $this->assertStringContainsString('<loc>http://example.com/favorites</loc>', $content);
        $this->assertStringContainsString('<loc>http://example.com/blog</loc>', $content);
        $this->assertStringContainsString('<loc>http://example.com</loc>', $content);
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
            '#<loc>http://example\.com</loc>(?:(?!</url>).)*?<lastmod>#s',
            $content,
        );
    }
}
