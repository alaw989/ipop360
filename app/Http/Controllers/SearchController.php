<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SortsRestaurantQueries;
use App\Http\Resources\LiveRestaurantResource;
use App\Http\Resources\RestaurantResource;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Services\PopularityScoreService;
use App\Services\UnifiedSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    /** The price levels a search can filter on, cheapest first. */
    private const PRICE_LEVELS = ['$', '$$', '$$$', '$$$$'];

    use SortsRestaurantQueries;

    public function __construct(
        private GeolocationService $geolocationService,
        private UnifiedSearchService $unifiedSearch,
    ) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price,social_presence,website_traffic',
            'price_range' => 'nullable',
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
        $priceRanges = $this->priceLevels($request->input('price_range'));
        $distanceMiles = (int) ($validated['distance'] ?? 25);
        $distanceKm = $distanceMiles * 1.60934;
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

        if ($coords !== null) {
            [$restaurants, $union] = $this->mergedSearch(
                $request, $coords, $cuisineSlug, $categorySlug, $sort, $distanceKm, $priceRanges
            );
            $categoryCounts = $this->categoryCountsForUnion($union);
        } else {
            [$restaurants, $query] = $this->dbOnlySearch($request, $cuisineSlug, $categorySlug, $priceRanges, $sort);
            $categoryCounts = $this->categoryCountsForQuery($query);
        }

        $filterOptions = [
            'categories' => $categoryCounts,
            'cuisines' => Cuisine::select('id', 'name', 'slug', 'category_id')->get()->toArray(),
            'priceOptions' => self::PRICE_LEVELS,
            'distanceOptions' => [1, 5, 10, 25, 50],
        ];

        $filters = $request->only(['cuisine', 'category', 'lat', 'lng', 'sort', 'distance']);
        if ($priceRanges !== null) {
            $filters['price_range'] = $priceRanges;
        }

        return Inertia::render('Search', [
            'restaurants' => $restaurants,
            'filters' => $filters,
            'cuisineName' => $cuisineName,
            'categorySlug' => $categorySlug,
            'filterOptions' => $filterOptions,
            'hasCoords' => $coords !== null,
        ]);
    }

    /**
     * Unified merged search (coords path): ALWAYS run the live free-source
     * search, merge persisted DB rows into it, and rank the union by popularity
     * score in one pass (UnifiedSearchService). Mirrors RestaurantController::
     * apiIndex — page 1 snapshots the full user-sorted union; pages 2+ slice that
     * snapshot (no re-search, deterministic pagination).
     *
     * @param  array{lat: float, lng: float}  $coords
     * @param  list<string>|null  $priceRanges
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function mergedSearch(
        Request $request,
        array $coords,
        ?string $cuisineSlug,
        ?string $categorySlug,
        string $sort,
        float $distanceKm,
        ?array $priceRanges,
    ): array {
        $paginate = filter_var(config('restaurant-finder.live_search.paginate', true), FILTER_VALIDATE_BOOL);
        $page = max(1, (int) $request->query('page', '1'));

        // The page-2+ short-circuit must gate the search() CALL itself — not just
        // the slice — or page 2 re-burns the live sources and re-ranks a fresh
        // union (breaking deterministic pagination).
        $union = [];
        if (! $paginate || $page <= 1) {
            $union = $this->unifiedSearch->search(
                $coords['lat'],
                $coords['lng'],
                $cuisineSlug,
                $categorySlug,
                $sort,
                (float) $distanceKm,
                $priceRanges,
            );
        }

        return $this->paginateUnion($request, $union, $coords, $cuisineSlug, $categorySlug, $sort, $priceRanges);
    }

    /**
     * Shape a merged union into an Inertia paginator envelope (plain array,
     * serialized by Inertia into the same data/current_page/last_page/next_page_url
     * shape the DB paginator produces). Page 1 snapshots the union; pages 2+ slice
     * it from the snapshot. Rows are formatted via LiveRestaurantResource (union
     * rows are plain arrays, not Eloquent models).
     *
     * @param  array<int, array<string, mixed>>  $union  (empty on pages 2+ — sliced from the snapshot)
     * @param  array{lat: float, lng: float}  $coords
     * @param  list<string>|null  $priceRanges
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function paginateUnion(
        Request $request,
        array $union,
        array $coords,
        ?string $cuisineSlug,
        ?string $categorySlug,
        string $sort,
        ?array $priceRanges,
    ): array {
        $paginate = filter_var(config('restaurant-finder.live_search.paginate', true), FILTER_VALIDATE_BOOL);
        $perPage = (int) config('restaurant-finder.live_search.page_size', 20);
        $page = max(1, (int) $request->query('page', '1'));

        $pageKey = 'search_page:'.md5(serialize([
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'cuisine' => $cuisineSlug,
            'category' => $categorySlug,
            'sort' => $sort,
            'price_range' => $priceRanges,
        ]));

        if ($paginate && $page > 1) {
            $snapshotted = ExternalApiCache::findByKey($pageKey);
            $fullResults = is_array($snapshotted) ? $snapshotted : [];
        } else {
            $fullResults = $union;

            if ($paginate) {
                ExternalApiCache::storeByKey(
                    $pageKey,
                    $fullResults,
                    now()->addMinutes((int) config('restaurant-finder.live_search.page_snapshot_minutes', 10))
                );
            }
        }

        $total = count($fullResults);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $effectivePage = $paginate ? min($page, $lastPage) : 1;
        $slice = $paginate
            ? array_slice($fullResults, ($effectivePage - 1) * $perPage, $perPage)
            : $fullResults;

        $hasNext = $paginate && $effectivePage < $lastPage;
        $hasPrev = $paginate && $effectivePage > 1;

        $restaurants = [
            'data' => LiveRestaurantResource::collection($slice)->resolve(),
            'current_page' => $effectivePage,
            'last_page' => $lastPage,
            'per_page' => $paginate ? $perPage : $total,
            'total' => $total,
            'prev_page_url' => $hasPrev ? $request->fullUrlWithQuery(['page' => $effectivePage - 1]) : null,
            'next_page_url' => $hasNext ? $request->fullUrlWithQuery(['page' => $effectivePage + 1]) : null,
        ];

        return [$restaurants, $fullResults];
    }

    /**
     * DB-only search (no geolocation available): the persisted-db path. Serves
     * RestaurantResource over an Eloquent paginator (parity with index()).
     *
     * @param  list<string>|null  $priceRanges
     * @return array{0: LengthAwarePaginator<int, mixed>, 1: Builder<Restaurant>}
     */
    private function dbOnlySearch(
        Request $request,
        ?string $cuisineSlug,
        ?string $categorySlug,
        ?array $priceRanges,
        string $sort,
    ): array {
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
                $priceRanges,
                fn ($q) => $q->whereIn('price_range', $priceRanges)
            )
            ->active();

        $query = $this->applySort($query, $sort, false);

        $restaurants = $query->paginate(20)->withQueryString();

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

        return [$restaurants, $query];
    }

    /**
     * Category counts for the merged union (the union IS the filtered set, so
     * the sidebar counts are the distinct restaurant ids across its rows).
     *
     * @param  array<int, array<string, mixed>>  $union
     * @return array<int, array<string, mixed>>
     */
    private function categoryCountsForUnion(array $union): array
    {
        $ids = [];
        foreach ($union as $row) {
            $id = $row['id'] ?? null;
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return CuisineCategory::select('cuisine_categories.id', 'cuisine_categories.name', 'cuisine_categories.slug')
            ->selectRaw('COUNT(DISTINCT restaurants.id) as restaurants_count')
            ->join('cuisines', 'cuisines.category_id', '=', 'cuisine_categories.id')
            ->join('cuisine_restaurant', 'cuisine_restaurant.cuisine_id', '=', 'cuisines.id')
            ->join('restaurants', 'restaurants.id', '=', 'cuisine_restaurant.restaurant_id')
            ->whereIn('restaurants.id', $ids)
            ->groupBy('cuisine_categories.id', 'cuisine_categories.name', 'cuisine_categories.slug')
            ->orderByDesc('restaurants_count')
            ->get()
            ->toArray();
    }

    /**
     * Category counts for the DB-only path (counts over the filtered $query).
     *
     * @param  Builder<Restaurant>  $query
     * @return array<int, array<string, mixed>>
     */
    private function categoryCountsForQuery(Builder $query): array
    {
        return CuisineCategory::select('cuisine_categories.id', 'cuisine_categories.name', 'cuisine_categories.slug')
            ->selectRaw('COUNT(DISTINCT restaurants.id) as restaurants_count')
            ->join('cuisines', 'cuisines.category_id', '=', 'cuisine_categories.id')
            ->join('cuisine_restaurant', 'cuisine_restaurant.cuisine_id', '=', 'cuisines.id')
            ->join('restaurants', 'restaurants.id', '=', 'cuisine_restaurant.restaurant_id')
            ->whereIn('restaurants.id', fn ($q) => $q->select('id')->from($query->select('id')))
            ->groupBy('cuisine_categories.id', 'cuisine_categories.name', 'cuisine_categories.slug')
            ->orderByDesc('restaurants_count')
            ->get()
            ->toArray();
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

    /**
     * The requested price levels: one (`price_range=$$`, the old links) or
     * several (`price_range[]=$&price_range[]=$$`). Unknown values are dropped;
     * the list comes back in PRICE_LEVELS order so equal filters share a cache
     * key. Null when nothing usable was asked for.
     *
     * @return list<string>|null
     */
    private function priceLevels(mixed $value): ?array
    {
        $asked = is_array($value) ? $value : [$value];
        $levels = array_values(array_filter(self::PRICE_LEVELS, fn (string $level): bool => in_array($level, $asked, true)));

        return $levels === [] ? null : $levels;
    }
}
