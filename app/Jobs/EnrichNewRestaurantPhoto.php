<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ingestion-time photo hunt for a freshly created restaurant.
 *
 * Dispatched from LiveVenuePersister::persist() only when a row is CREATED
 * with no photo_url. Runs the context-first searchImageForRestaurant chain
 * (website → social → Wikidata → Wikimedia → Wikipedia → Google CSE) in the
 * background so a new row is photo-rich within minutes without blocking the
 * search response.
 *
 * Created-only: skips when the row already has a photo. Domain-safe: a
 * transient gps-cs-s CDN result is never promoted to primary photo_url.
 */
class EnrichNewRestaurantPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The maximum number of seconds the job should run.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $restaurantId
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(RestaurantWebsiteScraperService $scraper): void
    {
        $restaurant = Restaurant::find($this->restaurantId);

        if ($restaurant === null) {
            return;
        }

        if (! empty($restaurant->photo_url)) {
            return;
        }

        try {
            $result = $scraper->searchImageForRestaurant($restaurant);

            if ($result === null || $result['url'] === '') {
                return;
            }

            $photoUrl = $result['url'];

            if ($this->isGpsCsSPhoto($photoUrl)) {
                Log::channel('enrichment')->debug('Photo hunt skipped transient gps-cs-s result', [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'photo_url' => $photoUrl,
                ]);

                return;
            }

            $restaurant->update(['photo_url' => $photoUrl, 'photo_source' => $result['source']]);

            Log::channel('enrichment')->info('Ingestion-time photo hunt found photo', [
                'restaurant_id' => $restaurant->id,
                'restaurant_name' => $restaurant->name,
                'photo_url' => $photoUrl,
                'photo_source' => $result['source'],
            ]);
        } catch (\Throwable $e) {
            Log::channel('enrichment')->warning('Ingestion-time photo hunt failed', [
                'restaurant_id' => $restaurant->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Detect a Google gps-cs-s CDN photo URL (transient, ~1-month decay).
     */
    private function isGpsCsSPhoto(string $url): bool
    {
        return str_contains(strtolower($url), 'gps-cs-s');
    }
}
