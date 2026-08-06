<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merge exact-duplicate restaurant rows.
 *
 * The live-search read path persisted a snapshot copy (persistLiveResults /
 * preview snapshots) on top of enrichment-created rows, so 118 pairs exist
 * with the SAME name + city + coords but different slugs. The earlier row is
 * consistently the richer one (has rating/website/photo). This command:
 *   1. finds exact-duplicate pairs (same name, city, non-null coords),
 *   2. keeps the earlier (richer) row, deletes the later,
 *   3. repoints restaurant_engagement / restaurant_social_links /
 *      favorite_restaurant_user to the kept row (dropping a dup-row social
 *      link when the kept row already has the same platform),
 *   4. unions the cuisine_restaurant pivot.
 *
 * Default is a read-only dry run; pass --apply to persist. Every change is
 * also logged to the enrichment channel for auditability.
 */
class DeduplicateRestaurants extends Command
{
    protected $signature = 'restaurants:dedupe
        {--apply : Persist merges (default is a read-only dry run)}
        {--limit=0 : Max pairs to process (0 = all)}';

    protected $description = 'Merge exact-duplicate restaurant rows (same name/city/coords)';

    private int $pairs = 0;

    private int $rowsDeleted = 0;

    private int $linksRepointed = 0;

    private int $linksDropped = 0;

    private int $engagementMerged = 0;

    private int $favoritesRepointed = 0;

    private int $pivotUnions = 0;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $pairs = $this->findDuplicatePairs($limit);

        if (empty($pairs)) {
            $this->info('No exact duplicate pairs found.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'Merging' : 'Would merge').' '.count($pairs).' duplicate pair(s)...');

        $bar = $this->output->createProgressBar(count($pairs));
        $bar->start();

        foreach ($pairs as $pair) {
            $this->mergePair((int) $pair['keep_id'], (int) $pair['dupe_id'], $apply);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (changes persisted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line("Pairs merged: {$this->pairs}");
        $this->line("Duplicate rows deleted: {$this->rowsDeleted}");
        $this->line("Social links repointed: {$this->linksRepointed}");
        $this->line("Social links dropped (platform collision): {$this->linksDropped}");
        $this->line("Engagement rows repointed: {$this->engagementMerged}");
        $this->line("Favorites repointed: {$this->favoritesRepointed}");
        $this->line("Cuisine pivot unions: {$this->pivotUnions}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{keep_id: int, dupe_id: int, name: string}>
     */
    private function findDuplicatePairs(int $limit): array
    {
        $query = DB::table('restaurants as a')
            ->join('restaurants as b', function ($join) {
                $join->on('a.name', '=', 'b.name')
                    ->on('a.id', '<', 'b.id');
            })
            ->whereNotNull('a.latitude')
            ->whereNotNull('a.longitude')
            ->whereColumn('a.latitude', 'b.latitude')
            ->whereColumn('a.longitude', 'b.longitude')
            ->whereColumn('a.city', 'b.city')
            ->select('a.id as keep_id', 'b.id as dupe_id', 'a.name')
            ->orderBy('a.id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($row) => (array) $row)->all();
    }

    private function mergePair(int $keepId, int $dupeId, bool $apply): void
    {
        // Social links: repoint a dup-row link unless the kept row already has
        // the same platform (then it's a true duplicate — drop it).
        $dupLinks = RestaurantSocialLink::where('restaurant_id', $dupeId)->get();
        foreach ($dupLinks as $link) {
            $exists = RestaurantSocialLink::where('restaurant_id', $keepId)
                ->where('platform', $link->platform)
                ->exists();

            if ($exists) {
                $this->linksDropped++;
                if ($apply) {
                    $link->delete();
                }
            } else {
                $this->linksRepointed++;
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
                $this->engagementMerged++;
                if ($apply) {
                    DB::table('restaurant_engagement')->where('id', $row->id)->update(['restaurant_id' => $keepId]);
                }
            }
        }

        // Favorites: repoint any rows referencing the dupe.
        $dupFavorites = DB::table('favorite_restaurant_user')->where('restaurant_id', $dupeId)->get();
        foreach ($dupFavorites as $row) {
            $this->favoritesRepointed++;
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
                $this->pivotUnions++;
                if ($apply) {
                    DB::table('cuisine_restaurant')->insert([
                        'restaurant_id' => $keepId,
                        'cuisine_id' => $cuisineId,
                    ]);
                }
            }
        }

        $this->pairs++;
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
            $this->rowsDeleted++;

            // Recompute the kept row's denormalized social counter so repointed
            // links are reflected (the scraping pipeline maintains it, but the
            // dedupe moved rows underneath it).
            $socialCount = RestaurantSocialLink::where('restaurant_id', $keepId)->count();
            Restaurant::where('id', $keepId)->update(['social_links_count' => $socialCount]);
        }

        Log::channel('enrichment')->info(($apply ? 'Dedupe merged pair' : 'Dedupe would merge pair'), [
            'keep_id' => $keepId,
            'dupe_id' => $dupeId,
            'applied' => $apply,
        ]);
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
