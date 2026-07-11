<?php

namespace App\Http\Controllers;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Services\GeolocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __construct(
        private GeolocationService $geolocationService,
    ) {}

    public function __invoke(Request $request)
    {
        $categories = CuisineCategory::with(['cuisines' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($cat) => [
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
            ]);

        $location = $this->geolocationService->resolveLocation($request);
        $fallbackCoords = $location
            ? ['lat' => $location['lat'], 'lng' => $location['lng']]
            : null;

        $city = $location['city'] ?? null;
        $state = $location['state'] ?? null;

        $popularRestaurants = collect();
        $popularCuisines = collect();

        if ($fallbackCoords) {
            $nearbyRestaurants = Restaurant::active()
                ->with('cuisines')
                ->nearby($fallbackCoords['lat'], $fallbackCoords['lng'])
                ->orderByDesc('popularity_score')
                ->limit(18)
                ->get();

            if ($nearbyRestaurants->isNotEmpty()) {
                $popularRestaurants = $nearbyRestaurants;

                $nearbyIds = $nearbyRestaurants->pluck('id');

                $popularCuisines = Cuisine::withCount([
                    'restaurants' => fn ($q) => $q->whereIn('restaurants.id', $nearbyIds)->active(),
                ])
                    ->orderByDesc('restaurants_count')
                    ->limit(12)
                    ->get(['id', 'name', 'slug', 'icon'])
                    ->filter(fn ($c) => $c->restaurants_count > 0)
                    ->values();
            }
        }

        if ($popularRestaurants->isEmpty()) {
            $popularRestaurants = Restaurant::active()
                ->with('cuisines')
                ->orderByDesc('popularity_score')
                ->limit(18)
                ->get();

            $popularCuisines = Cuisine::withCount(['restaurants' => fn ($q) => $q->active()])
                ->orderByDesc('restaurants_count')
                ->limit(12)
                ->get(['id', 'name', 'slug', 'icon'])
                ->filter(fn ($c) => $c->restaurants_count > 0)
                ->values();

            $location = null;
        }

        return Inertia::render('Welcome', [
            'categories' => $categories,
            'popularCuisines' => $popularCuisines,
            'popularRestaurants' => $popularRestaurants,
            'location' => $location
                ? ['city' => $city, 'state' => $state]
                : null,
            'fallbackCoords' => $fallbackCoords,
        ]);
    }
}
