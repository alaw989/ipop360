# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 7: Added `category` field to the admin blog post form (Admin/Blog/Edit.vue). The BlogPost interface now includes `category: string | null` and the form data includes `category` (defaulting to `props.post?.category ?? ''`). A text input field was added between excerpt and body, labeled "Category (optional)" with placeholder "e.g. News, Guide, Review" and `maxlength="100"`. Backend: BlogPostController::validated() now accepts `category` as `['nullable', 'string', 'max:100']` on both create and update. Tests: 4 new BlogAdminTest cases cover category set on create, nullable on create, updated on edit, and max-length validation enforced. All 26 BlogAdminTest tests + all backend tests pass; npm run build clean.

### What's next
- The category field is now fully functional end-to-end but uses a free-text input. A future enhancement could make it a select/datalist with predefined options (e.g., News, Guide, Review, Announcement) while still allowing free-form input.

## Log
1. Rewrote `BlogPreview.vue` hero section: overlay layout with `absolute inset-0` image, gradient overlay, and white text positioned at bottom via `absolute inset-x-0 bottom-0`. aspect-video on mobile, sm:aspect-[21/9] for wider screens.
2. Kept "No image" label via `sr-only` for accessibility. All 16 existing BlogPreview tests pass without modification.
3. Replaced gradient-only no-image placeholder with `PenLine` icon + decorative text-line bars on a muted gradient background. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
4. Added grid post decorative placeholder (scaled-down PenLine icon + text line bars on muted gradient). Made the `.aspect-video` wrapper always render (not conditional on having an image), keeping layout rhythm consistent. Added test for grid placeholder presence. Verified: `npm run test` (942 passed).
5. Added author bylines on hero and grid cards: HomeController eager-loads `with('author:id,name')`, BlogPreview shows `User` icon + name after date (separated by `·` dot). Optional `author` field, conditionally rendered. 4 new vitest tests. All 946 frontend + 36 backend tests pass.
6. Added category badge on hero and grid post cards: new nullable `category` column on blog_posts, selected in HomeController query, rendered as a Tag-icon pill badge above metadata on both hero (white translucent) and grid (primary-toned) cards. Welcome.vue interface updated for type compatibility. 4 new vitest tests. All 950 frontend tests + all blog backend tests pass; `npm run build` clean.
7. Added category field to admin blog create/edit form (Admin/Blog/Edit.vue): BlogPost interface includes `category: string | null`, form data includes `category`, text input between excerpt and body. Backend BlogPostController::validated() accepts `category` as nullable string max:100. 4 new BlogAdminTest cases cover create, nullable, update, and max-length validation. All 26 blog admin tests pass; backend test suite and `npm run build` clean.
