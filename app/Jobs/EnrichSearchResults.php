<?php

namespace App\Jobs;

use App\Models\Cuisine;
use App\Services\CuisineMatcher;
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
        CuisineMatcher $cuisineMatcher,
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

        // Candidate tags for a venue: the searched cuisine, or (for a category
        // search) every member cuisine. The live search is recall-protective
        // (ambiguous venues are kept, ranked low), so we must NOT blanket-tag
        // every result — only venues that carry evidence for a candidate are
        // tagged. A category search no longer stamps all member cuisines.
        $candidateSlugs = [];
        if ($this->cuisineSlug) {
            $candidateSlugs = [$this->cuisineSlug];
        } elseif ($this->categorySlug) {
            $candidateSlugs = Cuisine::whereHas(
                'category',
                fn ($q) => $q->where('slug', $this->categorySlug)
            )->pluck('slug')->all();
        }

        // slug => real DB id for any cuisine we may attach (candidates and
        // OSM tags carried on the venue row).
        $slugToId = Cuisine::pluck('id', 'slug')->all();

        $defaultLocation = $geolocationService->reverseGeocode($this->lat, $this->lng);

        $created = 0;
        $updated = 0;

        foreach ($results as $venue) {
            $ids = [];
            foreach ($candidateSlugs as $slug) {
                if ($cuisineMatcher->venueMatchesCuisine($venue, $slug)) {
                    $ids[] = $slugToId[$slug] ?? null;
                }
            }

            // OSM-sourced tags already carried by the venue resolve to real ids.
            foreach (($venue['cuisines'] ?? []) as $venueCuisine) {
                $slug = strtolower((string) ($venueCuisine['slug'] ?? ''));
                if ($slug !== '' && isset($slugToId[$slug])) {
                    $ids[] = $slugToId[$slug];
                }
            }

            $ids = array_values(array_unique(array_filter($ids)));

            $result = $venuePersister->persist($venue, $ids, $defaultLocation);

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
