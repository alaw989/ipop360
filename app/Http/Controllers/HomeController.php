<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use App\Support\StateAbbreviations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $location = $this->geolocationService->resolveLocation($request);
        $city = $location['city'] ?? null;
        $state = $location['state'] ?? null;

        $data = $this->getHomepageData($city, $state);

        return Inertia::render('Welcome', array_merge($data, [
            'location' => $location
                ? ['city' => $city, 'state' => $state]
                : null,
            'fallbackCoords' => $location
                ? ['lat' => $location['lat'], 'lng' => $location['lng']]
                : null,
        ]));
    }

    public function apiData(Request $request): JsonResponse
    {
        $city = $request->query('city');
        $state = $request->query('state');

        $data = $this->getHomepageData($city, $state);
        unset($data['latestPosts']);

        return response()->json($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function getHomepageData(?string $city, ?string $state): array
    {
        // Geolocation sources (IP lookup, GPS reverse-geocode, city-search
        // autocomplete) hand back full state names, but the DB's real
        // convention is the 2-letter abbreviation — normalize once here so
        // every query below matches actual data. Falls back to the original
        // value when unrecognized, so junk input still behaves like a
        // guaranteed non-match rather than becoming `where('state', null)`.
        $state = $state ? (StateAbbreviations::toAbbreviation($state) ?? $state) : $state;

        $categories = $this->getScopedCategories($city, $state);

        $popularRestaurants = collect();
        $popularCuisines = collect();

        $effectiveLocation = null;
        $trendingLimit = (int) config('restaurant-finder.trending.limit', 18);

        if ($city) {
            $popularRestaurants = Restaurant::active()->trendingQualified()
                ->with('cuisines')
                ->where('city', $city)
                ->where('state', $state)
                ->orderByDecayedScore()
                ->limit($trendingLimit)
                ->get();

            if ($popularRestaurants->isNotEmpty()) {
                $effectiveLocation = ['city' => $city, 'state' => $state];
            }
        }

        if ($popularRestaurants->isEmpty()) {
            $popularRestaurants = Restaurant::active()->trendingQualified()
                ->with('cuisines')
                ->orderByDecayedScore()
                ->limit($trendingLimit)
                ->get();
        }

        if ($popularRestaurants->isEmpty()) {
            // The quality floor filtered out everything (thin/early corpus) —
            // never show an empty Trending section when there IS data.
            $popularRestaurants = Restaurant::active()
                ->with('cuisines')
                ->orderByDecayedScore()
                ->limit($trendingLimit)
                ->get();
        }

        $popularIds = $popularRestaurants->pluck('id');

        $popularCuisines = Cuisine::withCount([
            'restaurants' => fn ($q) => $q->whereIn('restaurants.id', $popularIds)->active(),
        ])
            ->orderByDesc('restaurants_count')
            ->limit(12)
            ->get(['id', 'name', 'slug', 'icon'])
            ->filter(fn ($c) => $c->restaurants_count > 0)
            ->values();

        $latestPosts = BlogPost::published()
            ->with('author:id,name')
            ->orderBy('is_featured', 'desc')
            ->latest('published_at')
            ->limit(3)
            ->get(['id', 'title', 'slug', 'excerpt', 'category', 'featured_image', 'published_at', 'author_id', 'is_featured']);

        $stats = [
            'restaurants' => Restaurant::active()->count(),
            'cuisines' => Cuisine::count(),
            'cities' => Restaurant::active()->whereNotNull('city')->distinct()->count('city'),
        ];

        return [
            'categories' => $categories,
            'popularCuisines' => $popularCuisines,
            'popularRestaurants' => $popularRestaurants,
            'latestPosts' => $latestPosts,
            'location' => $effectiveLocation,
            'stats' => $stats,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getScopedCategories(?string $city, ?string $state): array
    {
        $categories = CuisineCategory::with(['cuisines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order');

        if ($city && $state) {
            $categories->whereHas('cuisines.restaurants', fn ($q) => $q
                ->where('restaurants.is_active', true)
                ->where('restaurants.city', $city)
                ->where('restaurants.state', $state));
        }

        $result = $categories->get()->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
            'slug' => $cat->slug,
            'icon' => $cat->icon,
            'cuisines' => $cat->cuisines->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'icon' => $c->icon,
            ]),
        ])->toArray();

        if (empty($result) && $city && $state) {
            return $this->getScopedCategories(null, null);
        }

        return $result;
    }
}
