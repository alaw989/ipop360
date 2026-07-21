<?php

namespace App\Http\Controllers;

use App\Http\Resources\RestaurantResource;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\PopularityScoreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SearchController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
        private LiveSearchService $liveSearchService,
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price,social_presence,website_traffic',
            'price_range' => 'nullable|string|max:4',
            'distance' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'cuisine' => 'nullable|string|exists:cuisines,slug',
            'category' => 'nullable|string|exists:cuisine_categories,slug',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $validated['cuisine'] ?? null;
        $categorySlug = $validated['category'] ?? null;
        $priceRange = $validated['price_range'] ?? null;
        $distanceKm = (int) ($validated['distance'] ?? 25);
        $cuisineName = null;

        if ($cuisineSlug) {
            $cuisine = Cuisine::where('slug', $cuisineSlug)->with('category')->first();
            if ($cuisine) {
                $cuisineName = $cuisine->name;
                $categorySlug = $cuisine->category?->slug;
            }
        } elseif ($categorySlug) {
            $category = CuisineCategory::where('slug', $categorySlug)->first();
            if ($category) {
                $cuisineName = $category->name;
            }
        }

        $coords = $this->geolocationService->resolveCoordinates($request);

        // When no geolocation coords are available, try the configured fallback so
        // the distance filter and live search still work (e.g. on local dev where
        // IP geo is skipped for 127.0.0.1). Removed the $request->has('distance')
        // guard so the initial homepage search (which now always sends distance=25)
        // benefits from the same fallback as a distance-filter toggle.
        if ($coords === null) {
            $fallbackLat = config('restaurant-finder.live_search.distance_fallback_lat');
            $fallbackLng = config('restaurant-finder.live_search.distance_fallback_lng');
            if ($fallbackLat !== null && $fallbackLng !== null) {
                $coords = ['lat' => (float) $fallbackLat, 'lng' => (float) $fallbackLng];
            }
        }

        $query = Restaurant::query()
            ->when(
                $cuisineSlug,
                fn ($q) => $q->whereHas(
                    'cuisines',
                    fn ($cq) => $cq->where('slug', $cuisineSlug)
                )
            )
            ->when(
                $categorySlug && ! $cuisineSlug,
                fn ($q) => $q->whereHas(
                    'cuisines',
                    fn ($cq) => $cq->whereHas(
                        'category',
                        fn ($ccq) => $ccq->where('slug', $categorySlug)
                    )
                )
            )
            ->when(
                $coords !== null,
                fn ($q) => $q->nearby($coords['lat'], $coords['lng'], $distanceKm)
            )
            ->when(
                $priceRange,
                fn ($q) => $q->where('price_range', $priceRange)
            )
            ->active();

        $query = $this->applySort($query, $sort, $coords !== null);

        $restaurants = $query->paginate(20)->withQueryString();

        if ($restaurants->isEmpty() && $coords !== null) {
            $liveResults = $this->liveSearchService->search(
                $coords['lat'],
                $coords['lng'],
                $cuisineSlug,
                $categorySlug,
                false,
                $sort,
                $distanceKm,
            );

            if (! empty($liveResults)) {
                $persistCuisineIds = [];
                if ($cuisineSlug) {
                    $persistCuisineIds = Cuisine::where('slug', $cuisineSlug)->pluck('id')->all();
                } elseif ($categorySlug) {
                    $persistCuisineIds = Cuisine::whereHas(
                        'category',
                        fn ($q) => $q->where('slug', $categorySlug)
                    )->pluck('id')->all();
                }

                $defaultLocation = $coords !== null
                    ? $this->geolocationService->reverseGeocode($coords['lat'], $coords['lng'])
                    : null;

                $this->persistLiveResults($liveResults, $persistCuisineIds, $defaultLocation);

                $restaurants = $query->paginate(20)->withQueryString();
            }
        }

        $items = $restaurants->getCollection();
        $allItems = $items;

        $aggregates = app(PopularityScoreService::class)->computeAggregates($allItems);

        $formatted = RestaurantResource::collection($items);
        $formatted->collection->each(fn ($resource) => $resource
            ->withAllRestaurants($allItems)
            ->withAggregates($aggregates));

        $formattedArray = $formatted->resolve();
        $restaurants->setCollection(collect($formattedArray));

        $categoryCounts = CuisineCategory::select('cuisine_categories.id', 'cuisine_categories.name', 'cuisine_categories.slug')
            ->selectRaw('COUNT(DISTINCT restaurants.id) as restaurants_count')
            ->join('cuisines', 'cuisines.category_id', '=', 'cuisine_categories.id')
            ->join('cuisine_restaurant', 'cuisine_restaurant.cuisine_id', '=', 'cuisines.id')
            ->join('restaurants', 'restaurants.id', '=', 'cuisine_restaurant.restaurant_id')
            ->whereIn('restaurants.id', fn ($q) => $q->select('id')->from($query->select('id')))
            ->groupBy('cuisine_categories.id', 'cuisine_categories.name', 'cuisine_categories.slug')
            ->orderByDesc('restaurants_count')
            ->get()
            ->toArray();

        $filterOptions = [
            'categories' => $categoryCounts,
            'cuisines' => Cuisine::select('id', 'name', 'slug', 'category_id')->get()->toArray(),
            'priceOptions' => ['$', '$$', '$$$', '$$$$'],
            'distanceOptions' => [1, 5, 10, 25, 50],
        ];

        return Inertia::render('Search', [
            'restaurants' => $restaurants,
            'filters' => $request->only(['cuisine', 'category', 'lat', 'lng', 'sort', 'price_range', 'distance']),
            'cuisineName' => $cuisineName,
            'categorySlug' => $categorySlug,
            'filterOptions' => $filterOptions,
            'hasCoords' => $coords !== null,
        ]);
    }

    private function applySort(Builder $query, string $sort, bool $hasCoords): Builder
    {
        $decayedScore = Restaurant::decayedPopularityScoreExpression();

        return match ($sort) {
            'best_match' => $query->orderByRaw("{$decayedScore} DESC"),
            'nearest' => $hasCoords
                ? $query->orderBy('distance')
                : $query->orderByRaw("{$decayedScore} DESC"),
            'rating' => $query
                ->orderByRaw('COALESCE(google_rating, yelp_rating) DESC NULLS LAST')
                ->orderByRaw("{$decayedScore} DESC"),
            'reviews' => $query
                ->orderByRaw('COALESCE(google_review_count, yelp_review_count) DESC NULLS LAST')
                ->orderByRaw("{$decayedScore} DESC"),
            'price' => $query
                ->orderByRaw('
                    CASE
                        WHEN price_range IS NULL THEN 999
                        WHEN price_range = "$" THEN 1
                        WHEN price_range = "$$" THEN 2
                        WHEN price_range = "$$$" THEN 3
                        WHEN price_range = "$$$$" THEN 4
                        WHEN price_range = "€" THEN 1
                        WHEN price_range = "€€" THEN 2
                        WHEN price_range = "€€€" THEN 3
                        WHEN price_range = "€€€€" THEN 4
                        WHEN price_range = "£" THEN 1
                        WHEN price_range = "££" THEN 2
                        WHEN price_range = "£££" THEN 3
                        WHEN price_range = "££££" THEN 4
                        WHEN price_range = "¥" THEN 1
                        WHEN price_range = "¥¥" THEN 2
                        WHEN price_range = "¥¥¥" THEN 3
                        WHEN price_range = "¥¥¥¥" THEN 4
                        WHEN price_range = "₩" THEN 1
                        WHEN price_range = "₩₩" THEN 2
                        WHEN price_range = "₩₩₩" THEN 3
                        WHEN price_range = "₩₩₩₩" THEN 4
                        ELSE 2
                    END ASC
                ')
                ->orderByRaw("{$decayedScore} DESC"),
            'social_presence' => $query
                ->orderByRaw('CASE WHEN social_links IS NOT NULL THEN 1 ELSE 0 END DESC')
                ->orderByRaw("{$decayedScore} DESC"),
            'website_traffic' => $query
                ->orderByDesc('website_clicks_count')
                ->orderByRaw("{$decayedScore} DESC"),
            default => $query->orderByRaw("{$decayedScore} DESC"),
        };
    }

    private function persistLiveResults(array $results, array $cuisineIds = [], ?array $defaultLocation = null): void
    {
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

            $restaurant = null;
            if (! empty($attributes['google_place_id'])) {
                $restaurant = Restaurant::where('google_place_id', $attributes['google_place_id'])->first();
            }
            if (! $restaurant && ! empty($attributes['slug'])) {
                $restaurant = Restaurant::where('slug', $attributes['slug'])->first();
            }

            if ($restaurant) {
                $restaurant->update($attributes);
            } else {
                $restaurant = Restaurant::create($attributes);

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
        }
    }
}
