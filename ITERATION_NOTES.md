# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/ResultsGrid.spec.ts` (23 tests) covering all 5 phase states: searching (spinner + message), error (error badge + message + Start Over/Try Again emits), empty (utensils icon + message + Start Over emit), results (result count singular/plural, sort dropdown options, select value from prop, sort change emits both `update:sort` and `resort`, RestaurantCard per-restaurant render with rank, load-more button visibility/emit, isResorting opacity classes), and load-more error (error card + message + Retry emit + dismiss button emit).
  - Mocked `@lucide/vue` at module level for `Utensils`, `X`, `Search` — these are named imports in `<script setup>`.
  - Stubbed `Badge`, `Button`, `Card`, `CardContent` (shadcn) — Badge and Button forward their variant/ariaLabel props; Card/CardContent are slot-pass-through wrappers.
  - Deep-stubbed `RestaurantCard` with a simple `<div>` forwarding the name and data-rank: avoids pulling in `useFavorites`, `useCompare`, etc.
  - Helper `makeRestaurant()` builds minimal Restaurant-shaped objects; `mountGrid()` provides defaults for all 14 props so each test only overrides what it needs.

Verification: `npx vitest run resources/js/Components/__tests__/ResultsGrid.spec.ts` → 1 file / 23 tests pass.

### Next
Continue adding tests for remaining untested Components: `SearchResultCard` and `RestaurantCard`. `SearchResultCard` (medium complexity, 5 states: stale, loading, error, no-data, loaded) — needs `useFavorites` mocked. `RestaurantCard` is the heaviest dependency graph (uses `useRestaurantDisplay`, `useCompare`, `@/lib/restaurant`) and will need the most stubbing.

### Gotchas
- Tests live in `resources/js/Components/__tests__/`; run individually with `npx vitest run <file>`.
- Components that directly `import` from `@inertiajs/vue3` (e.g. `Head`, `Link` as named imports in `<script setup>`) need `vi.mock('@inertiajs/vue3', ...)` at module level, NOT just `global.stubs`. Inertia's internals reference the plugin context (`createProvider`) at setup time and will throw `TypeError: Cannot read properties of undefined (reading 'createProvider')` if not mocked at module level.
- For components that use `Link` as a resolved global component (no explicit import), a `global.stubs: { Link: { template: '<a><slot /></a>' } }` is sufficient.
- `$page.props.auth` is injected dynamically: `global.$page = { props: { auth: { user } } }`. Set `user` to an object to render the Favorites link, `null` for guests.
- Stub complex children in presentational parents: for `HeroSearch` stub `Button`, `CuisinePicker`, `LocationPicker`, `BrandLogo` so assertions stay focused on the wrapper's own renders/emits.
- The stub `Button` must forward `disabled` (`<button :disabled="disabled"><slot /></button>`) for the detecting-state test to assert the disabled attribute.
- `vi.mock('@/composables/useIsMobile')` with `ref(false)` → desktop Popover path; shadcn `Popover: true` does NOT render default slots — use `{ template: '<div><slot /></div>' }` for slot-passing stubs.
- CommandItem stub must emit `select` on click (`@click="$emit('select')"`) for `@select="handler(cat)"` bindings to fire.
- Debounced async searches need `vi.useFakeTimers()` + `vi.advanceTimersByTimeAsync(300)` (not `advanceTimersByTime` — the async variant flushes microtasks that the resolved API promise schedules).
- Dynamic `import('@/lib/api')` inside a component method resolves from the same `vi.mock('@/lib/api', ...)` as static imports.

## Log
