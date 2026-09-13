<?php

namespace Tests\Unit;

use App\Support\PhotoUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PhotoUrl::isPlatformAsset() classifies a platform's own static assets
 * (Instagram/Facebook logo sprites served from /rsrc.php) so they are never
 * stored as a restaurant's photo. ~3,800 prod rows had the Instagram logo
 * sprite as their photo_url, rendered at 96–176 px.
 */
class PhotoUrlTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function platformAssets(): array
    {
        return [
            'instagram sprite' => ['https://static.cdninstagram.com/rsrc.php/v4/yD/r/R0fBIMurK8v.png'],
            'instagram sprite with query' => ['https://static.cdninstagram.com/rsrc.php?v=4&x=1'],
            'instagram bare host' => ['https://cdninstagram.com/rsrc.php'],
            'instagram www' => ['https://www.instagram.com/rsrc.php/v4/yD/r/abc.png'],
            'facebook rsrc' => ['https://www.facebook.com/rsrc.php/v4/yD/r/abc.png'],
            'facebook cdn rsrc' => ['https://static.xx.fbcdn.net/rsrc.php/v4/yD/r/abc.png'],
            'mixed case' => ['HTTPS://Static.CDNInstagram.com/rsrc.php/v4/yD/r/abc.png'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realPhotos(): array
    {
        return [
            'instagram photo' => ['https://scontent.cdninstagram.com/v/t51/photo.jpg'],
            'instagram non-sprite path' => ['https://static.cdninstagram.com/v4/photo.jpg'],
            'non-meta host' => ['https://example.com/rsrc.php/v4/yD/r/abc.png'],
            'ordinary photo' => ['https://cdn.example.com/photo.jpg'],
            'empty' => [''],
            'whitespace' => ['   '],
            'not a url' => ['not a url'],
        ];
    }

    #[DataProvider('platformAssets')]
    public function test_flags_platform_sprites(string $url): void
    {
        $this->assertTrue(PhotoUrl::isPlatformAsset($url));
    }

    #[DataProvider('realPhotos')]
    public function test_allows_real_photos(string $url): void
    {
        $this->assertFalse(PhotoUrl::isPlatformAsset($url));
    }

    public function test_null_is_not_a_platform_asset(): void
    {
        $this->assertFalse(PhotoUrl::isPlatformAsset(null));
    }
}
