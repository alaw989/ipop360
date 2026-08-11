# Iteration Notes

## Goal
build a featured blog section on the homepage (hero post + grid)

## State
Iteration 14: Added 7 backend feature tests for homepage blog post flow (HomeControllerTest). Covers: passes latestPosts with full structure (id, title, slug, excerpt, category, featured_image, published_at, is_featured, author name), excludes drafts, orders featured first, limits to 3, API endpoint includes latestPosts, handles zero posts (SSR + API). Also added `latestPosts` to existing API jsonStructure assertion. All 646 backend + 956 frontend tests pass; pint clean; `npm run build` passes.

### What's next
- Verify mobile responsiveness of the blog section visually (browser check). Then the featured blog section is complete.

## Log
1. Rewrote `BlogPreview.vue` hero section: overlay layout with `absolute inset-0` image, gradient overlay, and white text positioned at bottom via `absolute inset-x-0 bottom-0`. aspect-video on mobile, sm:aspect-[21/9] for wider screens.
2. Kept "No image" label via `sr-only` for accessibility. All 16 existing BlogPreview tests pass without modification.
3. Replaced gradient-only no-image placeholder with `PenLine` icon + decorative text-line bars on a muted gradient background. Verified: `npm run test` (941 passed), `npm run build` (client + SSR clean).
4. Added grid post decorative placeholder (scaled-down PenLine icon + text line bars on muted gradient). Made the `.aspect-video` wrapper always render (not conditional on having an image), keeping layout rhythm consistent. Added test for grid placeholder presence. Verified: `npm run test` (942 passed).
5. Added author bylines on hero and grid cards: HomeController eager-loads `with('author:id,name')`, BlogPreview shows `User` icon + name after date (separated by `·` dot). Optional `author` field, conditionally rendered. 4 new vitest tests. All 946 frontend + 36 backend tests pass.
6. Added category badge on hero and grid post cards: new nullable `category` column on blog_posts, selected in HomeController query, rendered as a Tag-icon pill badge above metadata on both hero (white translucent) and grid (primary-toned) cards. Welcome.vue interface updated for type compatibility. 4 new vitest tests. All 950 frontend tests + all blog backend tests pass; `npm run build` clean.
7. Added category field to admin blog create/edit form (Admin/Blog/Edit.vue): BlogPost interface includes `category: string | null`, form data includes `category`, text input between excerpt and body. Backend BlogPostController::validated() accepts `category` as nullable string max:100. 4 new BlogAdminTest cases cover create, nullable, update, and max-length validation. All 26 blog admin tests pass; backend test suite and `npm run build` clean.
8. Added `is_featured` boolean column (default false) via migration. BlogPost model: added to `$fillable`, `$casts` (boolean), and new `featured()` scope. BlogPostFactory: default `is_featured => false` + `featured()` state. All 635 backend + 950 frontend tests pass.
9. Added `'is_featured' => ['boolean']` to BlogPostController::validated(). Tests: defaults to false on create, can be set true on create, can be updated. All 29 BlogAdminTest pass.
10. HomeController: `->orderBy(is_featured, desc)->latest(published_at)` on blog query, added `is_featured` to columns + both BlogPost interfaces. `test_homepage_prioritizes_featured_posts` added. All 639 backend + 950 frontend tests pass.
11. Added `is_featured` checkbox to Admin/Blog/Edit.vue: BlogPost interface includes `is_featured: boolean`, form data defaults to `false`, checkbox rendered between category and body fields. All 29 BlogAdminTest pass; `npm run build` clean.
12. Added "Featured" badge column to Admin/Blog/Index.vue: BlogPost interface includes `is_featured`, table header + cell with Badge ("Featured" when true, em dash when false). 2 new vitest tests. All 952 frontend tests pass; `npm run build` clean.
13. Added "Featured" badge to BlogPreview.vue hero and grid cards. Hero: amber-400/90 filled Star + "Featured" text, amber-950 on translucent bg. Grid: amber-100 bg with amber-800 text. Both use `Star` icon with `fill-current`. Badge wraps in a flex div with category badge for side-by-side layout. 4 new vitest tests (hero shows/hides, grid shows/hides). All 956 frontend tests pass; `npm run build` clean.
14. Added 7 backend feature tests for homepage blog post flow (HomeControllerTest): passes latestPosts with full structure, excludes drafts, orders featured first, limits to 3, API endpoint includes latestPosts, handles zero posts (SSR + API). Updated existing API jsonStructure to include latestPosts. All 646 backend + 956 frontend tests pass; pint clean; `npm run build` clean.
