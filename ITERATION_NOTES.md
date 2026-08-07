# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/SeoMeta.spec.ts` (10 tests) covering: title rendering, meta description, canonical link, all 7 og meta tags (og:title, og:description, og:type, og:url, og:site_name, og:image, og:image:alt), all 4 twitter meta tags (twitter:card, twitter:title, twitter:description, twitter:image), noindex meta when `noindex: true`, absence of robots meta when noindex is false/absent, ComputedRef prop input, and plain object prop input.
  - Mocked `@inertiajs/vue3` at module level with `vi.mock()` providing `Head: { template: '<div><slot /></div>' }` — needed because `Head` internals reference Inertia's plugin context (`createProvider`) at setup time, and a `global.stubs` approach resolves too late.
  - The `ComputedRef` test passes an actual `computed(() => seoData)` as the prop, exercising the component's unwrap logic.

Verification: `npx vitest run resources/js/Components/__tests__/SeoMeta.spec.ts` → 1 file / 10 tests pass. Full suite: 200 tests / 27 files pass.

### Next
Continue adding tests for remaining untested Components: `RestaurantCard`, `ResultsGrid`, `SearchResultCard`, `SearchFilters`. `SearchResultCard` and `RestaurantCard` require mocking `@inertiajs/vue3` (`usePage`/`router`), `useFavorites`, `useCompare`, and `CardGallery`.

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
