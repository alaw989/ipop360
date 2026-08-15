# Iteration Notes

## Goal
add --verify mode to restaurants:backfill-photos: HTTP-check each row's existing photo_url (HEAD with GET fallback, ~8s timeout), keep valid photos untouched (200), re-source confirmed-dead ones (403/404/error) via the existing searchAnyImage free chain (website og:image → Wikimedia Commons → Wikipedia → Google Custom Search), remove the dead URL from the photos gallery array, tolerate transient failures (retry a 403 once before treating as dead — never churn a valid row), prioritize rows whose photo_url host is lh3.googleusercontent.com/gps-cs-s/ first then remaining photo-having rows, support --limit bounding and default dry-run with --apply to persist, log each re-sourced photo + a summary to the enrichment channel; schedule a WEEKLY bounded sweep (e.g. --verify --apply --limit=200 weekly) matching the ~1-month gps-cs-s decay; also prevent at persist time in LiveVenuePersister: a gps-cs-s URL must never overwrite an existing stable (Wikimedia/venue-owned) photo — only fill photo_url when empty

## State
Iteration 1 done: implemented `--verify` mode on `restaurants:backfill-photos`
(`app/Console/Commands/BackfillRestaurantPhotos.php`). Added `--verify` flag,
`handleVerify()` branch, and `isPhotoAlive()`/`requestSucceeds()` helpers.
Behavior: HEAD→GET fallback with 8s timeout; 403 retried once via the GET
fallback (transient 403 never churns a valid row); alive rows kept untouched;
dead rows re-sourced via `searchAnyImage` (mockable scraper); dead URL stripped
from the `photos` gallery and fresh photo merged in; gps-cs-s rows ordered first
(`photo_url LIKE '%gps-cs-s%'`); `--limit` bounding + default dry-run with
`--apply`; per-photo re-source log + summary log to the enrichment channel.

Iteration 2 done: wired the WEEKLY verify sweep into `routes/console.php`
(`Schedule::command('restaurants:backfill-photos --verify --apply --limit=200')
->weeklyOn(3, '07:30')`, Wednesdays, after all daily 00:30–07:00 jobs to avoid
SQLite lock contention). `PhotoVerifyTest` is now 6/6 green.

Iteration 3 done: LiveVenuePersister guard. `persist()` now calls a private
`guardTransientPhotos()` on the update path (before `$restaurant->update()`).
A gps-cs-s URL only fills an empty `photo_url` (never overwrites a stable
Wikimedia/venue-owned photo), and `stablePhotosFirst()` reorders the gallery so
a gps-cs-s entry never displaces an existing stable entry. Create path is
untouched (no existing photo to protect). New `LiveVenuePersisterPhotoTest`
(5/5 green). Also fixed two stale schedule tests from Iteration 2
(`PhotoBackfillScheduleTest`, `PhotoBackfillImprovementsTest`) that asserted
"exactly one" backfill-photos event — they now exclude the `--verify` sweep —
and repaired 7 pre-existing PHPStan `Model|null` errors in `PhotoVerifyTest`
(via `fresh()` + `assertNotNull`). Full gate green: 788 tests, pint, phpstan,
build.

Next: Goal is complete. Nothing left to implement; operator-gated hardening +
PR/merge/deploy/verify per the backlog workflow.

Gotchas: `Http::fake` matches HEAD and GET by URL pattern (not method), so the
HEAD-first check works with single-response fakes. `searchAnyImage` is only
invoked for dead rows, so valid-row tests need no scraper mock. Keep the
existing backfill path untouched — `handle()` now branches on `--verify` first.
`guardTransientPhotos` runs only on the update branch; `photos` is array-cast so
no `is_array` guard is needed there (PHPStan level 8 flags the redundant check).

## Log
- Iteration 1: `--verify` mode end-to-end. 5/6 `PhotoVerifyTest` green (schedule
  test pending). Pint + PHPStan clean.
- Iteration 2: weekly `--verify --apply --limit=200` sweep scheduled
  (Wed 07:30). 6/6 `PhotoVerifyTest` green.
- Iteration 3: LiveVenuePersister transient-photo guard + `LiveVenuePersisterPhotoTest`
  (5/5). Fixed 2 stale schedule tests and 7 pre-existing PHPStan errors. Full
  suite 788 passed, pint + phpstan + build clean. Goal complete.
