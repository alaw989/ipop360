<?php

use App\Support\SchedulerTelemetry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule daily re-scoring of all restaurants (runs at 2 AM UTC)
Schedule::command('restaurants:score')
    ->dailyAt('02:00')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Recompute popularity scores for all restaurants')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:score']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Schedule nightly API cache garbage collection (runs at 3 AM UTC)
Schedule::command('apicache:gc')
    ->dailyAt('03:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->description('Garbage collect expired API cache entries')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'apicache:gc']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Schedule uptime canary (runs every 15 minutes)
Schedule::command('uptime:canary')
    ->everyFifteenMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->description('Check application health and uptime')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'uptime:canary']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Schedule throttled DB enrichment (runs daily at 4 AM UTC)
// Uses --throttled flag for quota protection, rotates through city×cuisine combos
// Mutex expiry 360m covers the full ~5h35m run: even a hard-crashed run releases
// its lock the same day so tomorrow's tick is never blocked.
Schedule::command('restaurants:enrich --throttled')
    ->dailyAt('04:00')
    ->withoutOverlapping(360)
    ->onOneServer()
    ->description('Throttled DB enrichment under SerpApi quota')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:enrich --throttled']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Schedule daily sitemap generation (runs at 5 AM UTC)
Schedule::command('seo:sitemap')
    ->dailyAt('05:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->description('Generate sitemap.xml for SEO')
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Schedule website backfill (runs at 6 AM UTC, after enrichment 04:00–05:05
// and social scrape 05:30 complete, avoiding SQLite lock contention)
Schedule::command('restaurants:backfill-websites')
    ->dailyAt('06:00')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->description('Backfill missing website URLs from cache, web search, and domain guessing')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:backfill-websites']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Schedule photo backfill (runs at 6:30 AM UTC daily, after backfill-websites
// at 06:00 so website_url is populated — website og:image is the most reliable
// source). Decoupled from the SerpApi-bound enrichment so image retrieval keeps
// running even when SerpApi quota is exhausted. All sources are free; the
// --limit cap bounds the daily run so the Google Custom Search last-resort
// source (~100 req/day free) is never exhausted by an unbounded sweep.
// --min-photos tops up the multi-photo gallery on rows that already have a
// primary photo (live gallery coverage was only ~4% — cards degrade to a single
// image). Live run: 83% of photo hits came from free-first sources (website
// og:image / Wikimedia / Wikipedia), so 200/day stays safely under the CSE cap.
Schedule::command('restaurants:backfill-photos --apply --limit=200 --min-photos=2')
    ->dailyAt('06:30')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->description('Backfill missing restaurant photos + top up galleries from free sources (bounded)')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:backfill-photos --apply --limit=200 --min-photos=2']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Weekly photo-URL verification sweep (Wednesdays at 07:30 UTC, after all
// daily 00:30–07:00 jobs so it never contends for the SQLite write lock).
// HTTP-checks each row's photo_url (HEAD→GET fallback), keeps valid photos,
// and re-sources dead ones via the free searchAnyImage chain. gps-cs-s Google
// CDN URLs decay opaquely (~1-month) and are checked first. Bounded by
// --limit=200 so the free Google Custom Search last-resort source is never
// exhausted by an unbounded sweep.
Schedule::command('restaurants:backfill-photos --verify --apply --limit=200')
    ->weeklyOn(3, '07:30')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->description('Weekly verify + re-source of dead restaurant photo URLs (gps-cs-s first)')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:backfill-photos --verify --apply --limit=200']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Schedule social link scraping (runs at 5:30 AM UTC daily)
Schedule::command('restaurants:scrape-social')
    ->dailyAt('05:30')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Scrape new restaurant websites for social media links')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:scrape-social']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Saturday at 06:30 — refresh all existing social links (honours 30-day cache)
Schedule::command('restaurants:scrape-social --force')
    ->weeklyOn(6, '06:30')
    ->withoutOverlapping(240)
    ->onOneServer()
    ->description('Weekly re-scrape of all social media links')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:scrape-social --force']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Sunday at 07:00 — backfill Michelin award status for all restaurants from
// Wikidata (free, cached 30d per city box). Previously awards only ran as a
// side-effect of the 15-combo/day throttled enrichment, so most rows were
// never checked and has_award read 0 everywhere. (spec-104 award audit)
Schedule::command('restaurants:refresh-awards')
    ->weeklyOn(0, '07:00')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->description('Backfill Michelin award status for all restaurants from Wikidata')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:refresh-awards']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Aggregate engagement data into restaurant counters (runs at 00:30 UTC,
// before the 02:00 scoring run, so scores reflect the freshest engagement data)
Schedule::command('restaurants:update-engagement')
    ->dailyAt('00:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->description('Aggregate engagement clicks into restaurant counters')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:update-engagement']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Continuous data-hygiene pass (runs at 01:00 UTC, after engagement aggregation
// at 00:30 and before re-scoring at 02:00, so scores reflect a clean corpus).
// Normalizes state/city/name/address/phone and merges true duplicates. Bounded
// to 200 merge pairs + 200 enrich rows per run so a single daily sweep never
// exhausts the AI quota; the next run picks up where this one left off.
Schedule::command('restaurants:data-hygiene --apply --limit=200')
    ->dailyAt('01:00')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->description('Normalize restaurant fields and merge true duplicates (data hygiene)')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:data-hygiene --apply --limit=200']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// AI enrichment fills missing description, phone, website_url, price_range,
// and cuisines on all restaurants (uses Groq LLM, not SerpApi quota).
// Runs every 6 hours so rate-limited records are gradually filled.
Schedule::command('restaurants:ai-enrich')
    ->everySixHours()
    ->withoutOverlapping(180)
    ->onOneServer()
    ->description('AI enrichment for missing fields on all restaurants')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:ai-enrich']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Weekly field-coverage report (Mondays at 6:30 AM UTC, after weekend jobs)
Schedule::command('restaurants:coverage')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->description('Report field coverage across the restaurant corpus')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:coverage']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));

// Weekly website URL dead-link verification (Sundays at 6 AM UTC)
Schedule::command('restaurants:verify-websites --limit=200')
    ->weeklyOn(0, '06:00')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->description('HEAD-check existing website URLs, clear dead links')
    ->onFailure(function () {
        Log::channel('enrichment')->error('Scheduled command failed', ['command' => 'restaurants:verify-websites']);
    })
    ->tap(fn ($event) => SchedulerTelemetry::attach($event));
