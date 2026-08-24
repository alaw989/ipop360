# Feature Specification: SerpApi call count undercounts real quota usage

**Feature Branch**: `master` (interactive)

**Created**: 2026-08-24

**Status**: SHIPPED (2026-08-24)

## The problem

Reported live: the admin dashboard (`/admin`) showed a red "SerpApi account
exhausted" badge (provider-confirmed — a real exhaustion email had arrived
from SerpApi) at the same time as a usage box claiming "145/250 used, 105
remaining (58%)". The two signals directly contradicted each other.

Root cause: `ExternalApiCache::stats()['serpapi_calls_last_30d']`
(`app/Models/ExternalApiCache.php`) counted **distinct rows** in
`external_api_cache` with `source='serpapi'` and `fetched_at` in the last 30
days. But `storeByKey()`/`recordFailedCall()` write via `updateOrCreate()`
keyed on the cache key — a row **upsert, not an append**. Every time the
same rounded lat/lng/query key is fetched again after its cache entry
expires (2h for empty/failed results, 720h for success), the same row is
updated (`fetched_at` bumped) instead of a new row being inserted. The
metric measured "distinct locations/queries touched in 30 days," not
"outbound SerpApi calls made in 30 days" — every repeat call against an
already-seen key was invisible to it.

This was more than a cosmetic dashboard bug: this exact metric fed the live
read-path circuit breaker (`LiveSearchService::allowLiveSerpApiFetch()`),
duplicated again in `RestaurantEnrichmentService::countRealSerpApiCallsLast30Days()`
for the enrichment monthly-budget gate, and read a third/fourth time by
`uptime:canary` and `quota:status`. spec-073's circuit breaker ("guarantees
the read path can never exhaust the quota") was undermined by feeding it an
undercounted number — consistent with the account going fully exhausted for
real while the app's own counter still read 58% used and never tripped.

## Solution

Introduced a durable, append-only counter of real outbound SerpApi call
attempts, independent of the cache-row-dedup proxy:

- New `serpapi_call_log` table + `App\Models\SerpApiCallLog` model
  (`record()` always inserts a new row; `countLast30Days()` reads the true
  30-day count).
- `SerpApiService::record()` calls hooked into every real outbound attempt:
  `search()`, `fetchRaw()` (both success and exception paths), and
  `consumePoolResponses()` (covers both pooled callers — the live read
  path's `LiveSearchService::dispatchPool()` and the enrichment path's
  `RestaurantEnrichmentService::fetchAndNormalizeAllSources()`).
- All 5 consumers of the flawed metric swapped to `SerpApiCallLog::countLast30Days()`:
  `DashboardController`, `LiveSearchService::allowLiveSerpApiFetch()` (the
  actual circuit-breaker gate), `RestaurantEnrichmentService::countRealSerpApiCallsLast30Days()`,
  `UptimeCanary`, `QuotaStatusCommand`.
- `ExternalApiCache::stats()['serpapi_calls_last_30d']` kept (existing tests
  depend on it as a legitimate cache-inventory stat) but re-documented as
  NOT a quota metric, pointing callers at `SerpApiCallLog` instead.
- Dashboard reconciliation: when `serpapi_exhausted` (provider-confirmed) is
  true, `remaining`/`pct_used`/`circuit_breaker_tripped`/`enrich_budget_exhausted`
  are clamped to reflect it regardless of what the counter says — the two
  signals can no longer visually contradict each other.

## Acceptance criteria

- `SerpApiCallLog::countLast30Days()` counts every real outbound SerpApi
  attempt (success, HTTP failure, and thrown exception) across `search()`,
  `fetchRaw()`, and the pooled path — not just distinct cache keys.
- A regression test proves the original bug: repeated real calls against the
  *same* cache key (simulated via time-travel past each empty-row TTL) stay
  at 1 row in `ExternalApiCache` but count correctly in `SerpApiCallLog`.
- The live-search circuit breaker, enrichment monthly-budget guard,
  `uptime:canary`, and `quota:status` all read the true counter.
- The admin dashboard never shows `serpapi_exhausted: true` alongside a
  `remaining > 0` / `pct_used < 100`.

## Files

- `database/migrations/2026_08_24_143627_create_serpapi_call_log_table.php`
- `app/Models/SerpApiCallLog.php` (new)
- `app/Services/SerpApiService.php` — `record()` hooks in `search()`,
  `fetchRaw()`, `consumePoolResponses()`.
- `app/Services/LiveSearchService.php` — circuit-breaker gate.
- `app/Services/RestaurantEnrichmentService.php` — monthly-budget gate.
- `app/Http/Controllers/Admin/DashboardController.php` — dashboard
  reconciliation.
- `app/Console/Commands/UptimeCanary.php`, `app/Console/Commands/QuotaStatusCommand.php`.
- Tests updated across `tests/Feature/SerpApiExhaustionTest.php`,
  `tests/Feature/SerpApiQuotaGuardTest.php`, `tests/Feature/AdminDashboardTest.php`,
  `tests/Feature/UptimeCanaryCommandTest.php`, `tests/Feature/QuotaStatusCommandTest.php`,
  `tests/Feature/SerpApiPersistenceAndThrottlingTest.php`,
  `tests/Unit/RestaurantEnrichmentServiceTest.php` — quota-simulation setup
  switched from seeding `ExternalApiCache` rows to `SerpApiCallLog::record()`.

## Quota / deploy

Backend-only fix; no outbound SerpApi calls needed to build or verify
locally (1014 tests, Pint clean, PHPStan/Larastan clean). Live verification
after deploy: confirm the admin dashboard no longer shows a contradiction
between the exhausted badge and the usage box.

## Known follow-up (not in scope)

`SerpApiService::EXHAUSTED_RETRY_HOURS = 24` self-heals the exhausted badge
after 24h regardless of whether SerpApi's real monthly billing cycle has
actually reset — worth revisiting if the badge starts clearing prematurely.
