# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 1: Redesigned `BlogPreview.vue` from a flat 3-column card grid to a hero-post-plus-grid layout. The most recent post is now displayed as a prominent hero card (full-width on mobile, 3/2 split on md+ with larger image, title, excerpt, and "Read more" CTA). Remaining posts (up to 2) render in a 2-column grid below the hero. Hero posts without a featured image get a gradient placeholder. All 16 existing + new tests pass; `npm run build` passes (vue-tsc + client + SSR).

### What's next
- The hero card currently uses a simple gradient placeholder when no `featured_image` is set. Could add a default food/blog illustration or pattern.
- Consider adding category/tag badges on hero post cards for visual richness.
- The hero post layout could be enhanced with an overlay gradient on the image for better text readability.

## Log
1. Rewrote `BlogPreview.vue`: hero post (first in array) with `md:grid-cols-5` split layout, gradient placeholder for missing images, "Read more" arrow link. Grid posts (posts.slice(1)) in `sm:grid-cols-2` below. Section header and "View all" link unchanged.
2. Updated `BlogPreview.spec.ts`: 5 new tests for hero-specific behavior (hero position, "Read more", gradient placeholder, single-post no grid, grid post image count). All 16 tests pass.
3. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
