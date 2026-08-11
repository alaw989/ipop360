# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 2: Enhanced the hero post layout with a dark overlay gradient for text readability. The hero card now renders as a full-bleed image (21:9 aspect on sm+, 16:9 on mobile) with the text content overlaid at the bottom. A `bg-gradient-to-t from-black/70 via-black/30 to-transparent` overlay ensures white text remains legible on any image. The side-by-side grid layout (image left, text right) was replaced with this magazine-style overlay. When no featured image is present, the gradient placeholder fills the hero area and the "No image" label is kept for screen readers only (`sr-only`). All 941 tests pass; `npm run build` passes.

### What's next
- The hero card no-image placeholder could use a default food/blog illustration or pattern instead of just a gradient.
- Consider adding category/tag badges on hero post cards for visual richness.

## Log
1. Rewrote `BlogPreview.vue` hero section: overlay layout with `absolute inset-0` image, gradient overlay, and white text positioned at bottom via `absolute inset-x-0 bottom-0`. aspect-video on mobile, sm:aspect-[21/9] for wider screens.
2. Kept "No image" label via `sr-only` for accessibility. All 16 existing BlogPreview tests pass without modification.
3. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
