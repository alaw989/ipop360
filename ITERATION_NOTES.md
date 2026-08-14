# Iteration Notes

## Goal
unified merged search: always run live free-source search, merge DB rows, rank the union by popularity score, raise result caps, guard free-source cold misses

## State

### Done this iteration
- Reconciled the last remaining entry point — `RestaurantController::index()`
  (the `/restaurants` browse page) — with the unified merged search. With coords
  it now runs `UnifiedSearchService::search()` (always-live free-source + DB
  merge + one-pass popularity ranking) and renders the union through a new
  `mergedBrowsePaginator()` helper, which shapes the union into a
  `LengthAwarePaginator` for Inertia (parity with apiIndex's
  `paginatedUnionResponse`, but Inertia-shaped instead of a JSON envelope).
  No-coords requests keep the DB-only Eloquent path (`RestaurantResource`,
  price/cuisine/category/sort unchanged).
- `mergedBrowsePaginator()` mirrors the page-snapshot contract: page 1 snapshots
  the full user-sorted union under a distinct `browse_page:{...}` key (includes
  `distance` in the md5 — apiIndex's `union_page:` key does not); pages 2+ slice
  it with no re-search; each shown row is also snapshotted under `preview:{slug}`.
- Narrowed `$cuisineSlug`/`$categorySlug` in `index()` to `?string` (an array
  query param like `?cuisine[]=x` is malformed and treated as absent) so the
  merged call is type-clean under PHPStan level 8.
- Confirmed the `is_live` logging split is already correct (no code change): the
  merged apiIndex JSON always emits `is_live=true`; the no-coords DB-only JSON
  envelope emits no `is_live` key → `LogApiRequest` logs `false`. The Inertia
  browse page is never logged by the middleware (it only inspects
  `application/json` content-type), so its now-always-live behavior needs no
  `is_live` marker.

### Verification
- Updated the three coords-bearing `index()` tests to stub `UnifiedSearchService`
  (`bindUnifiedSearchResults`); added `unionRow` helper + tests for merged-union
  render order, sort/distance forwarding to the service, union pagination, and
  the page-2 snapshot short-circuit (`once()` mock).
- `composer test` → 726 passed (3208 assertions).
- `./vendor/bin/phpstan analyse` → 0 errors.
- `vendor/bin/pint --test` → clean.
- `npm run build` (vue-tsc + vite + ssr) → passes.

### Next (remaining goal work)
- None. All five goal sub-parts are landed across all three entry points
  (apiIndex + /search + /restaurants): always-live merged search, DB↔live merge,
  one-pass popularity ranking, raised result caps (150 max / 75 BizData / 80
  Overpass / 50 Photon), and the free-source cold-miss guard.

### Gotchas
- The browse page's snapshot key is prefixed `browse_page:` (distinct from
  apiIndex's `union_page:` and the Search page's `search_page:`), and includes
  `distance` in the md5. Without `distance`, a `?distance=5` and `?distance=25`
  browse request would collide on the same snapshot.
- Any coords-bearing `/restaurants` (index) test must stub `UnifiedSearchService`
  (or the real free-source HTTP fires); the three pre-existing coords tests were
  updated to `bindUnifiedSearchResults(...)`.
- The browse page (`index`) now ranks coords-path rows by the union's RECOMPUTED
  popularity score (UnifiedSearchService::scoreUnion), NOT the persisted
  `orderByDecayedScore()` used by the DB-only path. That is the intended "rank
  the union by popularity score" behavior, but it means `best_match` on the
  coords path can order differently than on the no-coords path for the same DB
  rows.
- CRITICAL: the page-2 snapshot short-circuit must gate the
  `UnifiedSearchService::search()` CALL itself, not just the slice. If
  `apiIndex` runs the merged search before checking `page > 1`, page 2 re-burns
  the live sources and re-ranks a fresh union — caught by
  `test_api_live_pagination_page2_uses_snapshot_not_search` (mock `once()`).
  The Search page (`test_coords_path_page2_uses_snapshot_not_search`) and the
  browse page (`test_restaurant_index_coords_path_page2_uses_snapshot_not_search`)
  mirror this.
- The Search page's snapshot key is prefixed `search_page:` AND includes
  `price_range` in the md5 (apiIndex's key doesn't). Without `price_range` in the
  key, a `?price_range=$` search would collide with `?price_range=$$` and serve
  the wrong snapshot.
- The Search page defaults `distance` to 25 (the frontend always sends it), so
  the merged search always runs scoped to 25km — unlike `apiIndex`, which passes
  `null` distance when the client omits it.
- `rank_change` is a day-over-day DB column (ScoreRestaurants command); the
  merged union only carries it on DB-origin rows (via `$r->toArray()`), never on
  live-origin rows. `LiveRestaurantResource` defaults it to null — the card's
  `rank_change != null` guard hides the badge. `distance` IS always present on
  union rows (scoreUnion stamps it, neutral-proximity sentinel aside).
- `restaurants.popularity_score` is NOT NULL: unmatched live rows MUST be
  persisted AFTER scoring (or defaulted) or the INSERT violates the constraint.
- The seed test builds `UnifiedSearchService` with a mocked `LiveSearchService`
  returning raw rows (no `popularity_score`/`distance`/`cuisine_match`), so the
  service must not assume live rows arrive pre-scored.
- The confidence filter MUST run BEFORE `persistUnmergedLiveRows` so a
  confidence-dropped `_persist` live row is never written to the DB.
- Free-source guard: `request()->ip()` is null in CLI/unit tests, so the limiter
  is inert there (only Feature HTTP requests carry 127.0.0.1). The guard runs
  after SerpApi is extracted, so it never gates SerpApi.
- BizData cache key: `cacheKeyFor` bakes `limit` into the key. The read path
  calls it WITHOUT an explicit limit, so it must default to the config-driven
  `live_limit` (not the old hardcoded 50) or a limit-75 fetch lands under a
  limit-50 key and collides with enrichment-path cache entries.
- A coords-bearing `apiIndex` test must stub `LiveSearchService` (e.g.
  `bindLiveSearchResults([])`) or the unified path fires real outbound HTTP
  (`test_restaurant_api_sort_by_nearest` was updated accordingly).
- `price_range` filter is an EXACT string match (`===`), matching the old
  `SearchController` `where('price_range', ...)`. It does NOT normalize currency
  (a `€€` row won't match a `$$` filter). `PriceLevelNormalizer` already exists
  for level-aware matching — a follow-up could compare normalized levels 1-4
  instead, but that diverges from the current DB-path behavior so it was left
  out of this iteration.

## Log
