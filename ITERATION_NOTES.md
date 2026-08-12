# Iteration Notes

## Goal
add section background rhythm, a homepage stats band, and cuisine/category pills while preserving the Yelp-style design

## State
Done so far:
- Cuisine pills (PopularCuisines.vue): converted the plain text cuisine links into
  pill-shaped chips (`rounded-full border bg-card`, flex-wrap layout). Added a
  vitest assertion (`PopularCuisines.spec.ts`) that each cuisine link is a pill
  (`rounded-full` + `border`). Full frontend suite (1005 tests) + `vue-tsc` pass.

Next:
- Category pills (CategoryGrid.vue) — still renders card tiles, not pills; restyle
  to match the cuisine pills (or keep cards if Yelp-style wins).
- Section background rhythm — alternate section backgrounds (muted/white) across
  CategoryGrid / PopularCuisines / PopularRestaurants / BlogPreview.
- Homepage stats band — new stats strip (e.g. restaurants/cuisines/cities counts);
  needs HomeController data + Welcome wiring + frontend/backend tests.

Gotchas:
- PopularCuisines skeleton/empty/href tests rely on `<a>` counts and `button` for
  "Show more" — keep those selectors stable when touching the template.
- CategoryGrid skeleton tests assert `[class*="grid"] > div` and
  `[class*="flex"]` counts; changing its container to flex-wrap will break them.

## Log
- Iteration 1: PopularCuisines cuisine links → pill chips + spec coverage (test-first).
