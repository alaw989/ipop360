# Iteration Notes

## Goal
upgrade the blog index into an archive with date grouping, category filter, and search

## State
- **Done (iteration 1)**: Date grouping — BlogIndex.vue now groups posts by month/year ("August 2026", "July 2026", etc.) with `<section>` per group and `<h2>` month header. Posts from the same month merge into a single grid. 3 new vitest tests cover grouping.
- **Done (iteration 2)**: Category filter — `BlogController::index()` accepts `?category=` query param (case-insensitive, `LOWER()` match), queries distinct non-null categories sorted by count desc then alpha, passes `categories` and `filters` props to Inertia. BlogIndex.vue renders a `<nav>` of pill buttons (All + one per category) with active highlight styling. `withQueryString()` preserves the category param across pagination. 4 new PHPUnit tests + 6 new vitest tests.
- **Next**: Search — add a search input to the blog index that filters posts by title/excerpt. Needs backend: search param on BlogController index, probably `LIKE` matching on title + excerpt.
- **Gotchas**: Category filter uses `whereRaw('LOWER(category) = ?')` for SQLite/MySQL compatible case-insensitive matching. The `"All"` pill links to `/blog` (no query params), resetting both category filter and pagination page. Categories list query is separate from the main posts query (not affected by the category filter).

## Log
- Iteration 1: Added date grouping to blog index. Frontend-only change using `computed` to group `posts.data` by `published_at` month/year. Tests: 958 pass.
- Iteration 2: Added category filter. Backend: `?category=` query param with case-insensitive filtering, distinct categories list. Frontend: pill button nav with active state. Tests: 650 PHPUnit (4 new) + 18 vitest (6 new).
