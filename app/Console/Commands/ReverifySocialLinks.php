<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * spec-109: a link verified once can rot (account deleted/renamed/handle
 * changed) after ScrapeRestaurantSocialLinks/BackfillRestaurantWebsites first
 * verified it. Without periodic re-checking, a dead profile would count
 * toward social_links_count forever. Re-checks previously-verified links
 * (decaying a link to unverified on repeated failure) and gives previously
 * -failed links another chance (a transient failure, or a site that has since
 * fixed a broken link), bounded per run so this never becomes an unbounded
 * outbound-request surface.
 */
class ReverifySocialLinks extends Command
{
    protected $signature = 'restaurants:reverify-social-links {--limit=500 : Max links to re-check per run}';

    protected $description = 'Re-verify previously-checked social profile links, decaying dead ones out of scoring';

    public function handle(RestaurantWebsiteScraperService $scraper): int
    {
        $limit = (int) $this->option('limit');

        $links = RestaurantSocialLink::query()
            ->where(function ($query) {
                $query->whereNotNull('verified_at')->orWhereNotNull('last_check_failed_at');
            })
            ->orderByRaw('COALESCE(verified_at, last_check_failed_at) ASC')
            ->limit($limit)
            ->get();

        if ($links->isEmpty()) {
            $this->info('No social links due for re-verification.');

            return self::SUCCESS;
        }

        $this->info("Re-verifying {$links->count()} social links...");
        $bar = $this->output->createProgressBar($links->count());
        $bar->start();

        $nowVerified = 0;
        $nowFailed = 0;
        $touchedRestaurantIds = [];

        foreach ($links as $link) {
            $verified = $scraper->verifyProfileUrl($link->url);
            $now = now();

            $link->update([
                'verified_at' => $verified ? $now : null,
                'last_check_failed_at' => $verified ? null : $now,
            ]);

            $verified ? $nowVerified++ : $nowFailed++;
            $touchedRestaurantIds[$link->restaurant_id] = true;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        foreach (array_keys($touchedRestaurantIds) as $restaurantId) {
            $restaurant = Restaurant::find($restaurantId);
            if ($restaurant !== null) {
                $restaurant->update(['social_links_count' => $restaurant->countScoredSocialLinks()]);
            }
        }

        $this->info("Done. {$nowVerified} verified, {$nowFailed} failed, ".count($touchedRestaurantIds).' restaurants resynced.');

        Log::channel('enrichment')->info('Social link re-verification completed', [
            'checked' => $links->count(),
            'now_verified' => $nowVerified,
            'now_failed' => $nowFailed,
            'restaurants_resynced' => count($touchedRestaurantIds),
        ]);

        return self::SUCCESS;
    }
}
