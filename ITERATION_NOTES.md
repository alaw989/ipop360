# Iteration Notes

## Goal
upgrade the blog index into an archive with date grouping, category filter, and search

## State
- **Done (iteration 1)**: Date grouping — BlogIndex.vue now groups posts by month/year ("August 2026", "July 2026", etc.) with `<section>` per group and `<h2>` month header. Posts from the same month merge into a single grid. 3 new vitest tests cover grouping.
- **Done (iteration 2)**: Category filter — `BlogController::index()` accepts `?category=` query param (case-insensitive, `LOWER()` match), queries distinct non-null categories sorted by count desc then alpha, passes `categories` and `filters` props to Inertia. BlogIndex.vue renders a `<nav>` of pill buttons (All + one per category) with active highlight styling. `withQueryString()` preserves the category param across pagination. 4 new PHPUnit tests + 6 new vitest tests.
- **Done (iteration 3)**: Search — `BlogController::index()` accepts `?search=` query param, filters posts with `LOWER(title) LIKE` / `LOWER(excerpt) LIKE` (case-insensitive). BlogIndex.vue renders a `<form method="get">` with a search input (Search icon, placeholder) and a hidden input preserving the active `category` filter. Empty state differentiates "No posts match your search" from "No articles yet". New PHPUnit tests: search by title, search by excerpt, combined search+category filter, search passed to view. New vitest tests: search input renders, hidden category input, omitted hidden input when no category, search term shown in input, empty search message, generic empty state.
- **Gotchas**: Search uses `whereRaw('LOWER(col) like ?')` for SQLite/MySQL compatible case-insensitive matching. The `<form method="get" action="/blog">` pattern preserves the current `category` filter via hidden input so both filters can stack. Inertia intercepts form GET natively.

## Log
- Iteration 1: Added date grouping to blog index. Frontend-only change using `computed` to group `posts.data` by `published_at` month/year. Tests: 958 pass.
- Iteration 2: Added category filter. Backend: `?category=` query param with case-insensitive filtering, distinct categories list. Frontend: pill button nav with active state. Tests: 650 PHPUnit (4 new) + 18 vitest (6 new).
- Iteration 3: Added search. Backend: `?search=` query param with case-insensitive `LOWER() LIKE` on title + excerpt. Frontend: search form with hidden category input. Tests: 654 PHPUnit (4 new) + 24 vitest (6 new).
