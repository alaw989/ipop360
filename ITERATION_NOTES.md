# Iteration Notes

## Goal
add section background rhythm, a homepage stats band, and cuisine/category pills while preserving the Yelp-style design

## State
Done so far:
- Cuisine pills (PopularCuisines.vue): converted the plain text cuisine links into
  pill-shaped chips (`rounded-full border bg-card`, flex-wrap layout). Added a
  vitest assertion (`PopularCuisines.spec.ts`) that each cuisine link is a pill
  (`rounded-full` + `border`). Full frontend suite (1005 tests) + `vue-tsc` pass.
- Category pills (CategoryGrid.vue): converted the card tiles into pill-shaped
  chips matching the cuisine pills (`rounded-full border bg-card`, flex-wrap,
  `v-if` on the icon span so null icons render nothing). Updated the skeleton
  markup to pill-shaped and tagged items with `data-testid="category-skeleton"`,
  then rewrote the two skeleton assertions (previously `[class*="grid"] > div`
  and `[class*="flex"]` counts) to use the testid, and added a "category links
  are pill-shaped" assertion. Full frontend suite now 1006 tests, all green.

Next:
- Section background rhythm — alternate section backgrounds (muted/white) across
  CategoryGrid / PopularCuisines / PopularRestaurants / BlogPreview.
- Homepage stats band — new stats strip (e.g. restaurants/cuisines/cities counts);
  needs HomeController data + Welcome wiring + frontend/backend tests.

Gotchas:
- PopularCuisines skeleton/empty/href tests rely on `<a>` counts and `button` for
  "Show more" — keep those selectors stable when touching the template.
- CategoryGrid skeleton tests now key off `[data-testid="category-skeleton"]` (8
  items) and assert pills via `rounded-full` + `border` on each `<a>`. Icon span
  is `v-if`-gated so `icon: null` renders no emoji (no "null" text leak).

## Log
- Iteration 1: PopularCuisines cuisine links → pill chips + spec coverage (test-first).
- Iteration 2: CategoryGrid card tiles → pill chips + spec coverage (test-first).
