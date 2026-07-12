<?php

namespace App\Http\Controllers;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
    ) {}

    public function __invoke(Request $request)
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

        return response()->json($this->getHomepageData($city, $state));
    }

    private function getHomepageData(?string $city, ?string $state): array
    {
        $categories = $this->getScopedCategories($city, $state);

        $popularRestaurants = collect();
        $popularCuisines = collect();

        $effectiveLocation = null;

        if ($city) {
            $popularRestaurants = Restaurant::active()
                ->with('cuisines')
                ->where('city', $city)
                ->where('state', $state)
                ->orderByDesc('popularity_score')
                ->limit(18)
                ->get();

            if ($popularRestaurants->isNotEmpty()) {
                $effectiveLocation = ['city' => $city, 'state' => $state];
            }
        }

        if ($popularRestaurants->isEmpty() && $state) {
            $popularRestaurants = Restaurant::active()
                ->with('cuisines')
                ->where('state', $state)
                ->orderByDesc('popularity_score')
                ->limit(18)
                ->get();

            if ($popularRestaurants->isNotEmpty()) {
                $effectiveLocation = ['city' => null, 'state' => $state];
            }
        }

        if ($popularRestaurants->isEmpty()) {
            $popularRestaurants = Restaurant::active()
                ->with('cuisines')
                ->orderByDesc('popularity_score')
                ->limit(18)
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

        return [
            'categories' => $categories,
            'popularCuisines' => $popularCuisines,
            'popularRestaurants' => $popularRestaurants,
            'location' => $effectiveLocation,
        ];
    }

    private function getScopedCategories(?string $city, ?string $state): array
    {
        $categories = CuisineCategory::with(['cuisines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order');

        if ($city && $state) {
            $categories->whereHas('cuisines.restaurants', fn ($q) => $q->active()
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
