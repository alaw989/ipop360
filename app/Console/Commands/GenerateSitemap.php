<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSitemap extends Command
{
    /**
     * Sitemaps.org allows 50,000 URLs per file; stay under it with headroom so
     * one chunk can absorb some growth without re-splitting.
     */
    private const DEFAULT_CHUNK_SIZE = 40000;

    protected $signature = 'seo:sitemap';

    protected $description = 'Generate sitemap.xml for SEO';

    public function handle(): int
    {
        $sitemapPath = public_path('sitemap.xml');
        $baseUrl = rtrim((string) config('app.url'), '/');
        $chunkSize = max(1, (int) config('restaurant-finder.seo.sitemap_chunk_size', self::DEFAULT_CHUNK_SIZE));

        [$indexXml, $children] = $this->build($baseUrl, $chunkSize);

        // Remove stale children BEFORE writing the current set: a shrinking
        // corpus (fewer chunks) must not leave orphaned, crawlable files behind.
        $this->removeStaleChunkFiles(array_keys($children));

        foreach ($children as $name => $xml) {
            file_put_contents(public_path($name), $xml);
        }

        file_put_contents($sitemapPath, $indexXml);

        $this->info("Sitemap generated at: {$sitemapPath}");

        return Command::SUCCESS;
    }

    /**
     * Build the sitemap (or index + children). A single `<urlset>` is written
     * while everything fits under the chunk size; above it, an index references
     * per-kind children so the restaurant list is never silently truncated.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function build(string $baseUrl, int $chunkSize): array
    {
        if ($chunkSize < 1) {
            $chunkSize = 1;
        }

        $pages = $this->pageEntries($baseUrl);
        $restaurants = $this->restaurantEntries($baseUrl);
        $posts = $this->blogEntries($baseUrl);

        if (count($pages) + count($restaurants) + count($posts) <= $chunkSize) {
            return [$this->urlset(array_merge($pages, $restaurants, $posts)), []];
        }

        $children = ['sitemap-pages.xml' => $this->urlset($pages)];

        foreach (array_chunk($restaurants, $chunkSize) as $i => $chunk) {
            $children['sitemap-restaurants-'.($i + 1).'.xml'] = $this->urlset($chunk);
        }

        $children['sitemap-blog.xml'] = $this->urlset($posts);

        return [$this->sitemapIndex($baseUrl, array_keys($children)), $children];
    }

    /**
     * @return list<array{loc: string, changefreq: string, priority: string, lastmod: ?Carbon}>
     */
    private function pageEntries(string $baseUrl): array
    {
        // Static pages. Auth/role-gated routes (/favorites, /dashboard,
        // /profile, /admin) are deliberately absent — crawlers only get a
        // redirect.
        $staticPages = [
            ['url' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['url' => '/restaurants', 'changefreq' => 'daily', 'priority' => '0.9'],
            ['url' => '/login', 'changefreq' => 'monthly', 'priority' => '0.3'],
            ['url' => '/register', 'changefreq' => 'monthly', 'priority' => '0.3'],
            ['url' => '/blog', 'changefreq' => 'weekly', 'priority' => '0.7'],
        ];

        $entries = [];
        foreach ($staticPages as $page) {
            $entries[] = $this->entry($baseUrl.$page['url'], $page['changefreq'], $page['priority']);
        }

        // Cuisine category pages — /cuisine/{slug} binds against CuisineCategory
        // (cuisine_categories.slug, e.g. "asian"), not the finer-grained
        // cuisines table (e.g. "chinese"), which 404s.
        foreach (DB::table('cuisine_categories')->select('slug')->get() as $cuisine) {
            $entries[] = $this->entry($baseUrl.'/cuisine/'.$cuisine->slug, 'weekly', '0.8');
        }

        return $entries;
    }

    /**
     * @return array<int, array{loc: string, changefreq: string, priority: string, lastmod: ?Carbon}>
     */
    private function restaurantEntries(string $baseUrl): array
    {
        return Restaurant::select('slug', 'updated_at')
            ->where('is_active', true)
            ->orderBy('popularity_score', 'desc')
            ->get()
            ->map(fn (Restaurant $restaurant): array => $this->entry(
                $baseUrl.'/restaurants/'.$restaurant->slug,
                'weekly',
                '0.7',
                $restaurant->updated_at,
            ))
            ->all();
    }

    /**
     * @return array<int, array{loc: string, changefreq: string, priority: string, lastmod: ?Carbon}>
     */
    private function blogEntries(string $baseUrl): array
    {
        return BlogPost::published()
            ->select('slug', 'updated_at')
            ->get()
            ->map(fn (BlogPost $post): array => $this->entry(
                $baseUrl.'/blog/'.$post->slug,
                'monthly',
                '0.6',
                $post->updated_at,
            ))
            ->all();
    }

    /**
     * @return array{loc: string, changefreq: string, priority: string, lastmod: ?Carbon}
     */
    private function entry(string $loc, string $changefreq, string $priority, ?Carbon $lastmod = null): array
    {
        return [
            'loc' => $loc,
            'changefreq' => $changefreq,
            'priority' => $priority,
            'lastmod' => $lastmod,
        ];
    }

    /**
     * @param  array<int, array{loc: string, changefreq: string, priority: string, lastmod: ?Carbon}>  $entries
     */
    private function urlset(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entries as $entry) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.htmlspecialchars($entry['loc']).'</loc>'."\n";
            $xml .= '    <changefreq>'.$entry['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$entry['priority'].'</priority>'."\n";

            if ($entry['lastmod'] !== null) {
                $xml .= '    <lastmod>'.$entry['lastmod']->toAtomString().'</lastmod>'."\n";
            }

            $xml .= '  </url>'."\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * @param  list<string>  $files
     */
    private function sitemapIndex(string $baseUrl, array $files): string
    {
        $lastmod = now()->toAtomString();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($files as $file) {
            $xml .= '  <sitemap>'."\n";
            $xml .= '    <loc>'.htmlspecialchars($baseUrl.'/'.$file).'</loc>'."\n";
            $xml .= '    <lastmod>'.$lastmod.'</lastmod>'."\n";
            $xml .= '  </sitemap>'."\n";
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    /**
     * @param  list<string>  $keep
     */
    private function removeStaleChunkFiles(array $keep): void
    {
        foreach (glob(public_path('sitemap-*.xml')) ?: [] as $path) {
            if (! in_array(basename($path), $keep, true)) {
                @unlink($path);
            }
        }
    }
}
