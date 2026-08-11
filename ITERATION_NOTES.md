# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 6: Added `category` (nullable string) column to blog_posts via migration, included it in BlogPost model fillable and BlogPostFactory (optional). HomeController now selects `category` in the homepage blog query. BlogPreview.vue renders a category badge (pill with Tag icon) above the metadata line on both hero and grid cards: hero uses white-on-translucent styling (`bg-white/20 backdrop-blur-sm`), grid uses muted primary-toned styling (`bg-primary/10 text-primary`). The badge is conditionally rendered via `v-if="post.category"`. Welcome.vue's inline BlogPost interface was updated to include `category: string | null` to satisfy vue-tsc. Four new vitest tests cover category badge rendering on hero, grid, null category, and undefined category. All 950 frontend tests and all blog backend tests pass; npm run build is clean.

### What's next
- The category field is currently a free-text string (no validation/enum in the admin form). Consider adding it to the admin BlogPost create/edit form (Admin/Blog/Edit.vue) so authors can set categories.

## Log
1. Rewrote `BlogPreview.vue` hero section: overlay layout with `absolute inset-0` image, gradient overlay, and white text positioned at bottom via `absolute inset-x-0 bottom-0`. aspect-video on mobile, sm:aspect-[21/9] for wider screens.
2. Kept "No image" label via `sr-only` for accessibility. All 16 existing BlogPreview tests pass without modification.
3. Replaced gradient-only no-image placeholder with `PenLine` icon + decorative text-line bars on a muted gradient background. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
4. Added grid post decorative placeholder (scaled-down PenLine icon + text line bars on muted gradient). Made the `.aspect-video` wrapper always render (not conditional on having an image), keeping layout rhythm consistent. Added test for grid placeholder presence. Verified: `npm run test` (942 passed).
5. Added author bylines on hero and grid cards: HomeController eager-loads `with('author:id,name')`, BlogPreview shows `User` icon + name after date (separated by `·` dot). Optional `author` field, conditionally rendered. 4 new vitest tests. All 946 frontend + 36 backend tests pass.
6. Added category badge on hero and grid post cards: new nullable `category` column on blog_posts, selected in HomeController query, rendered as a Tag-icon pill badge above metadata on both hero (white translucent) and grid (primary-toned) cards. Welcome.vue interface updated for type compatibility. 4 new vitest tests. All 950 frontend tests + all blog backend tests pass; `npm run build` clean.
