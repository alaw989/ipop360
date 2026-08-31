# Feature Specification: Scheduled-job audit fixes

**Feature Branch**: `fix/scheduled-job-audit` (interactive)

**Created**: 2026-08-31

**Status**: SHIPPED (2026-08-31)

**Series**: Follow-up to spec-096 (scheduled-job observability, PROPOSED
2026-06-30). Spec-096 was written against a 5-job scheduler before a prior
hardening pass (PRs #118-121) shipped `SchedulerTelemetry`,
`scheduler:health`, and `scheduler:report` — most of 096's scope is already
covered by that work. This spec closes the remaining live gaps, found by
actually running the scheduler's own diagnostic tools against production
(`php artisan scheduler:report --json --days=14`, `ps aux`) rather than
static code review alone — the first time this scheduler has been audited
against live evidence instead of just its source.

## The problem

Live evidence from the droplet (`scheduler:report --json --days=14`, cross-
checked with `ps aux`) surfaced one active production incident and three
smaller correctness bugs, none visible from code review alone until the
telemetry pointed at them:

1. **`restaurants:backfill-websites --limit=400` was hung in production**,
   caught still running via `ps aux` 7 hours into an 11:45 UTC run. 14-day
   telemetry showed this was chronic: avg runtime 56,209s (15.6h), max
   65,015s (18h) — vs. `withoutOverlapping(240)`'s 4-hour mutex. Root cause:
   `scrapeSocialLinks()` (`BackfillRestaurantWebsites.php`) queried ALL
   active restaurants with a website but `social_links_count = 0` — no
   `--limit`, no cap — a backlog that had grown to **17,639 rows** in
   production as the cache-matching phase kept adding new `website_url`s
   daily. Every individual HTTP call already had a timeout
   (`RestaurantWebsiteScraperService::REQUEST_TIMEOUT`/`VERIFY_TIMEOUT`); the
   volume itself was the problem, not a hang.
2. **`ScoreRestaurants.php`** returned `self::FAILURE` on a true-empty run
   (0 active restaurants) — a spec-096 concern that turned out to still be
   live for this one command specifically (checked `EnrichRestaurants.php`'s
   equivalent branches too: they live in code paths the actual `--throttled`
   cron invocation never reaches, so no fix was needed there).
3. **`VerifyRestaurantWebsites.php`**'s `--max-age-days` flag was parsed but
   never applied to the query, and the query had no ordering beyond
   ascending `id` — live telemetry confirmed the weekly job ran
   "successfully" every time while silently re-checking the same first ~200
   rows by id forever; restaurants further down the id range were never
   dead-link-checked.
4. **`RefreshAwards.php`** and **`VerifyRestaurantWebsites.php`** didn't log
   a completion summary to the shared `enrichment` channel like every other
   scheduled command does, weakening the audit trail `scheduler:report`
   relies on.

Also confirmed healthy during the same audit (no fix needed): `AI_API_KEY`
and `ADMIN_NOTIFY_EMAILS` are both present in prod (verified via GH secrets
+ direct droplet check); `restaurants:ai-enrich` telemetry showed real
completions, not a silent no-op; `seo:sitemap`/`uptime:canary` permission
errors in the 14-day window were a one-time, self-resolved 2026-08-23
incident, not ongoing.

## Solution

- `BackfillRestaurantWebsites.php`: added `SOCIAL_SCRAPE_DAILY_LIMIT = 400`
  (matching the sibling standalone `restaurants:scrape-social --limit=400`
  job's existing daily volume) and capped `scrapeSocialLinks()`'s query with
  `orderBy('id')->limit(...)`, switching from unbounded `chunkById` to a
  bounded `get()` + `foreach` (mirroring `scrapeMenuData()`'s existing
  pattern in the same file). A restaurant that fails to yield links keeps
  `social_links_count = 0` and is naturally retried on a later run.
- `ScoreRestaurants.php`: true-empty path now returns `self::SUCCESS` (a
  no-op is not a crash) with a comment explaining why, since
  `scheduler:health` treats `FAILURE` as alert-worthy.
- New nullable `restaurants.website_verified_at` timestamp (migration
  `2026_08_31_000003`, mirrors the existing `photo_verified_at` cooldown
  pattern), added to `Restaurant::$fillable`/`$casts`.
  `VerifyRestaurantWebsites.php` now filters on
  `website_verified_at IS NULL OR < now() - max-age-days`, orders
  never-checked rows first then oldest-checked, and stamps
  `website_verified_at = now()` on every row it actually checks (alive,
  dead, or transient-skip) so the cursor advances instead of starving on
  flaky-but-alive sites. Logging switched from the default channel to
  `Log::channel('enrichment')`.
- `RefreshAwards.php`: added a `Log::channel('enrichment')->info('Award
  refresh complete', [...])` completion summary alongside the existing
  per-change log lines.

## Acceptance criteria

- `restaurants:backfill-websites`'s social-scrape phase processes at most
  `SOCIAL_SCRAPE_DAILY_LIMIT` (400) restaurants per run regardless of
  backlog size (regression test: 450-row backlog, assert exactly 400
  `scrapeSocial()` calls).
- `restaurants:score` exits `SUCCESS` on a true-empty active-restaurant set.
- `restaurants:verify-websites`: a restaurant verified within
  `--max-age-days` is excluded from the next run; a never-verified
  restaurant is checked before an already-verified one regardless of id
  order; every checked row (alive/dead/transient) gets `website_verified_at`
  stamped in non-dry-run mode.
- `restaurants:refresh-awards` and `restaurants:verify-websites` log a
  completion summary to the `enrichment` channel.

## Files

- `app/Console/Commands/BackfillRestaurantWebsites.php`
- `app/Console/Commands/ScoreRestaurants.php`
- `app/Console/Commands/VerifyRestaurantWebsites.php`
- `app/Console/Commands/RefreshAwards.php`
- `app/Models/Restaurant.php` — `website_verified_at` fillable + cast.
- `database/migrations/2026_08_31_000003_add_website_verified_at_to_restaurants_table.php` (new)
- `tests/Feature/ScoreRestaurantsCommandTest.php` — exit-code assertion updated.
- `tests/Feature/VerifyRestaurantWebsitesCommandTest.php` — log-channel
  assertion updated; new tests for max-age-days exclusion, stale re-check,
  never-checked-first ordering, and the verified_at stamp.
- `tests/Feature/BackfillWebsitesSocialScrapeCapTest.php` (new) — the P0
  regression test.

## Quota / deploy

No SerpApi/AI quota impact — all changes are internal query/logging/exit-code
fixes plus one additive nullable column. Migration is a simple
`ADD COLUMN ... NULLABLE`, safe on the live MySQL table with no backfill
required (null = never verified, sorts first naturally). Deploy + verify:
confirm GHA deploy succeeds, then the day after deploy re-run
`php artisan scheduler:report --json --days=1` on the droplet (via
`~/.ssh/droplet-vp-nuxt`) to confirm `restaurants:backfill-websites`
completes in a bounded time (tens of minutes, not hours) and
`scheduler:health`'s problem count drops from the pre-fix baseline of 9.
