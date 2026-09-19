<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds and serves self-hosted WebP copies of restaurant photos.
 *
 * Every photo is downloaded once and stored as a width-capped WebP under the
 * private disk, served by /thumbs/{file}. That does two jobs: a venue host's
 * 16 MB original no longer ships to a 96 px card, and the photo survives its
 * source going away. Google's gps-cs-s photo URLs stop working a few weeks
 * after SerpApi hands them out, so a copy taken while the URL is fresh is the
 * only way to keep those photos; Google URLs are fetched at the thumbnail
 * width rather than whatever size the stored URL asks for.
 *
 * The stored file name embeds the first 10 hex chars of sha1(photo_url), so a
 * thumbnail is only served while it still matches the row's current photo_url —
 * a replaced photo can never serve a stale image. Bounds: SSRF guard on the
 * URL and every redirect hop, a 25 MB download cap, and a 50 MP decode cap.
 */
class PhotoThumbnailService
{
    /** Directory on the private disk that holds generated thumbnails. */
    public const DIRECTORY = 'thumbs';

    /**
     * The file name this row's current photo_url should have, or null when the
     * row has no photo.
     */
    public function expectedFilename(Restaurant $restaurant): ?string
    {
        $url = trim((string) $restaurant->photo_url);
        if ($url === '') {
            return null;
        }

        return $restaurant->id.'-'.substr(sha1($url), 0, 10).'.webp';
    }

    /**
     * Whether the stored photo_thumb still corresponds to the current photo_url
     * (hash match). Cheap — no disk I/O — so the API resource can call it per
     * row. The serving route checks the file itself.
     */
    public function matches(Restaurant $restaurant): bool
    {
        $expected = $this->expectedFilename($restaurant);

        return $expected !== null && $restaurant->photo_thumb === $expected;
    }

    /**
     * Whether a usable thumbnail exists on disk for this row (hash match AND
     * the file is present).
     */
    public function hasThumb(Restaurant $restaurant): bool
    {
        return $this->matches($restaurant)
            && Storage::disk('local')->exists($this->storagePath((string) $restaurant->photo_thumb));
    }

    /**
     * Whether a requested file name is the one this row's current photo_url
     * should have. Used by the serving route — it validates the name in the
     * URL, not the cached column, so a stale column can never serve.
     */
    public function isValidFilename(Restaurant $restaurant, string $filename): bool
    {
        return $this->expectedFilename($restaurant) === $filename;
    }

    /**
     * Download, resize and store the WebP. Returns the stored file name, or
     * null when the row has no photo or
     * the source could not be turned into an image.
     */
    public function generate(Restaurant $restaurant): ?string
    {
        $expected = $this->expectedFilename($restaurant);
        if ($expected === null) {
            return null;
        }

        $url = trim((string) $restaurant->photo_url);
        $width = (int) config('restaurant-finder.photo_thumbs.width', 640);

        if (config('restaurant-finder.photo_thumbs.ssrf_guard', true) && ! SsrfGuard::isSafe($url)) {
            Log::channel('enrichment')->warning('Photo thumbnail blocked by SSRF guard', [
                'restaurant_id' => $restaurant->id,
                'photo_url' => $url,
            ]);

            return null;
        }

        $binary = $this->download($this->sizedSourceUrl($url, $width));
        if ($binary === null) {
            return null;
        }

        $webp = $this->encodeWebp($binary, $width);
        if ($webp === null) {
            return null;
        }

        Storage::disk('local')->put($this->storagePath($expected), $webp);

        return $expected;
    }

    /**
     * The path on the private disk for a stored file name.
     */
    public function storagePath(string $filename): string
    {
        return self::DIRECTORY.'/'.$filename;
    }

