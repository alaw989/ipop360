<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Write-time copy of a restaurant's photo.
 *
 * Dispatched by Restaurant::saved whenever photo_url changes, so the
 * self-hosted copy is taken within minutes, while the source URL is fresh.
 * Google's gps-cs-s URLs stop working a few weeks after they're issued; a copy
 * taken late is a copy never taken. The daily restaurants:photo-thumbnails
 * sweep backstops writes that bypass model events (bulk query updates).
 */
class GeneratePhotoThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /** Hold the uniqueness lock at most this long (seconds). */
    public int $uniqueFor = 900;

    public function __construct(
        public int $restaurantId
    ) {
        //
    }

    public function uniqueId(): string
    {
        return (string) $this->restaurantId;
    }

    public function handle(PhotoThumbnailService $thumbs): void
    {
        $restaurant = Restaurant::find($this->restaurantId);

        if ($restaurant === null || $thumbs->expectedFilename($restaurant) === null || $thumbs->hasThumb($restaurant)) {
            return;
        }

        $filename = $thumbs->generate($restaurant);

        if ($filename === null) {
            // Retry: a transient download failure shouldn't cost the photo.
            // The last attempt leaves the row to the daily sweep.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 600);

                return;
            }

            Log::channel('enrichment')->info('Photo copy failed at write time', [
                'restaurant_id' => $restaurant->id,
                'photo_url' => $restaurant->photo_url,
            ]);

            return;
        }

        // A quiet write: photo_thumb is derived, so it must not re-trigger the
        // saved hook or bump updated_at.
        Restaurant::query()->whereKey($restaurant->id)->toBase()->update(['photo_thumb' => $filename]);
    }
}
