<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merge a duplicate restaurant row into its keeper.
 *
 * Extracted from the `restaurants:dedupe` command so the continuous
 * data-hygiene pass can reuse the exact same merge logic (social-link
 * repoint/drop, engagement repoint, favorites repoint, cuisine-pivot union,
 * best-of-both field backfill, then delete of the duplicate row).
 */
class RestaurantDeduplicationService
{
    /**
     * Merge $dupeId into $keepId, repointing relations, unioning cuisines,
     * backfilling fields the keeper lacks, then deleting the duplicate row.
     *
     * Dry run ($apply = false) performs the same detection and counting but
     * persists nothing.
     *
     * @return array{rows_deleted: int, links_repointed: int, links_dropped: int, engagement_merged: int, favorites_repointed: int, pivot_unions: int}
     */
    public function mergePair(int $keepId, int $dupeId, bool $apply): array
    {
        $stats = [
            'rows_deleted' => 0,
            'links_repointed' => 0,
            'links_dropped' => 0,
            'engagement_merged' => 0,
            'favorites_repointed' => 0,
            'pivot_unions' => 0,
        ];

        // Social links: repoint a dup-row link unless the kept row already has
        // the same platform (then it's a true duplicate — drop it).
        $dupLinks = RestaurantSocialLink::where('restaurant_id', $dupeId)->get();
        foreach ($dupLinks as $link) {
            $exists = RestaurantSocialLink::where('restaurant_id', $keepId)
                ->where('platform', $link->platform)
                ->exists();

            if ($exists) {
                $stats['links_dropped']++;
                if ($apply) {
                    $link->delete();
                }
            } else {
                $stats['links_repointed']++;
                if ($apply) {
                    $link->update(['restaurant_id' => $keepId]);
                }
            }
        }

        // Engagement: repoint dup-row rows to the kept row. The kept row may
        // already have an identical (restaurant_id, action_type, user_id) row;
        // delete dupes rather than creating a double-count.
        $dupEngagement = DB::table('restaurant_engagement')->where('restaurant_id', $dupeId)->get();
        foreach ($dupEngagement as $row) {
            $exists = DB::table('restaurant_engagement')
                ->where('restaurant_id', $keepId)
                ->where('action_type', $row->action_type)
                ->where('user_id', $row->user_id)
                ->exists();

            if ($exists) {
                if ($apply) {
                    DB::table('restaurant_engagement')->where('id', $row->id)->delete();
                }
            } else {
                $stats['engagement_merged']++;
                if ($apply) {
                    DB::table('restaurant_engagement')->where('id', $row->id)->update(['restaurant_id' => $keepId]);
                }
            }
        }

        // Favorites: repoint any rows referencing the dupe.
        $dupFavorites = DB::table('favorite_restaurant_user')->where('restaurant_id', $dupeId)->get();
        foreach ($dupFavorites as $row) {
            $stats['favorites_repointed']++;
            if ($apply) {
                DB::table('favorite_restaurant_user')
                    ->where('user_id', $row->user_id)
                    ->where('restaurant_id', $dupeId)
                    ->update(['restaurant_id' => $keepId]);
            }
        }

        // Cuisine pivot: union the dupe's cuisines onto the kept row, then clear.
        $dupeCuisines = DB::table('cuisine_restaurant')->where('restaurant_id', $dupeId)->pluck('cuisine_id');
        foreach ($dupeCuisines as $cuisineId) {
            $exists = DB::table('cuisine_restaurant')
                ->where('restaurant_id', $keepId)
                ->where('cuisine_id', $cuisineId)
                ->exists();

            if (! $exists) {
                $stats['pivot_unions']++;
                if ($apply) {
                    DB::table('cuisine_restaurant')->insert([
                        'restaurant_id' => $keepId,
                        'cuisine_id' => $cuisineId,
                    ]);
                }
            }
        }

        if ($apply) {
            // Merge any data the kept row lacks from the dupe (best-of-both)
            // before deletion.
            $keep = Restaurant::find($keepId);
            $dupe = Restaurant::find($dupeId);

            if ($keep !== null && $dupe !== null) {
                $this->backfillMissingFields($keep, $dupe);
            }

            DB::table('cuisine_restaurant')->where('restaurant_id', $dupeId)->delete();
            Restaurant::where('id', $dupeId)->delete();
            $stats['rows_deleted']++;

            // Recompute the kept row's denormalized social counter so repointed
            // links are reflected (the scraping pipeline maintains it, but the
            // dedupe moved rows underneath it).
            $keptRestaurant = Restaurant::find($keepId);
            if ($keptRestaurant !== null) {
                $keptRestaurant->update(['social_links_count' => $keptRestaurant->countScoredSocialLinks()]);
            }
        }

        Log::channel('enrichment')->info($apply ? 'Dedupe merged pair' : 'Dedupe would merge pair', [
            'keep_id' => $keepId,
            'dupe_id' => $dupeId,
            'applied' => $apply,
        ]);

        return $stats;
    }

    /**
     * Copy fields present on the dupe but missing on the kept row (best-of-both
     * merge). Re-computes engagement counters so the merged row reflects both.
     */
    private function backfillMissingFields(Restaurant $keep, Restaurant $dupe): void
    {
        $updates = [];
        foreach ([
            'website_url', 'photo_url', 'phone', 'description', 'price_range',
            'opening_hours', 'menu_url', 'google_place_id', 'yelp_business_id',
            'google_rating', 'google_review_count',
        ] as $field) {
            if (empty($keep->{$field}) && ! empty($dupe->{$field})) {
                $updates[$field] = $dupe->{$field};
            }
        }

        if (! empty($updates)) {
            $keep->update($updates);
        }

        $keep->update([
            'total_engagement' => $keep->total_engagement + $dupe->total_engagement,
            'website_clicks_count' => $keep->website_clicks_count + $dupe->website_clicks_count,
            'call_clicks_count' => $keep->call_clicks_count + $dupe->call_clicks_count,
            'directions_clicks_count' => $keep->directions_clicks_count + $dupe->directions_clicks_count,
            'social_link_clicks_count' => $keep->social_link_clicks_count + $dupe->social_link_clicks_count,
            'menu_click_count' => $keep->menu_click_count + $dupe->menu_click_count,
            'pageviews_count' => $keep->pageviews_count + $dupe->pageviews_count,
        ]);
    }
}
