# Iteration Notes

## Goal
add page-level vitest specs for the remaining pages: Leaderboard, Blog Index, Blog Show, Compare Index, Cuisine Subcategories, Dashboard, and the Admin pages

## State
**Done**: Created `resources/js/Pages/__tests__/Dashboard.spec.ts` (3 tests) — renders heading, logged-in message, and header slot. All 568 tests pass.
**Next**: Pick next simplest page — Cuisine/Subcategories (low complexity) or Blog/Index.
**Gotchas**: `Head` from `@inertiajs/vue3` must be explicitly stubbed in the `vi.mock()` factory (not just in the `stubs` object) because the real component requires Inertia's `createProvider` plugin context.

## Log
1. Created Dashboard.spec.ts — 3 tests, all passing.
