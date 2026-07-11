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
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price',
            'price_range' => 'nullable|string|max:4',
            'distance' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');
        $priceRange = $request->query('price_range');
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

        $query = $this->buildQuery($request)
            ->when(
                $coords !== null,
                fn ($q) => $q->nearby($coords['lat'], $coords['lng'], $distanceKm)
            )
            ->active();

        $query = $this->applySort($query, $sort, $coords !== null);

        $restaurants = $query->paginate(20)->withQueryString();

        $items = $restaurants->getCollection();
        $allItems = $items;

        $aggregates = app(PopularityScoreService::class)->computeAggregates($allItems);

        $formatted = RestaurantResource::collection($items);
        $formatted->collection->each(fn ($resource) => $resource
            ->withAllRestaurants($allItems)
            ->withAggregates($aggregates));

        $formattedArray = $formatted->resolve();
        $restaurants->setCollection(collect($formattedArray));

        $filterOptions = [
            'categories' => CuisineCategory::withCount('cuisines')->get()->toArray(),
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
        ]);
    }

    private function buildQuery(Request $request): Builder
    {
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');
        $priceRange = $request->query('price_range');

        return Restaurant::query()
            ->with('cuisines')
            ->when(
                $cuisineSlug,
                fn ($q) => $q->whereHas(
                    'cuisines',
                    fn ($cq) => $cq->where('slug', $cuisineSlug)
                )
            )
            ->when(
                $categorySlug && !$cuisineSlug,
                fn ($q) => $q->whereHas(
                    'cuisines',
                    fn ($cq) => $cq->whereHas(
                        'category',
                        fn ($ccq) => $ccq->where('slug', $categorySlug)
                    )
                )
            )
            ->when(
                $priceRange,
                fn ($q) => $q->where('price_range', $priceRange)
            );
    }

    private function applySort(Builder $query, string $sort, bool $hasCoords): Builder
    {
        return match ($sort) {
            'best_match' => $query->orderByDesc('popularity_score'),
            'nearest' => $hasCoords
                ? $query->orderBy('distance')
                : $query->orderByDesc('popularity_score'),
            'rating' => $query
                ->orderByRaw('COALESCE(google_rating, yelp_rating) DESC NULLS LAST')
                ->orderByDesc('popularity_score'),
            'reviews' => $query
                ->orderByRaw('COALESCE(google_review_count, yelp_review_count) DESC NULLS LAST')
                ->orderByDesc('popularity_score'),
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
                ->orderByDesc('popularity_score'),
            default => $query->orderByDesc('popularity_score'),
        };
    }
}
