# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/PopularCuisines.spec.ts` (10 tests) covering: heading renders with `in <city>`, city omitted when `city` is null, only 12 cuisines shown initially with correct `/search?cuisine=<slug>` href + icon/name text, lat/lng appended to href only when both present, "Show more" button shows when >12 cuisines, clicking toggles to all 15 then back to 12 ("Show more"/"Show less"), no button when ≤12 cuisines, 12 skeletons shown while `loading` (no links, no button), and empty cuisines list renders zero links.

Verification: `npx vitest run resources/js/Components/__tests__/PopularCuisines.spec.ts` → 1 file / 10 tests pass.

### Next
Continue adding tests for the other untested Components: `RestaurantCard`, `ResultsGrid`, `SearchResultCard`, `SearchFilters`, `SeoMeta`, `CuisinePicker`, `LocationPicker`, `StickySearchBar`, `HeroSearch`, `SubcategoryCard`. Prefer simple presentational components first. `RestaurantCard` is the hardest (needs mocking of `@inertiajs/vue3` `usePage`/`router`, `useFavorites`, `useCompare`, `CardGallery`).

### Gotchas
- `PopularCuisines` stubs: `{ Skeleton: true, ChevronDown: true }`. Initial cap is a hardcoded `initialLimit = 12`; generate 15 fixtures to exercise Show more/less toggle.
- `wrapper.findAll(...).exists()` is not a function — use `.toHaveLength(0)` instead.
- Stub `Skeleton` to avoid the UI skeleton resolver; stub `ChevronDown` from `@lucide/vue`.
- CategoryGrid only appends `lat`/`lng` to the href when **both** are non-null — test omitting lt/lng via `null` and via absent props.
- Loading branch renders only skeleton divs (no anchors); count them via grid children, not `findAll('a')`.
- Stub `Skeleton` (`global.stubs: { Skeleton: true }`) to avoid the UI skeleton resolver.
- ScoreBreakdown no-data segment renders no label text in the DOM (empty colored bar) — assert on bar-segment count and `style` width, not text().
- ScoreBreakdown omits `detail` unless the earlier fix is present; keep `detail: s.detail` in the map or the detail text never renders.
- Tests live in `resources/js/Components/__tests__/`; run individually with `npx vitest run <file>`.

## Log
