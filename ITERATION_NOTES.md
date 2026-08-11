# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 4: Added decorative SVG placeholders to grid post cards when `featured_image` is null. Each grid card now shows a smaller `PenLine` icon (h-10 w-10) centered on the same muted gradient background (`from-muted/50 via-muted/30 to-muted/10`) with two shorter "text line" bars (full, 2/3 width) at 20% opacity on sm+ screens. An `sr-only` "No image" label is preserved for accessibility. The `<div>` wrapper around the image/placeholder is now always present (no longer conditionally rendered), keeping the aspect-video box consistent. All 942 tests pass; `npm run build` pending.

### What's next
- Consider adding category/tag badges on hero and grid post cards for visual richness (requires DB migration + model changes, plus a backend endpoint that returns category/tag data for posts).

## Log
1. Rewrote `BlogPreview.vue` hero section: overlay layout with `absolute inset-0` image, gradient overlay, and white text positioned at bottom via `absolute inset-x-0 bottom-0`. aspect-video on mobile, sm:aspect-[21/9] for wider screens.
2. Kept "No image" label via `sr-only` for accessibility. All 16 existing BlogPreview tests pass without modification.
3. Replaced gradient-only no-image placeholder with `PenLine` icon + decorative text-line bars on a muted gradient background. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
4. Added grid post decorative placeholder (scaled-down PenLine icon + text line bars on muted gradient). Made the `.aspect-video` wrapper always render (not conditional on having an image), keeping layout rhythm consistent. Added test for grid placeholder presence. Verified: `npm run test` (942 passed).
