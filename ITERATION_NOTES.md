# Iteration Notes

## Goal
add page-level vitest specs for the remaining pages: Leaderboard, Blog Index, Blog Show, Compare Index, Cuisine Subcategories, Dashboard, and the Admin pages

## State
**Done**: Created `Dashboard.spec.ts` (3 tests) and `Subcategories.spec.ts` (8 tests). All 576 tests pass.
**Next**: Blog/Index (medium complexity, has pagination + empty state) or Leaderboard/Index (medium).
**Gotchas**: `Head` from `@inertiajs/vue3` must be explicitly stubbed in the `vi.mock()` factory (not just in the `stubs` object). When mocking `router.visit` via `vi.mock`, the spy must be created in `vi.hoisted()` to avoid hoisting order issues.

## Log
1. Created Dashboard.spec.ts — 3 tests, all passing.
2. Created Subcategories.spec.ts — 8 tests covering heading, icon, back link, conditional description, cuisine card rendering, navigation with and without coords.
