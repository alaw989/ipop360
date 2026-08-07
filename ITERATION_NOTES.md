# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/RestaurantCard.spec.ts` (61 tests) covering all rendering branches: basic rendering (name, address, city/state fallback, article), rank badge (fire emoji for #1, #N for others), rank change indicator (ArrowUp/Down/Minus with title Up/Down/Steady), ScoreChip visibility, award badge, StarRating, price range, distance formatting, description rendering, cuisine badges, action pills (Directions with trackDirections call, Call with callPhone, Website with openWebsite), favorites heart (aria-label Save/Saved, toggle, text-red-500 class), compare button (Add/Remove to comparison, toggleCompare call), name overlay link (internal vs external _blank), stagger animation (card-enter class when stagger && rank <= 12), and Directions link href/target/rel.
  - Same module-level mocks as SearchResultCard: `@inertiajs/vue3`, `@lucide/vue`, `@/lib/restaurant`, `@/composables/useFavorites`, `@/composables/useRestaurantDisplay`
  - **New mock**: `@/composables/useCompare` with `isInCompare` (overridable via let binding) and `toggleCompare` as `vi.fn()` — needed because RestaurantCard renders a compare button in the CardGallery overlays slot
  - **New stub**: `CardGallery` with `#overlays` slot passthrough (`<div class="card-gallery-stub"><slot name="overlays" /></div>`) — required because RestaurantCard nests rank badge, ScoreChip, compare button, and heart button inside CardGallery's overlays slot
  - Added `getRestaurantPhotos` to the useRestaurantDisplay mock (returns `photos` array or `[photo_url]`) — used by RestaurantCard's `<CardGallery :photos>` prop
  - Overridable `mockIsFavorited` / `mockIsInCompare` via `let` bindings that `beforeEach` resets — enables testing both favorited/not-favorited and in-compare/not-in-compare states from the same describe block
  - Helper `makeRestaurant()` and `mountCard()` follow the same pattern as SearchResultCard

Verification: `npx vitest run resources/js/Components/__tests__/RestaurantCard.spec.ts` → 1 file / 61 tests pass. Full suite: 31 files / 354 tests pass.

### Next
Add tests for `ResultsGrid` (already exists with 220 lines — check gaps), or pick up another untested component (HeroSearch, CuisinePicker, StickySearchBar already have tests). Good targets: `RestaurantDetailPanel`, `ComparePanel`, or composables like `useFavorites.spec.ts`, `useCompare.spec.ts`.

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

## Log
