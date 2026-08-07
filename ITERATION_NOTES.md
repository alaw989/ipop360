# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/SubcategoryCard.spec.ts` (5 tests) covering: icon + name render, description renders when present, description omitted when null, click emits `select` with the cuisine slug, and emit works when icon/description are absent.

Verification: `npx vitest run resources/js/Components/__tests__/SubcategoryCard.spec.ts` → 1 file / 5 tests pass.

### Next
Continue adding tests for the other untested Components: `RestaurantCard`, `ResultsGrid`, `SearchResultCard`, `SearchFilters`, `SeoMeta`, `CuisinePicker`, `LocationPicker`, `StickySearchBar`, `HeroSearch`. Prefer simple presentational components first (`StickySearchBar`, `HeroSearch`, `CuisinePicker` are next easiest). `SearchResultCard` and `RestaurantCard` are hardest (need mocking of `@inertiajs/vue3` `usePage`/`router`, `useFavorites`, `useCompare`, `CardGallery`).

### Gotchas
- Tests live in `resources/js/Components/__tests__/`; run individually with `npx vitest run <file>`.
- `SubcategoryCard` uses shadcn `@/components/ui/card` (Card/CardContent). These are simple presentational divs with `data-slot="card"`/`data-slot="card-content"`, so no stubs are needed — mount the real components and query the card root via `[data-slot="card"]` to assert the `select` emit.
- `SubcategoryCard` `cuisine` prop requires `id`/`name`/`slug` (and optional `description`/`icon`); omit fields via `{ ...cuisine, description: null }` or construct a full object.

## Log
