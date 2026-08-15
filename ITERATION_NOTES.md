# Iteration Notes

## Goal
switch the user-facing distance filter to miles while keeping internal geo math in km: (1) SearchController and RestaurantController convert the distance query param from miles to km ( * 1.60934) before it reaches UnifiedSearchService::search and Restaurant::nearby (default 25 is now 25 miles → 40.23 km), (2) RestaurantResource and LiveRestaurantResource emit distance in miles (km * 0.621371, rounding to 2dp; null stays null), (3) frontend SearchFilters.vue labels 'km' → 'mi' (1 mi / 50+ mi) and SearchResultCard.vue + RestaurantCard.vue render 'mi' instead of 'km', (4) fix PopularityScoreService proximity detail string to convert km→mi before formatting (it already said 'mi' while the value was km), (5) update any existing tests asserting km values (SearchControllerTest test_coords_path_forwards_filters_and_sort_to_unified_search expects 10.0 km — now 16.09 km; LiveRestaurantResourceTest + RestaurantResource tests asserting distance values). Internal knobs (nearby_radius_km, max_distance_km, proximity_scale_km, live_search distances) stay km. The distance query param contract is now MILES.

## State

### Done (this iteration)
- (1) Backend miles→km conversion complete. `SearchController::__invoke` now reads `$distanceMiles` (int, default 25) and computes `$distanceKm = $distanceMiles * 1.60934`; `mergedSearch` param retyped `int`→`float`. `RestaurantController::index` + `apiIndex` convert `(float) $validated['distance'] * 1.60934`. Factor `1.60934` matches the Goal; `10 * 1.60934 === 16.0934` (float-exact).
- Updated 2 existing tests asserting the old km value: `SearchControllerTest::test_coords_path_forwards_filters_and_sort_to_unified_search` (`10.0`→`16.0934`) and `RestaurantControllerTest::test_restaurant_index_coords_path_forwards_sort_and_distance_to_unified_search` (`10.0`→`16.0934`).
- (2) Resources emit distance in miles. `RestaurantResource` + `LiveRestaurantResource` now route `distance` through a private `kmToMiles(?float): ?float` helper (`round($km * 0.621371, 2)`, null stays null). `RestaurantResource` still wraps in `when(! $isShowRoute && ! is_null(...))`. Fixed the seed test bug: `DistanceMilesTest::test_restaurant_resource_emits_distance_in_miles` no longer passes `'cuisines' => collect([$cuisine])` to `factory()->create()` (pivot, not a column → QueryException); it now attaches via `$restaurant->cuisines()->attach($cuisine)`. Updated `LiveRestaurantResourceTest` km assertions to miles (`1.25`→`0.78`, `2.0`→`1.24`).
- (3) Frontend distance labels now miles. `SearchFilters.vue` renames the `km` loop/param var → `mi` and renders `1 mi` / `50+ mi` / `${mi} mi`; `SearchResultCard.vue` (line ~139) and `RestaurantCard.vue` (line ~160) render `mi` instead of `km`. Updated the 3 frontend specs (`SearchFilters.spec.ts` label assertions `1/5/50+ km`→`mi`; `RestaurantCard.spec.ts` + `SearchResultCard.spec.ts` `3.5 km`→`3.5 mi` and `not.toContain('km')`→`'mi'`). Verified: 131 frontend tests pass; no `km` string remains anywhere under `resources/js`.

### Next (in order)
1. (4) `PopularityScoreService::signalDetail` proximity → convert `(float)$raw` km→mi before `sprintf('%.1f mi …')`. This is the only remaining red test (`DistanceMilesTest::test_proximity_score_detail_converts_km_to_miles` — currently emits `1.6 mi` for a `1.60934` km input, expects `1.0 mi`).
2. (5) Verify no other km-asserting tests remain (`RestaurantResourceAggregatesTest` does not assert distance — confirmed clean).

### Gotchas
- Conversion factor must stay `1.60934` / `0.621371` exactly — the seed tests assert `abs(km - miles*1.60934) < 0.01` and `assertEqualsWithDelta(miles, km*0.621371, 0.01)`.
- Internal knobs (`nearby_radius_km`, `max_distance_km`, `proximity_scale_km`) remain km — do not convert them.
- `distanceOptions [1,5,10,25,50]` in `SearchController` are now miles; `test_distance_filter_options_are_miles` uses a config fallback default so it's currently green with no config entry (no action needed).
- Resource `distance` is a dynamically-set attribute (haversine `selectRaw` / `setAttribute`), not a model column; keep null-guards so `assertSame(0.78, ...)` etc. stay float-exact (PHP `round()` matches the source literals).

## Log

- **Iter 1:** Implemented goal part (1) — miles→km conversion in SearchController + RestaurantController (index + apiIndex), retyped `mergedSearch` distance param to float, updated the 2 existing `->with(…, 10.0, …)` assertions to `16.0934`. Verified: 774 passed / 3 failed — the 3 failures are the pre-existing red seed tests for parts (2) resources-emit-miles and (4) proximity-detail, out of scope this iteration.
- **Iter 2:** Implemented goal part (2) — resources emit distance in miles. Added `kmToMiles()` helper to both `RestaurantResource` and `LiveRestaurantResource` (`round($km * 0.621371, 2)`). Fixed the seed test QueryException (pivot vs column), updated `LiveRestaurantResourceTest` km→mi assertions. Verified: 776 passed / 1 failed — only remaining red is part (4) proximity-detail, out of scope this iteration.
- **Iter 3:** Implemented goal part (3) — frontend distance labels now miles. `SearchFilters.vue` (`1 mi`/`50+ mi`/`${mi} mi`), `SearchResultCard.vue`, `RestaurantCard.vue` render `mi`; updated the 3 corresponding specs. Verified: 131 frontend tests pass, no `km` left under `resources/js`.
