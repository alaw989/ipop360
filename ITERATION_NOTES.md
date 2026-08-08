# Iteration Notes

## Goal
increase vitest coverage of the page components (Welcome, Restaurants/Index, Restaurants/Show, favorites)

## State
- Added `resources/js/Pages/__tests__/Favorites.Index.spec.ts` — 6 tests covering empty state, restaurant card rendering, heading/links. All passing.
- Next: add tests for Restaurants/Index.vue (cuisine filtering, skeleton loading, empty result state).
- Gotchas: `useSeo` and `useBaseUrl` composables need manual mocking since they use `usePage()` / `window`. RestaurantCard should be stubbed to avoid deep dependency chains.
