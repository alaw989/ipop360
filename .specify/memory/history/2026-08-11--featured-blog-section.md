# 2026-08-11 — Featured blog section on the homepage (PR #84)

**Date:** 2026-08-11 · **Branch:** `feat/featured-blog-section` · **Status:** COMPLETE

## What shipped (opencode-loop, 15 iterations, ALL_DONE on iter 15)
Backlog goal #1: the homepage's subtle `BlogPreview` became a proper featured
section. Deployed + live-verified behaviorally, CI green before merge.

- **Hero + grid** — `BlogPreview.vue` rewritten: the latest post renders as a
  magazine-style hero (full-bleed 21:9 image on sm+, gradient overlay, white text,
  excerpt, "Read more"); remaining posts in a 2-column grid. Section hides entirely
  when there are no posts (`v-if="posts.length > 0"`).
- **`is_featured`** — column + admin checkbox in `Admin/Blog/Edit.vue` + amber
  "Featured" badge on hero/grid cards; featured posts sort first in the homepage query.
- **`category`** — nullable column + admin field + pill badge on cards.
- **Author bylines** — `HomeController` eager-loads `author:id,name`.
- **HomeController** — homepage data includes latest posts (featured-first, limit 3);
  `/api/homepage-data` trimmed of the unused `latestPosts` payload.
- **Tests** — PHPUnit 631 → 646 (+15: 7 `HomeControllerTest` homepage-blog cases +
  featured validation/prioritization); vitest 937 → 956 (+19: hero/grid badges,
  placeholders, author, category). pint + phpstan level 8 + build clean.

## Process notes (first opencode-loop backlog run)
- Loop ran in **legacy single-branch mode** — it had already been started before
  `--pr` existed. 15/15 iterations accepted, ALL_DONE on iter 15.
- **Post-loop hand-fixes:** (1) PHPStan level 8 flagged 3 `strpos` on
  `string|false` in the new `BlogPublicTest::test_homepage_prioritizes_featured_posts`
  — added `assertIsString($html)`. (2) The loop agent committed a stray
  `blog-mobile-375.png` (a mobile screenshot) into the repo root — removed it.
- **Gotcha:** I committed the docs update (`--pr` documentation) onto the loop's
  feature branch mid-run by mistake; cherry-picked it onto master via a git
  worktree (`d7beec1`) so master had it immediately, then rebased the feature
  branch (`rebase --onto` iterate-8 f738634) to drop the duplicate before the PR.
  Lesson: **commit docs on master, never while a loop owns the branch.**

## Verification (live)
- `/` + `/api/restaurants` both 200 after deploy.
- Temp-published the prod draft post + marked it featured → hero rendered with
  "Featured" badge, author byline, "Read more" CTA, "No image" gradient placeholder.
  Reverted the post to draft/unpublished after — section correctly disappears when
  no published posts exist.

## Follow-ups
- Next backlog goal is now #1: **blog archive page (upgrade /blog index)**.
- Future backlog runs use `opencode-loop --pr` (per-iteration PR lifecycle) — the
  new recipe documented in `backlog.md` + `AGENTS.md`.
