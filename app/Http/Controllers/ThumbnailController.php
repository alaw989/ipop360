<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a generated photo thumbnail from the private disk.
 *
 * The file name embeds the id and the photo hash, so the route re-validates
 * both against the row before serving: a thumbnail whose photo_url has since
 * changed (or that was never stored) 404s instead of leaking a stale image.
 * The name is content-addressed, so it is safe to cache "immutable".
 */
class ThumbnailController extends Controller
{
    public function __invoke(string $file, PhotoThumbnailService $thumbs): BinaryFileResponse
    {
        $id = strstr($file, '-', true);
        $restaurant = $id === false ? null : Restaurant::query()->find((int) $id);

        if ($restaurant === null || ! $thumbs->isValidFilename($restaurant, $file)) {
            abort(404);
        }

        $path = $thumbs->storagePath($file);
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
