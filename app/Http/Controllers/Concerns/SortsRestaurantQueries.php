<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared restaurant-query sort arms so the persisted-db and live-search
 * controllers don't drift (spec-104 audit: the price CASE was duplicated
 * verbatim across RestaurantController and SearchController).
 *
 * Controllers that expose extra sort modes (e.g. social_presence,
 * website_traffic) handle those BEFORE delegating to applyRestaurantSort.
 */
trait SortsRestaurantQueries
{
    /**
     * Price range -> numeric sort key. Single-char ranges are 1, doubled
     * ($$, €€, ...) are 2, etc. Unrecognised but currency-shaped ranges land
     * at 2 so mixed/unknown formats sort near mid-price rather than vanishing.
     */
    private const PRICE_SORT_EXPRESSION = <<<'SQL'
        CASE
            WHEN price_range IS NULL THEN 999
            WHEN price_range = '$' THEN 1
            WHEN price_range = '$$' THEN 2
            WHEN price_range = '$$$' THEN 3
            WHEN price_range = '$$$$' THEN 4
            WHEN price_range = '€' THEN 1
            WHEN price_range = '€€' THEN 2
            WHEN price_range = '€€€' THEN 3
            WHEN price_range = '€€€€' THEN 4
            WHEN price_range = '£' THEN 1
            WHEN price_range = '££' THEN 2
            WHEN price_range = '£££' THEN 3
            WHEN price_range = '££££' THEN 4
            WHEN price_range = '¥' THEN 1
            WHEN price_range = '¥¥' THEN 2
            WHEN price_range = '¥¥¥' THEN 3
            WHEN price_range = '¥¥¥¥' THEN 4
            WHEN price_range = '₩' THEN 1
            WHEN price_range = '₩₩' THEN 2
            WHEN price_range = '₩₩₩' THEN 3
            WHEN price_range = '₩₩₩₩' THEN 4
            WHEN price_range LIKE '$%' OR price_range LIKE '€%' OR price_range LIKE '£%' OR price_range LIKE '¥%' OR price_range LIKE '₩%' THEN 2
            ELSE 2
        END
    SQL;

    /**
     * Apply the sort modes shared by both restaurant-facing endpoints.
     */
    private function applyRestaurantSort(Builder $query, string $sort, bool $hasCoords): Builder
    {
        $decayedScore = Restaurant::decayedPopularityScoreExpression();

        return match ($sort) {
            'best_match' => $query->orderByRaw("{$decayedScore} DESC"),
            'nearest' => $hasCoords
                ? $query->orderBy('distance')
                : $query->orderByRaw("{$decayedScore} DESC"),
            'rating' => $query
                ->orderByRaw('COALESCE(google_rating, yelp_rating) DESC')
                ->orderByRaw("{$decayedScore} DESC"),
            'reviews' => $query
                ->orderByRaw('COALESCE(google_review_count, yelp_review_count) DESC')
                ->orderByRaw("{$decayedScore} DESC"),
            'price' => $query
                ->orderByRaw(self::PRICE_SORT_EXPRESSION.' ASC')
                ->orderByRaw("{$decayedScore} DESC"),
            default => $query->orderByRaw("{$decayedScore} DESC"),
        };
    }
}
