<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Support\Facades\DB;

/**
 * The one write path for scraped social links (restaurants:scrape-social and
 * the website backfill both call it): replace the row's links, reachability-
 * check each, classify shared corporate accounts as brand-scoped, and recount
 * social_links_count for every restaurant whose scored count changed.
 *
 * Brand scoping: a profile URL attached to >= data_integrity.
 * social_brand_min_restaurants distinct restaurants (every Domino's location
 * linking @dominos) is the chain's account, not the venue's. It is kept (it is
 * still a correct link to show) but never scored, so chains stop outranking
 * independents on corporate marketing.
 */
class SocialLinkRecorder
{
    public function __construct(private RestaurantWebsiteScraperService $scraper) {}

    /**
     * @param  array<string, string>  $links  platform => canonical profile URL
     * @return list<string> per-platform labels for logging (":unverified"/":brand" suffixed)
     */
    public function record(Restaurant $restaurant, array $links): array
    {
        $now = now();
        $rows = [];
        foreach ($links as $platform => $url) {
            $verified = $this->scraper->verifyProfileUrl($url);
            $rows[] = [
                'platform' => $platform,
                'url' => $url,
                'scope' => RestaurantSocialLink::SCOPE_LOCATION,
                'verified_at' => $verified ? $now : null,
                'last_check_failed_at' => $verified ? null : $now,
            ];
        }

        DB::transaction(function () use ($restaurant, $rows): void {
            $restaurant->socialLinks()->delete();
            foreach ($rows as $row) {
                $restaurant->socialLinks()->create($row);
            }
        });

        $this->classifyBrandScope(array_values($links));
        $restaurant->update(['social_links_count' => $restaurant->countScoredSocialLinks()]);

        $brandUrls = RestaurantSocialLink::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('scope', RestaurantSocialLink::SCOPE_BRAND)
            ->pluck('url')
            ->all();

        return array_map(
            fn (array $row) => $row['platform']
                .($row['verified_at'] === null ? ':unverified' : '')
                .(in_array($row['url'], $brandUrls, true) ? ':brand' : ''),
            $rows
        );
    }

    /**
     * Brand-scope every row carrying one of these URLs once the URL is shared
     * by enough distinct restaurants, then recount the affected restaurants.
     * Returns how many restaurants were recounted.
     *
     * @param  array<int, string>  $urls
     */
    public function classifyBrandScope(array $urls): int
    {
        if ($urls === []) {
            return 0;
        }

        $threshold = max(2, (int) config('restaurant-finder.data_integrity.social_brand_min_restaurants', 5));

        $shared = RestaurantSocialLink::query()
            ->whereIn('url', $urls)
            ->where('scope', RestaurantSocialLink::SCOPE_LOCATION)
            ->groupBy('url')
            ->havingRaw('COUNT(DISTINCT restaurant_id) >= ?', [$threshold])
            ->pluck('url')
            ->all();

        if ($shared === []) {
            return 0;
        }

        $restaurantIds = RestaurantSocialLink::query()
            ->whereIn('url', $shared)
            ->distinct()
            ->pluck('restaurant_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        RestaurantSocialLink::query()->whereIn('url', $shared)->update(['scope' => RestaurantSocialLink::SCOPE_BRAND]);

        return $this->recount($restaurantIds);
    }

    /**
     * Re-derive social_links_count for the given restaurants.
     *
     * @param  array<int, int>  $restaurantIds
     */
    public function recount(array $restaurantIds): int
    {
        $count = 0;
        Restaurant::query()->whereIn('id', $restaurantIds)->chunkById(500, function ($restaurants) use (&$count): void {
            foreach ($restaurants as $restaurant) {
                $scored = $restaurant->countScoredSocialLinks();
                if ($scored !== (int) $restaurant->social_links_count) {
                    $restaurant->update(['social_links_count' => $scored]);
                }
                $count++;
            }
        });

        return $count;
    }
}
