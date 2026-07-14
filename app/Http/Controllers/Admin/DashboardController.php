<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        // SerpApi quota
        $stats = ExternalApiCache::stats();
        $freeQuota = (int) config('restaurant-finder.serpapi.free_quota', 250);
        $circuitBreakerThreshold = (int) ceil($freeQuota * (float) config('restaurant-finder.serpapi.circuit_breaker_fraction', 0.8));
        $enrichBudget = (int) config('restaurant-finder.enrich.monthly_budget', 150);
        $serpapiCalls = $stats['serpapi_calls_last_30d'];

        $serpapiQuota = [
            'calls_used' => $serpapiCalls,
            'free_quota' => $freeQuota,
            'remaining' => max(0, $freeQuota - $serpapiCalls),
            'pct_used' => $freeQuota > 0 ? round(($serpapiCalls / $freeQuota) * 100) : 0,
            'circuit_breaker_threshold' => $circuitBreakerThreshold,
            'circuit_breaker_tripped' => $serpapiCalls >= $circuitBreakerThreshold,
            'enrich_budget' => $enrichBudget,
            'enrich_budget_exhausted' => $serpapiCalls >= $enrichBudget,
        ];

        // Scrape recency
        $lastSocialScrape = DB::table('restaurant_social_links')->max('updated_at');
        $socialLinksCount = DB::table('restaurant_social_links')->count();

        $scrapeHealth = [
            'last_social_scrape' => $lastSocialScrape,
            'hours_since_social_scrape' => $lastSocialScrape ? now()->diffInHours($lastSocialScrape) : null,
            'total_social_links' => $socialLinksCount,
        ];

        // Data quality
        $total = Restaurant::count();
        $withWebsite = Restaurant::whereNotNull('website_url')->where('website_url', '!=', '')->count();
        $withSocial = Restaurant::where('social_links_count', '>', 0)->count();
        $withOpeningHours = Restaurant::whereNotNull('opening_hours')->count();
        $withPhoto = Restaurant::whereNotNull('photo_url')->count();

        // Restaurants missing the most data (at least one gap, sorted by gaps, most gaps first)
        $missingData = DB::table('restaurants')
            ->select([
                'id', 'name', 'slug',
                DB::raw("CASE WHEN website_url IS NULL OR website_url = '' THEN 1 ELSE 0 END as missing_website"),
                DB::raw("CASE WHEN social_links_count = 0 THEN 1 ELSE 0 END as missing_social"),
                DB::raw("CASE WHEN opening_hours IS NULL THEN 1 ELSE 0 END as missing_hours"),
                DB::raw("CASE WHEN photo_url IS NULL THEN 1 ELSE 0 END as missing_photo"),
            ])
            ->havingRaw('missing_website + missing_social + missing_hours + missing_photo > 0')
            ->orderByRaw('missing_website + missing_social + missing_hours + missing_photo DESC')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'slug' => (string) $r->slug,
                'gaps' => collect([
                    (int) $r->missing_website ? 'website' : null,
                    (int) $r->missing_social ? 'social' : null,
                    (int) $r->missing_hours ? 'hours' : null,
                    (int) $r->missing_photo ? 'photo' : null,
                ])->filter()->values(),
                'gap_count' => (int) $r->missing_website + (int) $r->missing_social + (int) $r->missing_hours + (int) $r->missing_photo,
            ]);

        $dataQuality = [
            'total_restaurants' => $total,
            'with_website' => $withWebsite,
            'with_website_pct' => $total > 0 ? round(($withWebsite / $total) * 100) : 0,
            'with_social_links' => $withSocial,
            'with_social_links_pct' => $total > 0 ? round(($withSocial / $total) * 100) : 0,
            'with_opening_hours' => $withOpeningHours,
            'with_opening_hours_pct' => $total > 0 ? round(($withOpeningHours / $total) * 100) : 0,
            'with_photo' => $withPhoto,
            'with_photo_pct' => $total > 0 ? round(($withPhoto / $total) * 100) : 0,
            'missing_data' => $missingData,
        ];

        return Inertia::render('Admin/Dashboard', [
            'serpapiQuota' => $serpapiQuota,
            'scrapeHealth' => $scrapeHealth,
            'dataQuality' => $dataQuality,
        ]);
    }
}
