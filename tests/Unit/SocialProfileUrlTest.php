<?php

namespace Tests\Unit;

use App\Support\SocialProfileUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the social-profile canonicalizer against the junk "links" the old raw
 * regex stored in production (namespace URIs, the Meta Pixel, share/intent
 * endpoints, id-less profile.php, post permalinks, builder/vendor accounts)
 * and the real profile shapes it must keep.
 */
class SocialProfileUrlTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validProvider(): array
    {
        return [
            'facebook handle' => ['https://www.facebook.com/KhuesKitchen/', 'facebook', 'https://www.facebook.com/KhuesKitchen'],
            'facebook handle with subpage' => ['https://m.facebook.com/KhuesKitchen/posts/12345', 'facebook', 'https://www.facebook.com/KhuesKitchen'],
            'facebook profile.php with id' => ['https://www.facebook.com/profile.php?id=100063536287714', 'facebook', 'https://www.facebook.com/profile.php?id=100063536287714'],
            'facebook legacy pages url' => ['https://www.facebook.com/pages/Taqueria-El-Sol/123456789012', 'facebook', 'https://www.facebook.com/pages/Taqueria-El-Sol/123456789012'],
            'facebook pg tab' => ['https://www.facebook.com/pg/KhuesKitchen/about/', 'facebook', 'https://www.facebook.com/KhuesKitchen'],
            'facebook long numeric page id' => ['https://www.facebook.com/100063536287714', 'facebook', 'https://www.facebook.com/100063536287714'],
            'fb.com short host' => ['https://fb.com/khueskitchen', 'facebook', 'https://www.facebook.com/khueskitchen'],
            'instagram handle' => ['https://instagram.com/khues.kitchen/', 'instagram', 'https://www.instagram.com/khues.kitchen'],
            'protocol-relative instagram' => ['//www.instagram.com/khues_kitchen', 'instagram', 'https://www.instagram.com/khues_kitchen'],
            'twitter handle' => ['https://twitter.com/khueskitchen', 'twitter', 'https://twitter.com/khueskitchen'],
            'x handle' => ['https://x.com/khueskitchen?lang=en', 'twitter', 'https://x.com/khueskitchen'],
            'tiktok handle' => ['https://www.tiktok.com/@khueskitchen?lang=en', 'tiktok', 'https://www.tiktok.com/@khueskitchen'],
            'youtube handle' => ['https://www.youtube.com/@KhuesKitchen/videos', 'youtube', 'https://www.youtube.com/@KhuesKitchen'],
            'youtube channel id' => ['https://youtube.com/channel/UCabcdefghijklmnopqrstuv', 'youtube', 'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv'],
            'youtube c name' => ['https://www.youtube.com/c/KhuesKitchen', 'youtube', 'https://www.youtube.com/c/KhuesKitchen'],
        ];
    }

    #[DataProvider('validProvider')]
    public function test_accepts_real_profiles(string $input, string $platform, string $canonical): void
    {
        $this->assertSame(['platform' => $platform, 'url' => $canonical], SocialProfileUrl::canonicalize($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function junkProvider(): array
    {
        return [
            // The three biggest prod offenders.
            'xmlns:fb namespace uri' => ['http://www.facebook.com/2008/fbml'],
            'meta pixel' => ['https://www.facebook.com/tr?id=1234567890&ev=PageView&noscript=1'],
            'profile.php without id' => ['https://www.facebook.com/profile.php'],
            // Endpoints.
            'facebook sharer' => ['https://www.facebook.com/sharer/sharer.php?u=https://example.com'],
            'facebook share.php' => ['https://www.facebook.com/share.php?u=x'],
            'facebook plugins' => ['https://www.facebook.com/plugins/page.php?href=x'],
            'facebook dialog' => ['https://www.facebook.com/dialog/feed?app_id=1'],
            'facebook groups' => ['https://www.facebook.com/groups/austinfoodies'],
            'facebook events' => ['https://www.facebook.com/events/123456789'],
            'facebook short numeric' => ['https://www.facebook.com/2010'],
            'instagram post' => ['https://www.instagram.com/p/CxYz123AbC/'],
            'instagram reel' => ['https://www.instagram.com/reel/CxYz123AbC/'],
            'instagram explore' => ['https://www.instagram.com/explore/tags/tacos/'],
            'twitter intent' => ['https://twitter.com/intent/tweet?text=hi'],
            'twitter share' => ['https://twitter.com/share?url=x'],
            'x i/status' => ['https://x.com/i/status/123'],
            'tiktok without @' => ['https://www.tiktok.com/tag/tacos'],
            'youtube video' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'youtu.be video' => ['https://youtu.be/dQw4w9WgXcQ'],
            'youtube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
            // Someone else's account / placeholders.
            'wix footer account' => ['https://www.facebook.com/wix'],
            'squarespace footer account' => ['https://www.instagram.com/squarespace'],
            'toast footer account' => ['https://twitter.com/toasttab'],
            'template placeholder' => ['https://www.facebook.com/yourpage'],
            'platform self-link' => ['https://www.instagram.com/instagram'],
            // Not social at all.
            'bare host' => ['https://www.facebook.com/'],
            'other domain' => ['https://www.example.com/facebook'],
            'relative path' => ['/facebook.com/khueskitchen'],
            'mailto' => ['mailto:hi@example.com'],
        ];
    }

    #[DataProvider('junkProvider')]
    public function test_rejects_non_profiles(string $input): void
    {
        $this->assertNull(SocialProfileUrl::canonicalize($input));
    }
}
