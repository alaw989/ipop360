<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\FeaturedRestaurant;
use App\Models\Restaurant;
use App\Support\StateAbbreviations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class HomeService
{
    /**
     * Trending dedup happens in PHP after the DB query (see dedupeByName()),
     * so we over-fetch candidates before truncating to the real limit —
     * otherwise a chain with several highly-ranked locations could shrink
     * the final list below $trendingLimit once duplicate names collapse.
     */
    private const DEDUP_CANDIDATE_MULTIPLIER = 5;

    /**
     * @return array<string, mixed>
     */
    public function getHomepageData(?string $city, ?string $state): array
    {
        // Geolocation sources (IP lookup, GPS reverse-geocode, city-search
        // autocomplete) hand back full state names, but the DB's real
        // convention is the 2-letter abbreviation — normalize once here so
        // every query below matches actual data. Falls back to the original
        // value when unrecognized, so junk input still behaves like a
        // guaranteed non-match rather than becoming `where('state', null)`.
        $state = $state ? (StateAbbreviations::toAbbreviation($state) ?? $state) : $state;

        $categories = $this->getScopedCategories($city, $state);

        $trendingLimit = (int) config('restaurant-finder.trending.limit', 18);
        $effectiveLocation = null;
        $popularRestaurants = collect();

        $candidateLimit = $trendingLimit * self::DEDUP_CANDIDATE_MULTIPLIER;

        if ($city) {
            $popularRestaurants = $this->dedupeByName(
                $this->trendingRestaurantsQuery(qualified: true)
                    ->where('city', $city)
                    ->where('state', $state)
                    ->limit($candidateLimit)
                    ->get(),
                $trendingLimit
            );

            if ($popularRestaurants->isNotEmpty()) {
                $effectiveLocation = ['city' => $city, 'state' => $state];
            }
        }

        if ($popularRestaurants->isEmpty()) {
            $popularRestaurants = $this->dedupeByName(
                $this->trendingRestaurantsQuery(qualified: true)
                    ->limit($candidateLimit)
                    ->get(),
                $trendingLimit
            );
        }

        if ($popularRestaurants->isEmpty()) {
            // The quality floor filtered out everything (thin/early corpus) —
            // never show an empty Trending section when there IS data.
            $popularRestaurants = $this->dedupeByName(
                $this->trendingRestaurantsQuery(qualified: false)
                    ->limit($candidateLimit)
                    ->get(),
                $trendingLimit
            );
        }

        $popularCuisines = $this->getPopularCuisines();

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
            'popularCities' => config('restaurant-finder.homepage.popular_cities', []),
            'popularRestaurants' => $popularRestaurants,
            'featuredRestaurant' => $this->featuredRestaurant($popularRestaurants),
            'latestPosts' => $latestPosts,
            'location' => $effectiveLocation,
            'stats' => $stats,
        ];
    }

    /**
     * The home page spotlight: the admin's current pick (with its story, if
     * one is linked) while its restaurant is active; otherwise the top-ranked
     * restaurant in the list the visitor already sees (their city's, when
     * they picked one) that has a photo and a rating. Null when neither
     * exists.
     *
     * @param  Collection<int, Restaurant>  $popularRestaurants
     * @return array<string, mixed>|null
     */
    public function featuredRestaurant(Collection $popularRestaurants): ?array
    {
        $pick = FeaturedRestaurant::current()
            ->with(['restaurant.cuisines', 'blogPost'])
            ->whereHas('restaurant', fn (Builder $q) => $q->where('is_active', true))
            ->first();

        if ($pick !== null) {
            $story = $pick->blogPost !== null && $pick->blogPost->status === 'published' ? $pick->blogPost : null;

            return $this->spotlight($pick->restaurant, $story, picked: true, image: $pick->image_url, credit: $pick->image_credit);
        }

        $top = $popularRestaurants->first(
            fn (Restaurant $r): bool => ! empty($r->photo_url) && (float) $r->google_rating > 0.0
        );

        return $top !== null ? $this->spotlight($top, null, picked: false) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function spotlight(Restaurant $restaurant, ?BlogPost $story, bool $picked, ?string $image = null, ?string $credit = null): array
    {
        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'city' => $restaurant->city,
            'state' => $restaurant->state,
            'price_range' => $restaurant->price_range,
            'google_rating' => $restaurant->google_rating,
            'google_review_count' => $restaurant->google_review_count,
            'cuisines' => $restaurant->cuisines->map(fn (Cuisine $c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->values(),
            // A photo picked for the spotlight (with its credit) leads, then the
            // story's image, then the restaurant's own.
            'image' => $image ?: ($story?->featured_image ?: $restaurant->photo_url),
            'image_credit' => $image ? $credit : null,
            'quote' => $story?->excerpt ?: $restaurant->description,
            'story' => $story !== null ? ['title' => $story->title, 'slug' => $story->slug] : null,
            'picked' => $picked,
        ];
    }

    /**
     * @return Builder<Restaurant>
     */
    private function trendingRestaurantsQuery(bool $qualified): Builder
    {
        $query = Restaurant::active()->with('cuisines');

        if ($qualified) {
            $query->trendingQualified();
        }

        return $query->orderByTrendingScore();
    }

    /**
     * Caps Trending to one card per brand/name — the candidates arrive
     * pre-ordered by trending score, so unique() keeps the highest-scored
     * occurrence of each name and drops the rest. Normalization matches
     * VenuePipeline::venuesMatch()'s strtolower(trim($name)) convention.
     *
     * @param  Collection<int, Restaurant>  $restaurants
     * @return Collection<int, Restaurant>
     */
    private function dedupeByName(Collection $restaurants, int $limit): Collection
    {
        return $restaurants
            ->unique(fn (Restaurant $r) => strtolower(trim($r->name)))
            ->take($limit)
            ->values();
    }

    /**
     * Popular cuisines are always global (not scoped to the trending top-18
     * or the request city): a frequency count across every active
     * restaurant, so the section reflects the whole corpus, not a thin
     * city snapshot. Cached because it's an uncached COUNT over the whole
     * `restaurants` table otherwise, on a hot/unthrottled path.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getPopularCuisines(): array
    {
        $ttl = (int) config('restaurant-finder.homepage.popular_cuisines_cache_ttl_minutes', 30);

        // Cache a plain array, not the Eloquent Collection/models themselves:
        // config('cache.serializable_classes') is false (Laravel's default
        // gadget-chain hardening), so any object unserialized back out of the
        // cache silently degrades to __PHP_Incomplete_Class — caching model
        // instances here 500'd every homepage request once the value was
        // actually read back from cache.
        return Cache::remember('home:popular-cuisines', now()->addMinutes($ttl), function () {
            return Cuisine::withCount([
                'restaurants' => fn ($q) => $q->active(),
            ])
                ->orderByDesc('restaurants_count')
                ->limit(12)
                ->get(['id', 'name', 'slug', 'icon'])
                ->filter(fn ($c) => $c->restaurants_count > 0)
                ->values()
                ->map(fn (Cuisine $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'icon' => $c->icon,
                    'restaurants_count' => $c->restaurants_count,
                ])
                ->toArray();
        });
    }

    /**
     * The unscoped category list (every category and cuisine), cached for an
     * hour: the header search loads it on every page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allCategories(): array
    {
        return Cache::remember('search:all-categories', now()->addHour(), fn (): array => $this->getScopedCategories(null, null));
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
