<?php

namespace App\Jobs;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\RestaurantValidationService;
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
        RestaurantValidationService $restaurantValidation,
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
            $city = $venue['city'] ?? ($defaultLocation['city'] ?? null);
            $state = $venue['state'] ?? ($defaultLocation['state'] ?? null);

            $attributes = [
                'name' => $venue['name'] ?? 'Unknown',
                'slug' => $venue['slug'] ?? null,
                'description' => $venue['description'] ?? null,
                'address' => $venue['address'] ?? null,
                'city' => $city,
                'state' => $state,
                'postal_code' => $venue['postal_code'] ?? null,
                'latitude' => $venue['lat'] ?? null,
                'longitude' => $venue['lng'] ?? null,
                'phone' => $venue['phone'] ?? null,
                'website_url' => $venue['website_url'] ?? null,
                'price_range' => $venue['price_range'] ?? null,
                'photo_url' => $venue['photo_url'] ?? null,
                'photos' => $venue['photos'] ?? [],
                'google_place_id' => $venue['google_place_id'] ?? null,
                'google_rating' => $venue['google_rating'] ?? null,
                'google_review_count' => (int) ($venue['google_review_count'] ?? 0),
                'yelp_rating' => $venue['yelp_rating'] ?? null,
                'yelp_review_count' => (int) ($venue['yelp_review_count'] ?? 0),
                'has_award' => $venue['has_award'] ?? false,
                'popularity_score' => $venue['popularity_score'] ?? null,
                'features' => $venue['features'] ?? [],
                'is_active' => true,
            ];

            $attributes = $restaurantValidation->normalize($attributes);

            $restaurant = null;
            if (! empty($attributes['google_place_id'])) {
                $restaurant = Restaurant::where('google_place_id', $attributes['google_place_id'])->first();
            }
            if (! $restaurant && ! empty($attributes['slug'])) {
                $restaurant = Restaurant::where('slug', $attributes['slug'])->first();
            }

            if ($restaurant) {
                $restaurant->update($attributes);
                $updated++;
            } else {
                $restaurant = Restaurant::create($attributes);
                $created++;

                Log::channel('enrichment')->info('Venue created via search enrichment', [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'city' => $restaurant->city,
                    'state' => $restaurant->state,
                    'source' => $venue['source'] ?? 'api',
                    'google_place_id' => $restaurant->google_place_id,
                ]);
            }

            if (! empty($persistCuisineIds)) {
                $restaurant->cuisines()->syncWithoutDetaching($persistCuisineIds);
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
