# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/LocationPicker.spec.ts` (13 tests) covering: default "your city" trigger text, city-only display, "city, state" display, "Detecting..." with spinner, "Type to search cities" / "Use my current location" prompt, detect emit on "Use my location" click, "No cities found" empty state, debounced API search renders result buttons with city/state/display, selecting a result emits `update` and `coords`, null display field renders cleanly, spinner visible while search is in-flight, inverted prop applies border/text color classes, detecting prop adds animate-pulse class.
  - Mocked `useIsMobile` → `ref(false)` for desktop Popover path; mocked `useKeyboardOffset` → `keyboardHeight: ref(0)`
  - Mocked `@/lib/api` `get` function to return fixture results; dynamic `import('@/lib/api')` resolves to same mock
  - Stubbed Popover/PopoverTrigger/PopoverContent/Sheet/SheetTrigger/SheetContent as slot-passing wrappers
  - Used `vi.useFakeTimers()` + `vi.advanceTimersByTimeAsync(300)` to test debounced search; `setValue` on raw `<input>` triggers `v-model` via native input event
  - `Input` and `Button` imports in LocationPicker are unused (raw `<input>` / `<button>` elements in template), so no stubs needed

Verification: `npx vitest run resources/js/Components/__tests__/LocationPicker.spec.ts` → 1 file / 13 tests pass. Full suite: 190 tests / 26 files pass.

### Next
Continue adding tests for remaining untested Components: `RestaurantCard`, `ResultsGrid`, `SearchResultCard`, `SearchFilters`, `SeoMeta`. `SeoMeta` is likely the easiest (pure presentational, just props + slot rendering). `SearchResultCard` and `RestaurantCard` require mocking `@inertiajs/vue3` `usePage`/`router`, `useFavorites`, `useCompare`, `CardGallery`.

### Gotchas
- Tests live in `resources/js/Components/__tests__/`; run individually with `npx vitest run <file>`.
- Components using `@inertiajs/vue3` `Link` must stub it: `Link: { template: '<a><slot /></a>' }`. Because the stub declares no `emits`, `@click.prevent` fallthrough reaches the `<a>`, so logo-link clicks still fire component handlers.
- `$page.props.auth` is injected dynamically: `global.$page = { props: { auth: { user } } }`. Set `user` to an object to render the Favorites link, `null` for guests.
- Stub complex children in presentational parents: for `HeroSearch` stub `Button`, `CuisinePicker`, `LocationPicker`, `BrandLogo` so assertions stay focused on the wrapper's own renders/emits.
- The stub `Button` must forward `disabled` (`<button :disabled="disabled"><slot /></button>`) for the detecting-state test to assert the disabled attribute.
- `vi.mock('@/composables/useIsMobile')` with `ref(false)` → desktop Popover path; shadcn `Popover: true` does NOT render default slots — use `{ template: '<div><slot /></div>' }` for slot-passing stubs.
- CommandItem stub must emit `select` on click (`@click="$emit('select')"`) for `@select="handler(cat)"` bindings to fire.
- Debounced async searches need `vi.useFakeTimers()` + `vi.advanceTimersByTimeAsync(300)` (not `advanceTimersByTime` — the async variant flushes microtasks that the resolved API promise schedules).
- Dynamic `import('@/lib/api')` inside a component method resolves from the same `vi.mock('@/lib/api', ...)` as static imports.

## Log
