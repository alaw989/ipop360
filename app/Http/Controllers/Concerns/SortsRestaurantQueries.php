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
    /**
     * Rating sort with credibility bucketing, mirroring the live path's
     * VenuePipeline::sortVenues(): a rating whose review count is below
     * ranking.rating_sort_min_reviews sinks (rating - 10) below all credible
     * ratings. Kill-switched via ranking.rating_sort_credibility so both paths
     * degrade to identical naive behavior when disabled.
     *
     * @param  Builder<Restaurant>  $query
     * @return Builder<Restaurant>
     */
    private function applyRatingSort(Builder $query): Builder
    {
        $minReviews = (int) config('restaurant-finder.ranking.rating_sort_min_reviews', 20);
        $credibility = filter_var(
            config('restaurant-finder.ranking.rating_sort_credibility', true),
            FILTER_VALIDATE_BOOL
        );

        if (! $credibility) {
            return $query
                ->orderByRaw('COALESCE(google_rating, yelp_rating) DESC')
                ->orderByDecayedScore();
        }

        return $query
            ->orderByRaw(
                'CASE WHEN COALESCE(google_review_count, yelp_review_count, 0) < ? '
                .'THEN COALESCE(google_rating, yelp_rating) - 10 '
                .'ELSE COALESCE(google_rating, yelp_rating) END DESC',
                [$minReviews]
            )
            ->orderByDecayedScore();
    }

    /**
     * @param  Builder<Restaurant>  $query
     * @return Builder<Restaurant>
     */
    private function applyRestaurantSort(Builder $query, string $sort, bool $hasCoords): Builder
    {
        return match ($sort) {
            'best_match' => $query->orderByDecayedScore(),
            'nearest' => $hasCoords
                ? $query->orderBy('distance')
                : $query->orderByDecayedScore(),
            'rating' => $this->applyRatingSort($query),
            'reviews' => $query
                ->orderByRaw('COALESCE(google_review_count, yelp_review_count) DESC')
                ->orderByDecayedScore(),
            'price' => $query
                ->orderByRaw(self::PRICE_SORT_EXPRESSION.' ASC')
                ->orderByDecayedScore(),
            default => $query->orderByDecayedScore(),
        };
    }
}
