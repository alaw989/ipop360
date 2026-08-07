# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/ScoreBreakdown.spec.ts` (10 tests) covering: score % rendering, the three "No data" paths (zero total / empty signals / all-zero contributions render a single full-width segment), one bar segment per active signal, proportional bar widths, the 5% min-width floor, and signal label + contribution % output.
- Fixed a real bug in `resources/js/Components/ScoreBreakdown.vue`: the `barSegments` computed map dropped the `detail` field, so the signal-detail tooltip line always rendered as `<!--v-if-->`. Added `detail: s.detail` to the mapped object.

Verification: `npm run test` → 19 files / 127 tests pass.

### Next
Continue adding tests for the other untested Components: `CategoryGrid`, `ScoreBreakdown`-adjacent cards, `RestaurantCard`, `ResultsGrid`, `SearchResultCard`, `SearchFilters`, `SeoMeta`, `CuisinePicker`, `LocationPicker`, `StickySearchBar`, `HeroSearch`. Prefer simple presentational components first.

### Gotchas
- ScoreBreakdown's no-data segment renders no label text in the DOM (empty colored bar) — assert on bar-segment count and `style` width, not `text()`.
- The component omits `detail` unless the fix above is present; keep `detail: s.detail` in the map or the detail text never renders.
- Tests live in `resources/js/Components/__tests__/`; run individually with `npx vitest run <file>`.

## Log
