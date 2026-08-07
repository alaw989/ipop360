# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/composables/__tests__/useBaseUrl.spec.ts` (3 tests) covering the client-side branch of `useBaseUrl`: returns `window.location` origin from jsdom, is reactive (computed with `.value`), and matches protocol+host format (no trailing path). The SSR fallback branch (`typeof window === 'undefined'`) was already covered in `ssr-fallback.node.spec.ts`.

Verification: `npx vitest run resources/js/composables/__tests__/useBaseUrl.spec.ts` → 1 file / 3 tests pass. Full suite: 33 files / 366 tests pass.

### Previous iteration
- Added `resources/js/composables/__tests__/useCompare.spec.ts` (9 tests) covering the compare composable: empty state, toggleCompare add/remove, clearCompare, isInCompare id distinction, compareUrl, localStorage persistence, corrupt localStorage recovery.

### Next
Still need: `useGeolocation.spec.ts`, `useRestaurantDisplay.spec.ts`, `useCardGallery.spec.ts`, `useIsMobile.spec.ts`, `useKeyboardOffset.spec.ts` among composables. Among components: `CardGallery`, `PopularRestaurants`, `HeroBanner`, `SearchMap`, `DetailMap`, `BlogEditor`, `Modal`, `Dropdown`, `RestaurantCardSkeleton`.

### Gotchas
- Tests live in `resources/js/Components/__tests__/`; run individually with `npx vitest run <file>`.
- Components that directly `import` from `@inertiajs/vue3` (e.g. `Head`, `Link` as named imports in `<script setup>`) need `vi.mock('@inertiajs/vue3', ...)` at module level, NOT just `global.stubs`. Inertia's internals reference the plugin context (`createProvider`) at setup time and will throw `TypeError: Cannot read properties of undefined (reading 'createProvider')` if not mocked at module level.
- For components that use `Link` as a resolved global component (no explicit import), a `global.stubs: { Link: { template: '<a><slot /></a>' } }` is sufficient.
- `$page.props.auth` is injected dynamically: `global.$page = { props: { auth: { user } } }`. Set `user` to an object to render the Favorites link, `null` for guests.
- Stub complex children in presentational parents: for `HeroSearch` stub `Button`, `CuisinePicker`, `LocationPicker`, `BrandLogo` so assertions stay focused on the wrapper's own renders/emits.
- The stub `Button` must forward `disabled` (`<button :disabled="disabled"><slot /></button>`) for the detecting-state test to assert the disabled attribute.
- `vi.mock('@/composables/useIsMobile')` with `ref(false)` → desktop Popover path; shadcn `Popover: true` does NOT render default slots — use `{ template: '<div><slot /></div>' }` for slot-passing stubs.
- To make mock behavior overridable per-test (e.g., toggling `isFavorited` from false to true), use a `let` binding in the mock factory and reassign it per-test, resetting in `beforeEach`. The `vi.mock()` call is hoisted, so the `let` variable must be declared before the mock and referenced by the factory's closure.
- Components that use named `<slot name="overlays">` inside a child component need that child stubbed with `<slot name="overlays" />` to pass through the slot content for assertion.
- CommandItem stub must emit `select` on click (`@click="$emit('select')"`) for `@select="handler(cat)"` bindings to fire.
- Debounced async searches need `vi.useFakeTimers()` + `vi.advanceTimersByTimeAsync(300)` (not `advanceTimersByTime` — the async variant flushes microtasks that the resolved API promise schedules).
- Dynamic `import('@/lib/api')` inside a component method resolves from the same `vi.mock('@/lib/api', ...)` as static imports.
- Composables with module-level reactive state (e.g., `const compareIds = ref<number[]>(...)` outside the exported function) share state across all callers. To get a clean state per test, use `vi.resetModules()` + `await import()` in each test, with `localStorage.clear()` before module init so `loadIds()` returns `[]`.

## Log
