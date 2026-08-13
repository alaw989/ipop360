<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SortsRestaurantQueries;
use App\Http\Resources\RestaurantResource;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\LiveSearchService;
use App\Services\LiveVenuePersister;
use App\Services\PopularityScoreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    use SortsRestaurantQueries;

    public function __construct(
        private GeolocationService $geolocationService,
        private LiveSearchService $liveSearchService,
        private LiveVenuePersister $venuePersister,
    ) {}

    public function __invoke(Request $request): Response
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
                function ($q) use ($coords, $distanceKm) {
                    assert($coords !== null);

                    return $q->nearby($coords['lat'], $coords['lng'], $distanceKm);
                }
            )
            ->when(
                $priceRange,
                fn ($q) => $q->where('price_range', $priceRange)
            )
            ->active();

        $query = $this->applySort($query, $sort, $coords !== null);

        $restaurants = $query->paginate(20)->withQueryString();

        if ($restaurants->isEmpty() && $coords !== null) {
            // Run the live search synchronously (mirrors apiIndex) so results
            // appear immediately instead of waiting for the async enrichment
            // job + frontend polling. Persist the venues with evidence-gated
            // cuisine tags, then re-query the now-populated DB so rows flow
            // through the standard RestaurantResource pipeline with real ids.
            $liveResults = $this->liveSearchService->search(
                $coords['lat'],
                $coords['lng'],
                $cuisineSlug,
                $categorySlug,
                false, // cacheOnly
                $sort,
                (float) $distanceKm,
            );

            if (! empty($liveResults)) {
                $this->venuePersister->persistTaggedVenues($liveResults, $cuisineSlug, $categorySlug, $coords['lat'], $coords['lng']);

                $restaurants = $query->paginate(20)->withQueryString();
            }
        }

        $items = $restaurants->getCollection();
        $allItems = $items;

        $aggregates = app(PopularityScoreService::class)->computeAggregates($allItems);

        $formatted = RestaurantResource::collection($items);
        if ($formatted->collection !== null) {
            $formatted->collection->each(fn ($resource) => $resource
                ->withAllRestaurants($allItems)
                ->withAggregates($aggregates));
        }

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

    /**
     * @param  Builder<Restaurant>  $query
     * @return Builder<Restaurant>
     */
    private function applySort(Builder $query, string $sort, bool $hasCoords): Builder
    {
        // Search-only sort modes (not exposed by the persisted-db endpoint).
        if (in_array($sort, ['social_presence', 'website_traffic'], true)) {
            return match ($sort) {
                'social_presence' => $query
                    ->orderByRaw('CASE WHEN social_links_count > 0 THEN 1 ELSE 0 END DESC')
                    ->orderByDecayedScore(),
                'website_traffic' => $query
                    ->orderByDesc('website_clicks_count')
                    ->orderByDecayedScore(),
            };
        }

        return $this->applyRestaurantSort($query, $sort, $hasCoords);
    }
}
