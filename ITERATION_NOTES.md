# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 5: Added author bylines to both hero and grid post cards. HomeController now eager-loads `author:id,name` on `latestPosts`, and author name appears inline after the date with a `User` icon, separated by a middle dot. The `author` field is optional in the `BlogPost` interface (backward-compatible); it's conditionally rendered via `v-if="post.author"`. Four new vitest tests cover author rendering on hero, grid, null author, and undefined author. 946 frontend tests and all 36 relevant backend tests pass.

### What's next
- Consider adding category/tag badges on hero and grid post cards for visual richness (requires DB migration + model changes, plus a backend endpoint that returns category/tag data for posts).

## Log
1. Rewrote `BlogPreview.vue` hero section: overlay layout with `absolute inset-0` image, gradient overlay, and white text positioned at bottom via `absolute inset-x-0 bottom-0`. aspect-video on mobile, sm:aspect-[21/9] for wider screens.
2. Kept "No image" label via `sr-only` for accessibility. All 16 existing BlogPreview tests pass without modification.
3. Replaced gradient-only no-image placeholder with `PenLine` icon + decorative text-line bars on a muted gradient background. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
4. Added grid post decorative placeholder (scaled-down PenLine icon + text line bars on muted gradient). Made the `.aspect-video` wrapper always render (not conditional on having an image), keeping layout rhythm consistent. Added test for grid placeholder presence. Verified: `npm run test` (942 passed).
5. Added author bylines on hero and grid cards: HomeController eager-loads `with('author:id,name')`, BlogPreview shows `User` icon + name after date (separated by `·` dot). Optional `author` field, conditionally rendered. 4 new vitest tests. All 946 frontend + 36 backend tests pass.
