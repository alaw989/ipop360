# Iteration Notes

## Goal
increase vitest coverage of the page components (Welcome, Restaurants/Index, Restaurants/Show, favorites)

## State
- Added `resources/js/Pages/__tests__/Favorites.Index.spec.ts` — 6 tests covering empty state, restaurant card rendering, heading/links. All passing.
- Added `resources/js/Pages/__tests__/Restaurants.Index.spec.ts` — 15 tests covering cuisine filtering (heading, back links), skeleton loading (show/hide 8 skeletons), empty state, results rendering with rank computation, sort dropdown, and pagination (prev/next, page count, hidden when single page). All passing.
- Next: add tests for Restaurants/Show.vue (restaurant detail, sections, score breakdown) or Welcome.vue (hero, featured restaurants, search CTA).
- Gotchas: `useSeo` and `useBaseUrl` composables need manual mocking since they use `usePage()` / `window`. RestaurantCard should be stubbed to avoid deep dependency chains. `vi.mock` factories cannot reference top-level variables — use `vi.hoisted()`. shadcn Button with `as="a"` renders native `<a>` in DOM; `&` in query strings is HTML-encoded to `&amp;`, so use `findAll('a').find()` with text matching rather than exact href selectors.
