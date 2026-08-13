<?php

namespace App\Services;

use App\Models\Cuisine;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Log;

/**
 * Upsert a live-search venue array into the restaurants table, keyed by
 * google_place_id then slug (spec-104 audit: previously duplicated across
 * RestaurantController, SearchController, and a variant in FavoriteController).
 * Synthetic negative CRC32 ids are replaced with real
 * auto-increment ids so engagement tracking, detail pages, and lookups work.
 */
class LiveVenuePersister
{
    public function __construct(
        private RestaurantValidationService $restaurantValidation,
        private CuisineMatcher $cuisineMatcher,
        private GeolocationService $geolocationService,
    ) {}

    /**
     * Persist one venue. Returns the restaurant, whether it was created (vs
     * updated), and the venue array annotated with its real DB id.
     *
     * @param  array<string, mixed>  $venue  normalized live-search venue array
     * @param  int[]  $cuisineIds  cuisine ids to attach (when provided)
     * @param  array<string, mixed>|null  $defaultLocation  reverse-geocoded [city, state] fallback
     * @return array{restaurant: Restaurant, created: bool, venue: array<string, mixed>}
     */
    public function persist(array $venue, array $cuisineIds = [], ?array $defaultLocation = null): array
    {
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

        $attributes = $this->restaurantValidation->normalize($attributes);

        $restaurant = null;
        if (! empty($attributes['google_place_id'])) {
            $restaurant = Restaurant::where('google_place_id', $attributes['google_place_id'])->first();
        }
        if (! $restaurant && ! empty($attributes['slug'])) {
            $restaurant = Restaurant::where('slug', $attributes['slug'])->first();
        }

        $created = false;

        if ($restaurant) {
            // Update: exclude has_award so a live-search source (which hardcodes
            // has_award => false) can never clobber a true award set by
            // RestaurantEnrichmentService::enrichAwards. (spec-104 award audit)
            unset($attributes['has_award']);
            $restaurant->update($attributes);
        } else {
            $restaurant = Restaurant::create($attributes);
            $created = true;

            Log::channel('enrichment')->info('Venue created via search', [
                'restaurant_id' => $restaurant->id,
                'restaurant_name' => $restaurant->name,
                'city' => $restaurant->city,
                'state' => $restaurant->state,
                'source' => $venue['source'] ?? 'api',
                'google_place_id' => $restaurant->google_place_id,
            ]);
        }

        if (! empty($cuisineIds)) {
            $restaurant->cuisines()->syncWithoutDetaching($cuisineIds);
        }

        $venue['id'] = $restaurant->id;

        return [
            'restaurant' => $restaurant,
            'created' => $created,
            'venue' => $venue,
        ];
    }

    /**
     * Persist a batch of live-search venues with evidence-gated cuisine tags.
     * The candidate tag set is the searched cuisine, or (for a category search)
     * every member cuisine. The live search is
     * recall-protective (ambiguous venues are kept, ranked low), so only venues
     * carrying positive evidence for a candidate are tagged, plus any OSM
     * cuisine tags already carried on the venue row resolved to real ids.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array{created: int, updated: int}
     */
    public function persistTaggedVenues(
        array $results,
        ?string $cuisineSlug,
        ?string $categorySlug,
        float $lat,
        float $lng,
    ): array {
        $candidateSlugs = [];
        if ($cuisineSlug) {
            $candidateSlugs = [$cuisineSlug];
        } elseif ($categorySlug) {
            $candidateSlugs = Cuisine::whereHas(
                'category',
                fn ($q) => $q->where('slug', $categorySlug)
            )->pluck('slug')->all();
        }

        $slugToId = Cuisine::pluck('id', 'slug')->all();
        $defaultLocation = $this->geolocationService->reverseGeocode($lat, $lng);

        $created = 0;
        $updated = 0;

        foreach ($results as $venue) {
            $ids = [];
            foreach ($candidateSlugs as $slug) {
                if ($this->cuisineMatcher->venueMatchesCuisine($venue, $slug)) {
                    $ids[] = $slugToId[$slug] ?? null;
                }
            }

            foreach (($venue['cuisines'] ?? []) as $venueCuisine) {
                $slug = strtolower((string) ($venueCuisine['slug'] ?? ''));
                if ($slug !== '' && isset($slugToId[$slug])) {
                    $ids[] = $slugToId[$slug];
                }
            }

            $ids = array_values(array_unique(array_filter($ids)));

            $result = $this->persist($venue, $ids, $defaultLocation);

            if ($result['created']) {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Filter venue cuisine ids to those that exist in the cuisines table.
     *
     * @param  array<string, mixed>  $venue
     * @return int[]
     */
    public function knownCuisineIds(array $venue): array
    {
        $known = Cuisine::pluck('id')->all();

        return array_filter(
            array_column($venue['cuisines'] ?? [], 'id'),
            fn ($id) => in_array($id, $known, true)
        );
    }
}
