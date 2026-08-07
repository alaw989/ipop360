# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/SearchResultCard.spec.ts` (49 tests) covering all rendering branches of the presentational card: basic rendering (name, address, city/state), rank badge (#1 fire emoji vs #N), photo (img vs gradient fallback), award badge, StarRating display, price range, distance formatting, review snippet (truncation at 120 chars + Read more link), cuisine badges with search link, action pills (Directions with mapCoords call tracking, Call with callPhone, Website with openWebsite), favorites heart toggle and aria-label, rank change indicator (ArrowUp/Down/Minus with title Up/Down/Steady), ScoreChip visibility, link targets (internal for id>0 vs external _blank for id<=0), and overlay link wrapping the name.
  - Mocked `@inertiajs/vue3` at module level for `Link` (named import via `<script setup>`)
  - Mocked `@lucide/vue` at module level: `Heart`, `Navigation`, `Phone`, `Globe`, `ArrowUp`, `ArrowDown`, `Minus`
  - Mocked `@/composables/useFavorites` with `isFavorited` returning false and `toggle` as a vi.fn()
  - Mocked `@/composables/useRestaurantDisplay` with `getDetailUrl`, `getDisplayRating`, `getMapCoords`, `getRankStyle`, `getRestaurantGradient` — uses actual logic equivalents to test real branching
  - Mocked `@/lib/restaurant` with `callPhone`, `openWebsite`, `trackDirections` as vi.fn() singletons
  - Stubbed child components: `StarRating` (passes rating as text for assertion), `ScoreChip` (passes total as data attr), `Badge` (passes variant as text)
  - Helper `makeRestaurant()` provides sensible defaults; `mountCard()` merges restaurant data + extra props

Verification: `npx vitest run resources/js/Components/__tests__/SearchResultCard.spec.ts` → 1 file / 49 tests pass.

### Next
Add tests for `RestaurantCard` — the heaviest dependency graph (uses `useRestaurantDisplay`, `useCompare`, `@/lib/restaurant`, `StarRating`, `ScoreChip`, `useFavorites`). Will need the most stubbing.

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
