<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Unit coverage for PhotoThumbnailService: file-name/hash freshness, Google
 * source sizing, the SSRF and size guards, and (where GD exists) the resize
 * itself. Every photo is copied: some hosts serve 16 MB originals to a 96 px
 * card, and Google's photo URLs expire within weeks.
 */
class PhotoThumbnailServiceTest extends TestCase
{
    private PhotoThumbnailService $thumbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thumbs = $this->app->make(PhotoThumbnailService::class);
        Config::set('restaurant-finder.photo_thumbs.ssrf_guard', false);
        Storage::fake('local');
    }

    private function restaurant(string $photoUrl, ?string $thumb = null): Restaurant
    {
        $restaurant = new Restaurant(['photo_url' => $photoUrl, 'photo_thumb' => $thumb]);
        $restaurant->id = 42;

        return $restaurant;
    }

    public function test_expected_filename_embeds_the_photo_hash(): void
    {
        $restaurant = $this->restaurant('https://example.com/beef.jpg');

        $this->assertSame(
            '42-'.substr(sha1('https://example.com/beef.jpg'), 0, 10).'.webp',
            $this->thumbs->expectedFilename($restaurant),
        );
    }

    public function test_expected_filename_is_null_without_a_photo(): void
    {
        $this->assertNull($this->thumbs->expectedFilename($this->restaurant('')));
    }

    public function test_matches_is_true_only_for_the_current_photo_hash(): void
    {
        $current = $this->thumbs->expectedFilename($this->restaurant('https://example.com/a.jpg'));

        $this->assertTrue($this->thumbs->matches($this->restaurant('https://example.com/a.jpg', $current)));
        $this->assertFalse($this->thumbs->matches($this->restaurant('https://example.com/b.jpg', $current)));
        $this->assertFalse($this->thumbs->matches($this->restaurant('https://example.com/a.jpg', null)));
    }

    public function test_google_photos_are_fetched_at_the_thumbnail_width(): void
    {
        Http::fake(['*' => Http::response('not an image', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->thumbs->generate($this->restaurant('https://lh3.googleusercontent.com/gps-cs-s/abc=w400-h300-c-no'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://lh3.googleusercontent.com/gps-cs-s/abc=w640-h640');
    }

    public function test_sized_source_url_rewrites_only_google_photo_urls(): void
    {
        $this->assertSame(
            'https://lh3.googleusercontent.com/p/AF1Qip=w640-h640',
            $this->thumbs->sizedSourceUrl('https://lh3.googleusercontent.com/p/AF1Qip', 640),
        );
        $this->assertSame(
            'https://lh5.googleusercontent.com/gps-cs-s/abc=w320-h320',
            $this->thumbs->sizedSourceUrl('https://lh5.googleusercontent.com/gps-cs-s/abc=s1600-k-no', 320),
        );
        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/d/de/File.jpg',
            $this->thumbs->sizedSourceUrl('https://upload.wikimedia.org/wikipedia/commons/d/de/File.jpg', 640),
        );
    }

    public function test_public_url_is_set_only_for_a_matching_copy(): void
    {
        $url = 'https://example.com/a.jpg';
        $current = $this->thumbs->expectedFilename($this->restaurant($url));

        $this->assertSame('/thumbs/'.$current, $this->thumbs->publicUrl($this->restaurant($url, $current)));
        $this->assertNull($this->thumbs->publicUrl($this->restaurant('https://example.com/b.jpg', $current)));
        $this->assertNull($this->thumbs->publicUrl($this->restaurant($url)));
    }

    public function test_blocks_a_private_address_when_the_ssrf_guard_is_on(): void
    {
        Config::set('restaurant-finder.photo_thumbs.ssrf_guard', true);
        Http::fake();

        $this->assertNull($this->thumbs->generate($this->restaurant('http://169.254.169.254/latest/meta-data.jpg')));
        Http::assertNothingSent();
    }

    public function test_rejects_a_non_image_body(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertNull($this->thumbs->generate($this->restaurant('https://example.com/photo.jpg')));
        $this->assertFalse($this->thumbs->hasThumb($this->restaurant('https://example.com/photo.jpg')));
    }

    public function test_rejects_a_download_over_the_byte_cap(): void
    {
        Config::set('restaurant-finder.photo_thumbs.max_download_bytes', 8);
        Http::fake([
            'example.com/*' => Http::response('way more than eight bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->assertNull($this->thumbs->generate($this->restaurant('https://example.com/photo.jpg')));
    }

    public function test_encodes_a_resized_webp_when_gd_is_available(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD with WebP is not installed in this environment.');
        }

        $source = imagecreatetruecolor(1000, 500);
        $color = imagecolorallocate($source, 200, 40, 30);
        if ($color === false) {
            $this->fail('Could not allocate a color.');
        }
        imagefill($source, 0, 0, $color);
        ob_start();
        imagepng($source);
        $binary = (string) ob_get_clean();
        imagedestroy($source);

        $webp = $this->thumbs->encodeWebp($binary, 640);

        $this->assertNotNull($webp);
        $info = getimagesizefromstring($webp);
        $this->assertIsArray($info);
        $this->assertSame(640, $info[0]);
        $this->assertSame(320, $info[1]);
    }
}
