<?php

namespace App\Jobs;

use App\Models\Cuisine;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\LiveVenuePersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrichSearchResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public float $lat,
        public float $lng,
        public ?string $cuisineSlug,
        public ?string $categorySlug,
        public string $sort,
        public float $distanceKm,
    ) {}

    public function handle(
        LiveSearchService $liveSearchService,
        GeolocationService $geolocationService,
        LiveVenuePersister $venuePersister,
    ): void {
        $results = $liveSearchService->search(
            $this->lat,
            $this->lng,
            $this->cuisineSlug,
            $this->categorySlug,
            false,
            $this->sort,
            $this->distanceKm,
        );

        if (empty($results)) {
            Log::channel('enrichment')->info('EnrichSearchResults: no live results found', [
                'lat' => $this->lat,
                'lng' => $this->lng,
                'cuisine' => $this->cuisineSlug,
                'category' => $this->categorySlug,
            ]);

            return;
        }

        $persistCuisineIds = [];
        if ($this->cuisineSlug) {
            $persistCuisineIds = Cuisine::where('slug', $this->cuisineSlug)->pluck('id')->all();
        } elseif ($this->categorySlug) {
            $persistCuisineIds = Cuisine::whereHas(
                'category',
                fn ($q) => $q->where('slug', $this->categorySlug)
            )->pluck('id')->all();
        }

        $defaultLocation = $geolocationService->reverseGeocode($this->lat, $this->lng);

        $created = 0;
        $updated = 0;

        foreach ($results as $venue) {
            $result = $venuePersister->persist($venue, $persistCuisineIds, $defaultLocation);

            if ($result['created']) {
                $created++;
            } else {
                $updated++;
            }
        }

        Log::channel('enrichment')->info('EnrichSearchResults: complete', [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'cuisine' => $this->cuisineSlug,
            'created' => $created,
            'updated' => $updated,
        ]);
    }
}
