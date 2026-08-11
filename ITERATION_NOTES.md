# Iteration Notes

## Goal
upgrade the blog index into an archive with date grouping, category filter, and search

## State
- **Done (iteration 1)**: Date grouping — BlogIndex.vue now groups posts by month/year ("August 2026", "July 2026", etc.) with `<section>` per group and `<h2>` month header. Posts from the same month merge into a single grid. 3 new vitest tests cover grouping.
- **Next**: Category filter — add a category filter bar (pill buttons for each category) above the post grid. Needs backend: distinct categories query + filter param on BlogController index.
- **Gotchas**: The groupedPosts computed skips posts with null `published_at` (shouldn't happen for published posts, but safe). Pagination still works per-page — each page shows whatever 12 posts it gets, grouped by month.

## Log
- Iteration 1: Added date grouping to blog index. Frontend-only change using `computed` to group `posts.data` by `published_at` month/year. Tests: 958 pass.
