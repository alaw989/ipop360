# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/SearchFilters.spec.ts` (21 tests) covering: filters heading render, price buttons rendered from `priceOptions`, active class on selected price, emit on price click, toggle-off emit when clicking active price, category links with names and counts, active class on selected category, distance radio buttons for all options plus Auto, checked state matching current distance, default distance of "25", emit on distance change, Auto emitting `distance: undefined`, distance label formatting (1 km, 5 km, 50+ km), Auto label, "Clear all" visibility when price/distance/cuisine/category active, "Clear all" hidden when no filters or only default distance, and clear emit on button click.
  - Mocked `@inertiajs/vue3` at module level with `Link: { template: '<a><slot /></a>' }` — needed because `Link` is imported in `<script setup>`.
  - Stubbed `Button` via `global.stubs` as `{ template: '<button><slot /></button>' }` — the "Clear all" button only needs to render and emit on click, no prop forwarding needed.

Verification: `npx vitest run resources/js/Components/__tests__/SearchFilters.spec.ts` → 1 file / 21 tests pass. Full suite: 221 tests / 28 files pass.

### Next
Continue adding tests for remaining untested Components: `ResultsGrid`, `SearchResultCard`, `RestaurantCard`. `ResultsGrid` is medium complexity (185 lines, 5 phase states, can deep-stub `RestaurantCard` to avoid its heavy dependency graph). `SearchResultCard` and `RestaurantCard` require mocking `useFavorites`, `useCompare`, `useRestaurantDisplay`, and `@/lib/restaurant`.

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
