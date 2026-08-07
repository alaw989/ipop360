# Iteration Notes

## Goal
increase frontend test coverage with vitest

## State

### Changed this iteration
- Added `resources/js/Components/__tests__/HeroSearch.spec.ts` (6 tests) covering: brand logo + hero heading render, nested `CuisinePicker`/`LocationPicker` stub render, "Search" button label by default, "Detecting location..." label + disabled button when `detectingLocation`, `search` emit on logo link click, `search` emit on Search button click.

Verification: `npx vitest run resources/js/Components/__tests__/HeroSearch.spec.ts` → 1 file / 6 tests pass.

### Next
Continue adding tests for the other untested Components: `RestaurantCard`, `ResultsGrid`, `SearchResultCard`, `SearchFilters`, `SeoMeta`, `CuisinePicker`, `LocationPicker`. `CuisinePicker` is the next easiest standalone (it uses popover/sheet/command + `useIsMobile` — needs an `isMobile` return mock and button-click driven open/select assertions). `SearchResultCard` and `RestaurantCard` are hardest (need mocking of `@inertiajs/vue3` `usePage`/`router`, `useFavorites`, `useCompare`, `CardGallery`).

### Gotchas
- Tests live in `resources/js/Components/__tests__/`; run individually with `npx vitest run <file>`.
- Components using `@inertiajs/vue3` `Link` must stub it: `Link: { template: '<a><slot /></a>' }`. Because the stub declares no `emits`, `@click.prevent` fallthrough reaches the `<a>`, so logo-link clicks still fire component handlers.
- `$page.props.auth` is injected dynamically: `global.$page = { props: { auth: { user } } }`. Set `user` to an object to render the Favorites link, `null` for guests.
- Stub complex children in presentational parents: for `HeroSearch` stub `Button`, `CuisinePicker`, `LocationPicker`, `BrandLogo` so assertions stay focused on the wrapper's own renders/emits.
- The stub `Button` must forward `disabled` (`<button :disabled="disabled"><slot /></button>`) for the detecting-state test to assert the disabled attribute.
- The component shows city via `location.city || location.state || 'Everywhere'`.

## Log
