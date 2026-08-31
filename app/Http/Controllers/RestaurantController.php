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
use App\Services\LiveVenuePersister;
use App\Services\PopularityScoreService;
use App\Services\RestaurantValidationService;
use App\Services\UnifiedSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RestaurantController extends Controller
{
    use SortsRestaurantQueries;

    public function __construct(
        private GeolocationService $geolocationService,
        private RestaurantValidationService $restaurantValidation,
        private LiveVenuePersister $venuePersister,
        private UnifiedSearchService $unifiedSearch,
    ) {}

    /**
     * Build the shared restaurant query with cuisine/category/coords filtering.
     *
     * This query builder is used by both index() and apiIndex() to ensure
     * consistent filtering behavior and avoid drift between the two endpoints.
     *
     * @return Builder<Restaurant>
     */
    private function buildRestaurantQuery(Request $request): Builder
    {
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');

        return Restaurant::query()
            ->with('cuisines')
            ->when(
                $cuisineSlug,
                fn ($query) => $query->whereHas(
                    'cuisines',
                    fn ($q) => $q->where('slug', $cuisineSlug)
                )
            )
            ->when(
                $categorySlug && ! $cuisineSlug,
                fn ($query) => $query->whereHas(
                    'cuisines',
                    fn ($q) => $q->whereHas(
                        'category',
                        fn ($cq) => $cq->where('slug', $categorySlug)
                    )
                )
            );
    }

    /**
     * Apply the selected sort mode to the query.
     *
     * @param  Builder<Restaurant>  $query
     * @return Builder<Restaurant>
     */
    private function applySortMode(Builder $query, string $sort, bool $hasCoords): Builder
    {
        return $this->applyRestaurantSort($query, $sort, $hasCoords);
    }

    /**
     * Persist each live result under preview:{slug} in ExternalApiCache (spec-040).
     *
     * Lets preview() render a venue from a direct slug lookup instead of
     * reconstructing it via a cache-only re-search — which 404'd on category
     * searches (the card carried cuisine but never category), Overpass
     * name-fallback venues, coord drift, and cache expiry. Writes only to
     * external_api_cache (already written on the read path, so the "no
     * restaurants write" constraint stands) and triggers no live fetch (zero
     * quota). TTL-configurable via restaurant-finder.cache.preview_snapshot_days.
     *
     * @param  array<array<string, mixed>>  $results
     */
    private function snapshotLiveResults(array $results): void
    {
        if (empty($results)) {
            return;
        }

        $expiresAt = now()->addDays(
            (int) config('restaurant-finder.cache.preview_snapshot_days', 7)
        );

        foreach ($results as $venue) {
            $slug = $venue['slug'] ?? null;
            if (! empty($slug)) {
                ExternalApiCache::storeByKey("preview:{$slug}", $venue, $expiresAt);
            }
        }
    }

    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price',
            'distance' => 'nullable|numeric|min:1|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');
        $distanceKm = isset($validated['distance']) ? (float) $validated['distance'] * 1.60934 : null;
        $cuisineName = null;

        // A cuisine/category slug is a single string; an array query param
        // (?cuisine[]=x) is malformed and treated as absent.
        $cuisineSlug = is_string($cuisineSlug) ? $cuisineSlug : null;
        $categorySlug = is_string($categorySlug) ? $categorySlug : null;

        // Cuisine takes precedence. For a cuisine scope, derive the parent
        // category slug from the DB (used by the page for navigation). For a
        // category scope ("All <Category>"), resolve its display name. The
        // matching/filtering itself is handled by CuisineMatcher on the live
        // path and by the whereHas() scopes below on the DB path.
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

        // Homepage "popular cities" browse: DB-only, always — checked before
        // geolocation resolution so a city click can never fall through to the
        // live merged-search path even when the visitor's IP would otherwise
        // resolve coordinates (SerpApi quota is capped at 250/month).
        $city = $request->query('city');
        $city = is_string($city) ? $city : null;

        if ($city) {
            $query = $this->buildRestaurantQuery($request)->active()
                ->inCity($city, (string) $request->query('state', ''));
            $query = $this->applySortMode($query, $sort, false);

            return Inertia::render('Restaurants/Index', [
                'restaurants' => $this->formatDbOnlyRestaurants($query->paginate(20)->withQueryString()),
                'filters' => $request->only(['cuisine', 'category', 'city', 'state', 'sort']),
                'cuisineName' => $cuisineName,
                'categorySlug' => $categorySlug,
                'cityName' => $city,
            ]);
        }

        $coords = $this->geolocationService->resolveCoordinates($request);

        // Coordinate fallback for when IP geolocation is unavailable (local dev,
        // VPN, privacy browsers) AND the user has explicitly set a distance filter.
        // Uses DISTANCE_FALLBACK_LAT/LNG from .env; only activates when a distance
        // is requested so normal requests without coordinates still work.
        if ($coords === null && $distanceKm !== null) {
            $fallbackLat = config('restaurant-finder.live_search.distance_fallback_lat');
            $fallbackLng = config('restaurant-finder.live_search.distance_fallback_lng');
            if ($fallbackLat !== null && $fallbackLng !== null) {
                $coords = ['lat' => (float) $fallbackLat, 'lng' => (float) $fallbackLng];
            }
        }

        // Unified merged search: with coords, ALWAYS run the live free-source
        // search, merge the persisted DB rows, and rank the union in one pass
        // (parity with apiIndex and the /search page). Without coords, serve the
        // DB-only Eloquent list below.
        if ($coords !== null) {
            $restaurants = $this->mergedBrowsePaginator(
                $request,
                $coords,
                $cuisineSlug,
                $categorySlug,
                $sort,
                $distanceKm,
            );

            return Inertia::render('Restaurants/Index', [
                'restaurants' => $restaurants,
                'filters' => $request->only(['cuisine', 'category', 'lat', 'lng', 'sort', 'distance']),
                'cuisineName' => $cuisineName,
                'categorySlug' => $categorySlug,
                'cityName' => null,
            ]);
        }

        // DB-only path (no geolocation available).
        // Build the shared query with cuisine/category filtering
        /** @var Builder<Restaurant> $query */
        $query = $this->buildRestaurantQuery($request)->active();

        // Apply sorting based on the selected mode (no coords → nearest falls back).
        $query = $this->applySortMode($query, $sort, false);

        return Inertia::render('Restaurants/Index', [
            'restaurants' => $this->formatDbOnlyRestaurants($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['cuisine', 'category', 'lat', 'lng', 'sort', 'distance']),
            'cuisineName' => $cuisineName,
            'categorySlug' => $categorySlug,
            'cityName' => null,
        ]);
    }

    /**
     * Format a DB-only paginator page with RestaurantResource, sharing one set
     * of popularity-score normalization aggregates across every row (spec-078:
     * avoids an O(n²) per-row recompute). Shared by the plain DB-only browse
     * path and the city-scoped browse path.
     *
     * @param  LengthAwarePaginator<int, Restaurant>  $restaurants
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function formatDbOnlyRestaurants(LengthAwarePaginator $restaurants): LengthAwarePaginator
    {
        $items = $restaurants->getCollection();

        $aggregates = app(PopularityScoreService::class)->computeAggregates($items);

        /** @var AnonymousResourceCollection $formatted */
        $formatted = RestaurantResource::collection($items);
        if ($formatted->collection !== null) {
            $formatted->collection->each(fn ($resource) => $resource
                ->withAllRestaurants($items)
                ->withAggregates($aggregates));
        }

        // A fresh paginator (rather than mutating $restaurants in place) so its
        // generic type genuinely matches the declared array<string, mixed>
        // return — setCollection() on the original object keeps PHPStan's
        // inferred type pinned to the pre-format Restaurant generic.
        return (new LengthAwarePaginator(
            collect($formatted->resolve()),
            $restaurants->total(),
            $restaurants->perPage(),
            $restaurants->currentPage(),
            ['path' => $restaurants->path()]
        ))->withQueryString();
    }

    /**
     * Build an Inertia-ready paginator for the browse page from the unified
     * merged-search union — parity with apiIndex's paginatedUnionResponse, but
     * shaped as a LengthAwarePaginator for Inertia instead of a JSON envelope.
     * Page 1 snapshots the full user-sorted union under browse_page:{...}; pages
     * 2+ slice that snapshot (deterministic, no re-search). Each shown row is
     * also snapshotted under preview:{slug} for the detail page.
     *
     * @param  array{lat: float, lng: float}  $coords
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function mergedBrowsePaginator(
        Request $request,
        array $coords,
        ?string $cuisineSlug,
        ?string $categorySlug,
        string $sort,
        ?float $distanceKm,
    ): LengthAwarePaginator {
        $paginate = filter_var(config('restaurant-finder.live_search.paginate', true), FILTER_VALIDATE_BOOL);
        $perPage = (int) config('restaurant-finder.live_search.page_size', 20);
        $page = max(1, (int) $request->query('page', '1'));

        $pageKey = 'browse_page:'.md5(serialize([
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'cuisine' => $cuisineSlug,
            'category' => $categorySlug,
            'sort' => $sort,
            'distance' => $distanceKm,
        ]));

        if ($paginate && $page > 1) {
            // Pages 2+: serve from the page-1 snapshot. If it expired mid-
            // pagination, return an empty page rather than re-burning the search.
            $snapshotted = ExternalApiCache::findByKey($pageKey);
            $results = is_array($snapshotted) ? $snapshotted : [];
        } else {
            $results = $this->unifiedSearch->search(
                $coords['lat'],
                $coords['lng'],
                $cuisineSlug,
                $categorySlug,
                $sort,
                $distanceKm,
            );

            if ($paginate) {
                ExternalApiCache::storeByKey(
                    $pageKey,
                    $results,
                    now()->addMinutes((int) config('restaurant-finder.live_search.page_snapshot_minutes', 10))
                );
            }
        }

        $total = count($results);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $effectivePage = $paginate ? min($page, $lastPage) : 1;
        $slice = $paginate
            ? array_slice($results, ($effectivePage - 1) * $perPage, $perPage)
            : $results;

        $this->snapshotLiveResults($slice);

        $data = LiveRestaurantResource::collection($slice)->resolve();

        return new LengthAwarePaginator(
            collect($data),
            $total,
            $paginate ? $perPage : $total,
            $effectivePage,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    public function show(Restaurant $restaurant): InertiaResponse
    {
        // Route-model binding resolves by slug alone with no active-scope check —
        // a quarantined (is_active=false) row would otherwise still render publicly.
        abort_if(! $restaurant->is_active, 404);

        $restaurant->load(['cuisines.category', 'socialLinks']);

        $collection = collect([$restaurant]);

        // Format using RestaurantResource (single item)
        $resource = (new RestaurantResource($restaurant))
            ->withAllRestaurants($collection);

        $categorySlug = $restaurant->cuisines->first()?->category?->slug;

        return Inertia::render('Restaurants/Show', [
            'restaurant' => $resource->resolve(),
            'categorySlug' => $categorySlug,
        ]);
    }

    /**
     * Detail page for a LIVE-search result (spec-040). Renders the venue from the
     * per-slug snapshot written by apiIndex() (preview:{slug} in
     * ExternalApiCache) — a direct lookup, NOT a cache-only re-search. This is
     * quota-free and robust: it no longer depends on reproducing the original
     * search's coords/scope (which 404'd category searches, Overpass name-fallback
     * venues, and any coord drift). The URL is just /restaurants/preview/{slug}
     * (old lat/lng/cuisine query params are harmlessly ignored for back-compat).
     * 404s once the snapshot TTL expires (findByKey honors expires_at).
     */
    public function preview(string $slug): InertiaResponse
    {
        $restaurant = ExternalApiCache::findByKey("preview:{$slug}");

        if ($restaurant === null) {
            abort(404, 'This restaurant preview is no longer available.');
        }

        // Snapshots written by older code can carry a synthetic negative id
        // (crc32 of the venue) with no row in the restaurants table. Every
        // engagement event from this page would then 422 and vanish. Upsert the
        // venue now to mint a real DB id and refresh the snapshot so the page
        // renders a persisted row. (spec-104 engagement audit)
        $snapshotId = $restaurant['id'] ?? null;
        if (! is_int($snapshotId) || $snapshotId <= 0) {
            $result = $this->venuePersister->persist($restaurant);
            $restaurant = $result['venue'];

            ExternalApiCache::storeByKey(
                "preview:{$slug}",
                $restaurant,
                now()->addDays((int) config('restaurant-finder.cache.preview_snapshot_days', 7))
            );
        }

        return Inertia::render('Restaurants/Show', [
            'restaurant' => (new LiveRestaurantResource($restaurant))->resolve(),
            'categorySlug' => null,
            'isLivePreview' => true,
            'canonicalUrl' => route('restaurants.preview', ['slug' => $slug]),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sort' => 'nullable|in:best_match,nearest,rating,reviews,price',
            'distance' => 'nullable|numeric|min:1|max:500',
        ]);

        $sort = $validated['sort'] ?? 'best_match';
        $cuisineSlug = $request->query('cuisine');
        $categorySlug = $request->query('category');
        $distanceKm = isset($validated['distance']) ? (float) $validated['distance'] * 1.60934 : null;

        $coords = $this->geolocationService->resolveCoordinates($request);

        // Coordinate fallback for when IP geolocation is unavailable.
        if ($coords === null && $distanceKm !== null) {
            $fallbackLat = config('restaurant-finder.live_search.distance_fallback_lat');
            $fallbackLng = config('restaurant-finder.live_search.distance_fallback_lng');
            if ($fallbackLat !== null && $fallbackLng !== null) {
                $coords = ['lat' => (float) $fallbackLat, 'lng' => (float) $fallbackLng];
            }
        }

        // Unified merged search: ALWAYS run the live free-source search, merge
        // the persisted DB rows into it, and rank the union by popularity score
        // in one pass (UnifiedSearchService). Needs coords to run the live geo
        // search; without them fall through to the DB-only list (parity with
        // index(), which serves persisted rows when the client has no location).
        if ($coords !== null) {
            $paginate = filter_var(config('restaurant-finder.live_search.paginate', true), FILTER_VALIDATE_BOOL);
            $page = max(1, (int) $request->query('page', '1'));

            // Pages 2+ (when paginating) slice the page-1 snapshot — the merged
            // search must NOT re-run here (it would re-burn the live sources and
            // re-rank a fresh union, breaking deterministic pagination).
            $union = [];
            if (! $paginate || $page <= 1) {
                $union = $this->unifiedSearch->search(
                    $coords['lat'],
                    $coords['lng'],
                    $cuisineSlug,
                    $categorySlug,
                    $sort,
                    $distanceKm,
                );
            }

            return $this->paginatedUnionResponse($request, $union, $coords, $cuisineSlug, $categorySlug, $sort);
        }

        // DB-only path (no geolocation available).
        $query = $this->buildRestaurantQuery($request)->active();

        // Apply sorting based on the selected mode (no coords → nearest falls back).
        $query = $this->applySortMode($query, $sort, false);

        $restaurants = $query->paginate(20)->withQueryString();

        $items = $restaurants->getCollection();
        $allItems = $items; // Keep for score_breakdown fallback

        // spec-078: compute normalization aggregates ONCE over the displayed set
        // and share across every resource (avoids the O(n²) per-row recompute).
        $aggregates = app(PopularityScoreService::class)->computeAggregates($allItems);

        // Format using RestaurantResource (collection)
        /** @var AnonymousResourceCollection $formatted */
        $formatted = RestaurantResource::collection($items);
        // Attach the full collection + precomputed aggregates to each resource
        if ($formatted->collection !== null) {
            $formatted->collection->each(fn ($resource) => $resource
                ->withAllRestaurants($allItems)
                ->withAggregates($aggregates));
        }

        return response()->json([
            'data' => $formatted->resolve(),
            'current_page' => $restaurants->currentPage(),
            'last_page' => $restaurants->lastPage(),
            'per_page' => $restaurants->perPage(),
            'total' => $restaurants->total(),
            'next_page_url' => $restaurants->nextPageUrl(),
            'prev_page_url' => $restaurants->previousPageUrl(),
            'from' => $restaurants->firstItem(),
            'to' => $restaurants->lastItem(),
        ]);
    }

    /**
     * Paginate a unified merged-search union (the full DB + live result set from
     * UnifiedSearchService) and shape the JSON envelope. Page 1 snapshots the
     * full user-sorted union under union_page:{coords+cuisine+category+sort};
     * pages 2+ slice that snapshot (deterministic, no re-search) — same contract
     * as spec-068, generalized to the merged union. Each shown row is also
     * snapshotted under preview:{slug} for the detail page.
     *
     * @param  array<int, array<string, mixed>>  $union
     * @param  array{lat: float, lng: float}  $coords
     */
    private function paginatedUnionResponse(
        Request $request,
        array $union,
        array $coords,
        ?string $cuisineSlug,
        ?string $categorySlug,
        string $sort,
    ): JsonResponse {
        $paginate = filter_var(config('restaurant-finder.live_search.paginate', true), FILTER_VALIDATE_BOOL);
        $perPage = (int) config('restaurant-finder.live_search.page_size', 20);
        $page = max(1, (int) $request->query('page', '1'));

        $pageKey = 'union_page:'.md5(serialize([
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'cuisine' => $cuisineSlug,
            'category' => $categorySlug,
            'sort' => $sort,
        ]));

        if ($paginate && $page > 1) {
            // Pages 2+: serve from the page-1 snapshot. If it expired mid-
            // pagination, return an empty page (the frontend surfaces its
            // "couldn't load more" state) rather than re-burning the search.
            $snapshotted = ExternalApiCache::findByKey($pageKey);
            $results = is_array($snapshotted) ? $snapshotted : [];
        } else {
            $results = $union;

            if ($paginate) {
                ExternalApiCache::storeByKey(
                    $pageKey,
                    $results,
                    now()->addMinutes((int) config('restaurant-finder.live_search.page_snapshot_minutes', 10))
                );
            }
        }

        $total = count($results);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $effectivePage = $paginate ? min($page, $lastPage) : 1;
        $slice = $paginate
            ? array_slice($results, ($effectivePage - 1) * $perPage, $perPage)
            : $results;

        // Snapshot each SHOWN result under preview:{slug} so the detail page
        // (/restaurants/preview/{slug}) can render it without re-running the
        // live search (zero quota, no restaurants write). See spec-040.
        $this->snapshotLiveResults($slice);

        $hasNext = $paginate && $effectivePage < $lastPage;

        return response()->json([
            'data' => LiveRestaurantResource::collection($slice)->resolve(),
            'current_page' => $effectivePage,
            'last_page' => $lastPage,
            'per_page' => $paginate ? $perPage : $total,
            'total' => $total,
            'next_page_url' => $hasNext ? $request->fullUrlWithQuery(['page' => $effectivePage + 1]) : null,
            'prev_page_url' => null,
            'from' => $total ? ($effectivePage - 1) * $perPage + 1 : null,
            'to' => $total ? min($effectivePage * $perPage, $total) : null,
            'is_live' => true,
        ]);
    }

    public function leaderboard(Request $request): InertiaResponse
    {
        $coords = $this->geolocationService->resolveCoordinates($request);

        $query = Restaurant::active()
            ->with('cuisines')
            ->when(
                $coords !== null,
                function ($query) use ($coords) {
                    assert($coords !== null);

                    return $query->nearby($coords['lat'], $coords['lng']);
                }
            )
            ->orderByDecayedScore()
            ->orderBy('id', 'asc');

        $restaurants = $query->paginate(50)->withQueryString();

        $items = $restaurants->getCollection();
        $aggregates = app(PopularityScoreService::class)->computeAggregates($items);

        $formatted = RestaurantResource::collection($items);
        if ($formatted->collection !== null) {
            $formatted->collection->each(fn ($resource) => $resource
                ->withAllRestaurants($items)
                ->withAggregates($aggregates));
        }

        $restaurants->setCollection(collect($formatted->resolve()));

        return Inertia::render('Leaderboard/Index', [
            'restaurants' => $restaurants,
            'filters' => $request->only(['lat', 'lng']),
        ]);
    }

    public function compare(Request $request): InertiaResponse
    {
        $ids = $request->query('ids', '');
        $idList = collect(explode(',', $ids))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->values()
            ->toArray();

        if (empty($idList)) {
            return Inertia::render('Compare/Index', [
                'restaurants' => [],
            ]);
        }

        $restaurants = Restaurant::active()
            ->with('cuisines')
            ->whereIn('id', $idList)
            ->get();

        $aggregates = app(PopularityScoreService::class)->computeAggregates($restaurants);

        $formatted = RestaurantResource::collection($restaurants);
        if ($formatted->collection !== null) {
            $formatted->collection->each(fn ($resource) => $resource
                ->withAllRestaurants($restaurants)
                ->withAggregates($aggregates));
        }

        return Inertia::render('Compare/Index', [
            'restaurants' => $formatted->resolve(),
        ]);
    }
}
