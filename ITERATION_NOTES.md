# Iteration Notes

## Goal
ingestion-time enrichment: when LiveVenuePersister::persist() CREATES a row, queue async enrichment so new rows are rich within minutes — (1) photo hunt via the context-first searchImageForRestaurant chain, (2) EnrichRestaurantWithAi job for missing description/price_range/phone, (3) opening_hours from the venue's OSM tags when present; never block the search response (all queued); never clobber existing data on updates (created-only); respect domain-safety (no gps-cs-s primary, name-relevance guard)

## State
Progress toward the goal:
- [x] (1) photo hunt — DONE. New `App\Jobs\EnrichNewRestaurantPhoto` runs the
  context-first `searchImageForRestaurant` chain in the background and is queued
  from `LiveVenuePersister::persist()` only on CREATE when `photo_url` is empty.
- [x] (2) AI enrichment — DONE. `persist()` now dispatches
  `EnrichRestaurantWithAi` on CREATE when description/price_range/phone is
  missing. The job itself only fills empty fields, so it never clobbers.
- [x] (3) opening_hours — DONE. `persist()` now copies a string
  `$venue['opening_hours']` (OSM raw-hours tag) into `$attributes` as the app's
  `{structured:false, raw_text}` shape, and unsets it in the update branch so
  structured hours set by the scraper/enrichment are never clobbered.
- [x] (4) verification hardening — DONE. `composer test` no longer dies on the
  Composer 300s process-timeout and no longer hits real outbound HTTP from the
  no-params search tests (see Iteration 4). Full suite now green in ~156s.

## Log
### Iteration 1 — photo hunt job + dispatch on create
- Added `app/Jobs/EnrichNewRestaurantPhoto.php`: loads the row, skips when
  missing or already photo'd (created-only), runs `searchImageForRestaurant`,
  refuses to promote a gps-cs-s CDN URL to primary, sets `photo_url`, logs.
- `LiveVenuePersister::persist()` now dispatches it in the create branch when
  `photo_url` is empty (never blocks the search response).
- Added `tests/Feature/EnrichNewRestaurantPhotoTest.php` (5 cases) and three
  dispatch assertions in `LiveVenuePersisterPhotoTest`.
- Gotcha: `persist()` runs under `QUEUE_CONNECTION=sync` in tests, so a queued
  job would fire `searchImageForRestaurant` (real HTTP) during any create-path
  test. Added `Queue::fake()` to `setUp()` of `LiveVenuePersisterPhotoTest`,
  `LiveVenuePersisterAwardTest`, `LiveVenuePersisterTagTest`, and
  `UnifiedSearchServiceTest`. Any future test that creates a row via
  `persist()`/`persistTaggedVenues()` must fake the queue the same way.
- Verified: `pint --test` pass, `phpstan analyse` 0 errors, full suite 830 passed,
  `npm run build` pass.

### Iteration 2 — AI enrichment dispatch on create
- `LiveVenuePersister::persist()` now dispatches `EnrichRestaurantWithAi` in the
  create branch when `description`/`price_range`/`phone` is empty (imported
  `App\Jobs\EnrichRestaurantWithAi`). The job only fills empty fields internally,
  so it is created-only and never clobbers existing data.
- Added `tests/Feature/LiveVenuePersisterAiEnrichmentTest.php` (3 cases: missing
  fields queue, all-present skip, update never queues).
- Gotcha: this is a second dispatch in the same create branch, so the Iteration 1
  queue-faking rule still holds — every test that creates a row via `persist()` /
  `persistTaggedVenues()` must call `Queue::fake()` in `setUp()`. Confirmed all
  such tests (LiveVenuePersister{Photo,Award,Tag,AiEnrichment}, UnifiedSearchService)
  already fake the queue; otherwise a sync-queue run would fire real AI HTTP.
- Verified: `pint --test` pass, `phpstan analyse` 0 errors, targeted tests
  (LiveVenuePersister*, UnifiedSearchService, AiEnrichRestaurants) all green.

### Iteration 3 — opening_hours copy on create (created-only)
- `LiveVenuePersister::persist()` now maps `opening_hours` through a new private
  `normalizeOpeningHours()` helper: a string OSM tag (e.g. `"Mo-Fr 10:00-20:00"`)
  is stored as `['structured' => false, 'raw_text' => <tag>]` (the same shape the
  website scraper and `OpeningHours.vue` consume); non-string values (SerpApi
  operating_hours objects/arrays) are deliberately ignored so structured hours
  stay with the scraper/AI job.
- The update branch now `unset($attributes['opening_hours'])` alongside
  `has_award`, so a live-search source can never clobber existing (possibly
  structured) hours — created-only, matching the goal.
- Added `tests/Feature/LiveVenuePersisterOpeningHoursTest.php` (4 cases: string
  copy, null when absent, non-string ignored, update never clobbers).
- Gotcha: `normalize()` only trims `is_string()` values, so the array-shaped
  opening_hours passes through untouched (no special handling needed).
- Verified: `pint --test` pass, `phpstan analyse` 0 errors, targeted tests (all
  LiveVenuePersister*, EnrichNewRestaurantPhoto, UnifiedSearchService) green.

### Iteration 4 — make `composer test` reliably green
- `composer test` was timing out: the suite had grown past Composer's 300s
  `process-timeout` (the previous loop commit literally logged "check stalled on
  slow suite"). Added `Composer\Config::disableProcessTimeout` to the `test` and
  `coverage` scripts in `composer.json`, matching the existing `dev` script.
- Root cause of the slowness was not the whole suite but four
  `SearchControllerTest` "no-params" tests: the dev `.env` sets
  `DISTANCE_FALLBACK_LAT/LNG`, which leaks into the testing env (no `.env.testing`),
  so those requests routed down the live-search path and fired real outbound HTTP
  (hang/flaky). Added a `setUp()` that nulls
  `restaurant-finder.live_search.distance_fallback_{lat,lng}`, so they now take the
  deterministic db-only path; coords-path tests opt back in explicitly.
- Result: `SearchControllerTest` went from 60s+ hang to 0.6s; full suite from
  ~330s to ~156s, all 837 green.
- Gotcha: any future `SearchControllerTest` case that expects the fallback to be
  non-null MUST set it explicitly (setUp now nulls it). Same for any test class
  whose requests should NOT fire real live-search HTTP — null the fallback or mock
  `UnifiedSearchService`/`GeolocationService`.
- Verified: `pint --test` pass (changed test file), `npm run build` pass, full
  `php artisan test` 837 passed (3494 assertions).
