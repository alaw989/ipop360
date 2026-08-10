# Iteration Notes

## Goal
add page-level vitest specs for the remaining pages: Leaderboard, Blog Index, Blog Show, Compare Index, Cuisine Subcategories, Dashboard, and the Admin pages

## State
**Done**: `Dashboard.spec.ts` (3 tests), `Subcategories.spec.ts` (8 tests), `Blog.Index.spec.ts` (10 tests). All 586 tests pass.
**Next**: Blog/Show (medium-low, single post rendering) or Leaderboard/Index (medium).
**Gotchas**: `Head` from `@inertiajs/vue3` must be explicitly stubbed in the `vi.mock()` factory (not just in the `stubs` object). When mocking `router.visit` via `vi.mock`, the spy must be created in `vi.hoisted()` to avoid hoisting order issues. `Link` stub template must bind `:href="href"` for attribute assertions to work. Date tests using UTC midnight can cross the date line in local timezone — use noon UTC or later.

## Log
1. Created Dashboard.spec.ts — 3 tests, all passing.
2. Created Subcategories.spec.ts — 8 tests covering heading, icon, back link, conditional description, cuisine card rendering, navigation with and without coords.
3. Created Blog.Index.spec.ts — 10 tests: heading, subtitle, post cards, formatted dates, empty state, post slug links, pagination visibility (show when >3, hide when ≤3), disabled link as span.
