# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 3: Replaced the flat gradient no-image placeholder in the hero card with a richer decorative SVG. A large `PenLine` lucide icon sits centered on a subtle gradient (`from-muted/50 via-muted/30 to-muted/10`), with three horizontal "text lines" (full, 3/4, 1/2 width rounded bars) appearing below the icon on `sm+` screens. The icon + lines group renders at 20% opacity so it's decorative without competing with the overlay text. The `sr-only` "No image" label is preserved for screen readers. All 941 tests pass; `npm run build` passes.

### What's next
- The grid posts (posts 2+) also lack a placeholder when `featured_image` is null — they simply omit the image area. A similar decorative placeholder there would keep visual rhythm.
- Consider adding category/tag badges on hero post cards for visual richness (requires DB migration + model changes).

## Log
1. Rewrote `BlogPreview.vue` hero section: overlay layout with `absolute inset-0` image, gradient overlay, and white text positioned at bottom via `absolute inset-x-0 bottom-0`. aspect-video on mobile, sm:aspect-[21/9] for wider screens.
2. Kept "No image" label via `sr-only` for accessibility. All 16 existing BlogPreview tests pass without modification.
3. Replaced gradient-only no-image placeholder with `PenLine` icon + decorative text-line bars on a muted gradient background. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
