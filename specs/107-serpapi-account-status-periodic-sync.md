# Feature Specification: Periodically sync real SerpApi account status into the dashboard

**Feature Branch**: `master` (interactive)

**Created**: 2026-08-24

**Status**: SHIPPED + LIVE-VERIFIED (2026-08-24)

**Series**: Follow-up to spec-106 (SerpApi call-count undercount fix). Closes
spec-106's "Known follow-up" note about the exhausted flag's blind 24h
self-heal.

## The problem

Right after spec-106 deployed, the live admin dashboard showed a clean
0/250 used, 0%, no exhausted badge — yet a direct check against SerpApi's
own `/account.json` endpoint confirmed the account was genuinely exhausted
(`total_searches_left: 0`, `account_status: "Your account has run out of
searches."`, renews 2026-09-19).

Root cause: the app's SerpApi quota state is entirely self-reported from
its own call history. `SerpApiCallLog` (spec-106) only counts calls *this
app* has made since it started tracking; `serpapi_exhausted`
(`SerpApiService::isProviderExhausted()`) only trips reactively when a live
outbound call happens to get a 429 back, then self-heals blindly after 24h
regardless of whether the account is still actually dead. Neither signal
ever asks the provider directly, so a freshly-deployed counter with zero
history reads as "healthy" even on a genuinely exhausted account.

## Solution

Added a periodic, scheduled sync against SerpApi's own account-status
endpoint — a zero-quota-cost account-info call (confirmed live: distinct
from the metered `/search` endpoint) — that both displays the
provider-confirmed truth on the dashboard AND reconciles the local
exhausted flag against it:

- `SerpApiService::fetchAccountStatus()` — pure fetch of
  `GET https://serpapi.com/account.json?api_key=...`, returns the decoded
  array or `null` on a missing key / HTTP failure / malformed response /
  thrown exception.
- `SerpApiService::syncAccountStatus()` — fetches, caches the relevant
  fields (`total_searches_left`, `searches_per_month`, `this_month_usage`,
  `account_status`, `plan_name`, `plan_renewal_date`, `synced_at`) for 2h,
  then reconciles the exhausted flag using the authoritative
  `total_searches_left` field: `<= 0` → `markProviderExhausted()`; `> 0` →
  new `clearProviderExhausted()`. A fetch failure touches neither the flag
  nor the cache — a transient network blip must never wrongly clear a real
  exhaustion or wrongly mark a healthy account dead.
- `SerpApiService::cachedAccountSnapshot()` — read-only cache accessor used
  by the dashboard controller; no live network call in the request cycle.
- New scheduled command `serpapi:sync-account-status`, registered in
  `routes/console.php` at `everyFifteenMinutes()->withoutOverlapping(5)->onOneServer()`,
  matching the existing `uptime:canary` convention exactly. 15 minutes is
  cheap since the endpoint costs zero quota, and keeps the flag/display far
  fresher than the old blind 24h self-heal window it supersedes.
- `DashboardController` adds `live_account` (the cached snapshot, or `null`
  before the first sync) to the `serpapiQuota` Inertia prop.
- `Dashboard.vue` gets a new "SerpApi Account (live, provider-confirmed)"
  card above the existing 4-card grid, showing `total_searches_left /
  searches_per_month`, the raw `account_status` message, the renewal date,
  and a "synced Xm ago" line; shows a muted "not yet synced" placeholder
  when `live_account` is `null`. The existing exhausted banner and 4-card
  grid are untouched — they become *correct* automatically once the
  scheduler starts reconciling the flag.

## Acceptance criteria

- `fetchAccountStatus()` returns `null` with no key configured, on HTTP
  failure, and on a malformed response — never throws.
- `syncAccountStatus()` marks the account exhausted when
  `total_searches_left <= 0`, clears it when `> 0`, and leaves the flag
  untouched on a fetch failure (verified: marking it exhausted, then a
  faked 500, still reads exhausted afterward).
- The dashboard's `live_account` field is `null` before any sync has run
  and matches the synced snapshot afterward.
- `serpapi:sync-account-status` is registered with the same conventions
  (`withoutOverlapping`, `onOneServer`, `description`, `onFailure` logging,
  `SchedulerTelemetry::attach`) as every other entry in `routes/console.php`.

## Files

- `app/Services/SerpApiService.php` — `fetchAccountStatus()`,
  `syncAccountStatus()`, `cachedAccountSnapshot()`, `clearProviderExhausted()`.
- `app/Console/Commands/SyncSerpApiAccountStatus.php` (new).
- `routes/console.php` — schedule registration.
- `app/Http/Controllers/Admin/DashboardController.php` — `live_account` prop.
- `resources/js/Pages/Admin/Dashboard.vue` — new card + prop type.
- `tests/Feature/SerpApiAccountStatusSyncTest.php` (new) — fetch/sync/command coverage.
- `tests/Feature/AdminDashboardTest.php` — dashboard snapshot surfacing.
- `tests/Feature/SchedulerReportTest.php`, `tests/Feature/SchedulerManifestTest.php`,
  `tests/Feature/SchedulerExpiryTest.php` — updated hardcoded registered-command
  counts/maps (17→18) and added the new command's expected cron slot + mutex
  expiry, since these tests assert an exact manifest of every scheduled command.

## Quota / deploy

Zero quota cost (account.json is an account-info call, confirmed live
against the real account — not the metered `/search` endpoint). 1027
backend tests green, 1117 Vitest tests green, PHPStan/Larastan 0, Pint
clean, `npm run build` clean.

## Verification

**Live-verified 2026-08-24** (GHA deploy `3367a86` green, then the browser
admin dashboard checked ~13 minutes later after the scheduler's first tick):
the new card read `0 / 250 left`, `"Your account has run out of searches. ·
renews 2026-09-19"`, `Synced 1 minute ago`; the existing exhausted banner
reappeared; Usage `0 / 250, 0 remaining (100%)`; Circuit Breaker `Tripped`;
Enrich Budget `Exhausted`; Live Read Path `Cache only, 0 calls left this
cycle`. Every signal on the dashboard now agrees with the provider-confirmed
truth — the original spec-106 contradiction (exhausted badge vs. a clean
usage box) cannot recur, since the sync actively drives the exhausted flag
rather than the flag drifting independently of it.

**Follow-up hardening (2026-08-24, same day):** a subsequent docs-only push
through the full deploy pipeline revealed that `deploy.yml`'s "Migrate +
build caches" step runs `artisan cache:clear` on every deploy, wiping the
entire database cache store — including this spec's snapshot AND the
`serpapi_provider_exhausted` flag. Not a correctness bug (fails safe: no
snapshot just shows "not yet synced," never a false-healthy signal), but
the dashboard went dark on every deploy until the next 15-minute tick.
Fixed by running `serpapi:sync-account-status` (non-fatal) immediately
after `cache:clear` in the same step — live-verified: after the next
deploy the card showed fresh data (`0/250 left`, exhausted banner present)
within ~1 minute, no 15-minute wait. See PR #135, commit `87d101a`.

Original verification plan (superseded by the above, kept for reference):
after deploy, confirm the GHA deploy succeeds, then either wait ~15 minutes
for the first scheduled tick or manually run
`php artisan serpapi:sync-account-status` once (zero quota cost) via SSH,
and reload `https://ipop360.com/admin` to confirm the new card shows the
real `total_searches_left`/`account_status`/renewal date, and that the
existing exhausted banner now agrees with it.
