# Feature Specification: Homepage payload diet + case-safe city scoping

**Feature Branch**: `fix/spec-114-homepage-dead-props-and-payload`

**Created**: 2026-09-15

**Status**: IN PROGRESS (local)

**Series**: 2026-09 feature audit, finding #4 (`docs/feature-audit-2026-09.md`
§7). §7's headline bug (the `__PHP_Incomplete_Class` category payload) shipped
as spec-111/PR #221; this spec closes the remaining drift.

## The problem (audit evidence)

1. **Dead props.** `HomeService::getHomepageData()` returned `stats`,
   `popularCuisines` and `location`. `Welcome.vue` declares neither `stats`
   nor `popularCuisines` (zero `resources/js` matches) — only the
   `/api/homepage-data` fetch path consumed `location`.
2. **Wasted queries.** `stats` ran three uncached COUNTs (including a
   `DISTINCT city`) on every `/` and `/api/homepage-data` request, and
   nothing rendered the result.
3. **Payload bloat.** `popularRestaurants` returned full Eloquent models
   (every column — `photos`, `ai_metadata`, `score_breakdown`, …) while the
   Trending card renders name/photo/rating/price/primary-cuisine for ~18
   cards per request.
4. **Case-sensitive city matching.** Routing was `where('city', $city)` /
   `where('state', $state)`, but stored casing is inconsistent and
   GPS/IP/search geolocation can hand back any casing — while
   `Restaurant::scopeInCity` (the DB browse path) is deliberately
   case-insensitive. A lowercase "atlanta" missed the Title-Case row.

## Solution

- **Drop the dead props** from `getHomepageData()`, delete the now-unused
  `getPopularCuisines()` + its `homepage.popular_cuisines_cache_ttl_minutes`
  config key (the section has no frontend consumer; the backlog's
  "Popular cuisines noisy" item stays open for a real redesign).
- **Slim Trending payload**: new `trendingCard()` maps each row to the exact
  14 fields the card consumes. `featuredRestaurant()` still runs on the raw
  models first (its fallback pick needs `photo_url`/`google_rating`), then
  the collection is flattened.
- **Case-insensitive scoping**: Trending scopes via `Restaurant::scopeInCity`;
  city-scoped categories use `LOWER(city) = ?` / `LOWER(state) = ?`.
- **Location echo**: `location` reports the matched row's stored casing
  ("Atlanta", not the request's "atlanta") since the heading renders it
  verbatim — matching `popularCities`' Title-Case convention.
- **Frontend types aligned**: `PopularRestaurants.vue`'s `PopularRestaurant`
  drops the unsent `score_breakdown`; `Welcome.vue` types the payload as
  `TrendingRestaurant` (no lat/lng/description), test fixtures trimmed.

## Tests

- `HomeControllerTest`: expected structure updated; new
  `does_not_ship_dead_homepage_props` (missing-path stats/popularCuisines),
  `trending_restaurants_ship_a_slim_card_payload` (required keys +
  bloat keys absent), `api_data_matches_city_case_insensitively` (title-case
  row + stored-casing echo), `city_scoped_categories_match_city_case_insensitively`.
  The two `popularCuisines` suite tests were deleted with the feature.
- `HomeServiceTest`: trending assertions now index card arrays; new
  `trending_cards_are_slim_plain_arrays` pins the exact key list and array
  shape; `popularCuisines` test deleted.

## Verification

- Gate (local): pint clean · PHPStan L8 zero errors · PHPUnit 1471 passed /
  1 skipped · vitest 1129 passed · `vue-tsc` clean · `npm run build` clean.
- Live (post-deploy): inspect `/api/homepage-data` — no `stats`/
  `popularCuisines` keys; `popularRestaurants[0]` has exactly the 14 slim
  fields; page weight of the Trending section drops.
