<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule daily re-scoring of all restaurants (runs at 2 AM UTC)
Schedule::command('restaurants:score')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Recompute popularity scores for all restaurants');

// Schedule nightly API cache garbage collection (runs at 3 AM UTC)
Schedule::command('apicache:gc')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Garbage collect expired API cache entries');

// Schedule uptime canary (runs every 15 minutes)
Schedule::command('uptime:canary')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Check application health and uptime');

// Schedule throttled DB enrichment (runs daily at 4 AM UTC)
// Uses --throttled flag for quota protection, rotates through city×cuisine combos
Schedule::command('restaurants:enrich --throttled')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Throttled DB enrichment under SerpApi quota');

// Schedule daily sitemap generation (runs at 5 AM UTC)
Schedule::command('seo:sitemap')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Generate sitemap.xml for SEO');

// Schedule website backfill (runs at 5 AM UTC, before social scrape at 5:30)
Schedule::command('restaurants:backfill-websites')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Backfill missing website URLs from cache, web search, and domain guessing');

// Schedule social link scraping (runs at 5:30 AM UTC)
Schedule::command('restaurants:scrape-social')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Scrape restaurant websites for social media links');

// Aggregate engagement data into restaurant counters (runs at 1 AM UTC)
Schedule::command('restaurants:update-engagement')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Aggregate engagement clicks into restaurant counters');