    /**
     * Download the photo, capped at max_download_bytes. Streams to a temp file
     * so an oversized body can be rejected without holding it in memory.
     */
    private function download(string $url): ?string
    {
        $maxBytes = (int) config('restaurant-finder.photo_thumbs.max_download_bytes', 25 * 1024 * 1024);
        $timeout = (float) config('restaurant-finder.photo_thumbs.timeout', 12.0);
        $tmp = tempnam(sys_get_temp_dir(), 'ipop-thumb-');

        if ($tmp === false) {
            return null;
        }

        try {
            $options = [
                'allow_redirects' => config('restaurant-finder.photo_thumbs.ssrf_guard', true)
                    ? SsrfGuard::redirectOptions()
                    : ['max' => 3],
                'sink' => $tmp,
            ];

            // spec-103: pin the fetch to the validated IP so a rebinding resolver
            // can't re-point it at a private/metadata address after isSafe().
            if (config('restaurant-finder.photo_thumbs.ssrf_guard', true)) {
                $options = [...$options, ...SsrfGuard::pinnedOptions($url)];
            }

            $response = Http::timeout($timeout)
                ->withOptions($options)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $size = is_file($tmp) ? filesize($tmp) : false;
            if ($size === false || $size === 0 || $size > $maxBytes) {
                return null;
            }

            $binary = file_get_contents($tmp);

            return $binary === false ? null : $binary;
        } catch (\Throwable $e) {
            Log::channel('enrichment')->warning('Photo thumbnail download failed', [
                'photo_url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Resize to the configured width and encode as WebP. Public so tests can
     * exercise it directly (skipped where GD is unavailable). Returns null for
     * a non-image, an image over the pixel cap, or a failed encode.
     */
    public function encodeWebp(string $binary, int $width): ?string
    {
        if (! function_exists('getimagesizefromstring') || ! function_exists('imagecreatefromstring')) {
            return null; // GD not installed
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return null; // not a decodable image
        }

        [$sourceWidth, $sourceHeight] = $info;
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return null;
        }

        $maxPixels = (int) config('restaurant-finder.photo_thumbs.max_pixels', 50_000_000);
        if ($sourceWidth * $sourceHeight > $maxPixels) {
            return null; // decompression-bomb guard
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $targetWidth = max(1, min($width, $sourceWidth));
        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        $quality = (int) config('restaurant-finder.photo_thumbs.quality', 78);

        ob_start();
        $encoded = imagewebp($target, null, $quality);
        $data = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $encoded && $data !== '' ? $data : null;
    }

    /**
     * The public URL of this row's stored copy, or null when it has none that
     * matches the current photo_url.
     */
    public function publicUrl(Restaurant $restaurant): ?string
    {
        return $this->matches($restaurant) ? '/thumbs/'.$restaurant->photo_thumb : null;
    }

    /**
     * Add photo_thumb_url to live-search result arrays whose persisted row has
     * a matching copy. Live results replay photo URLs from a 30-day search
     * cache, so Google ones are often dead by the time they're shown; one
     * batched query per page swaps in the stored copy.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<int|string, array<string, mixed>>
     */
    public function attachPublicUrls(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_int($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $urls = Restaurant::query()
            ->whereIn('id', array_unique($ids))
            ->whereNotNull('photo_thumb')
            ->get(['id', 'photo_url', 'photo_thumb'])
            ->mapWithKeys(fn (Restaurant $r): array => [$r->id => $this->publicUrl($r)])
            ->filter()
            ->all();

        foreach ($rows as $key => $row) {
            $url = is_int($row['id'] ?? null) ? ($urls[$row['id']] ?? null) : null;
            if ($url !== null) {
                $rows[$key]['photo_thumb_url'] = $url;
            }
        }

        return $rows;
    }

    /**
     * Stored thumbnail files that no longer belong to their row's current
     * photo (the photo changed, was cleared, or the row is gone).
     *
     * @return list<string> file names
     */
    public function orphanedFiles(): array
    {
        $files = [];
        foreach (Storage::disk('local')->files(self::DIRECTORY) as $path) {
            $files[basename($path)] = (int) strstr(basename($path), '-', true);
        }

        if ($files === []) {
            return [];
        }

        $expected = [];
        foreach (array_chunk(array_unique(array_values($files)), 1000) as $ids) {
            Restaurant::query()->whereIn('id', $ids)->get(['id', 'photo_url'])
                ->each(function (Restaurant $r) use (&$expected): void {
                    $name = $this->expectedFilename($r);
                    if ($name !== null) {
                        $expected[$name] = true;
                    }
                });
        }

        return array_values(array_filter(
            array_keys($files),
            fn (string $name): bool => ! isset($expected[$name]),
        ));
    }

    /**
     * Google photo URLs carry their size after the last "=" ("=w400-h300-c-no");
     * ask for one that fits the thumbnail width instead. Mirrors
     * googleSrcset() in resources/js/lib/responsiveImage.ts. Other hosts are
     * fetched as stored.
     */
    public function sizedSourceUrl(string $url, int $width): string
    {
        if (preg_match('/^(https:\/\/lh\d\.googleusercontent\.com\/[^=?#]+)(?:=[\w-]*)?$/', $url, $m) === 1) {
            return $m[1].'=w'.$width.'-h'.$width;
        }

        return $url;
    }
}
