# iPop360 — Backlog (opencode-loop goals)

> When the user says *"let's get to work on the next item in the backlog with the
> opencode-loop"*, run the **first unfinished item** below with the documented
> command. The loop lives at `~/.local/bin/opencode-loop` (globally installed).
>
> Loop recipe (proven across 5 shipped PRs):
> ```bash
> # create a feature branch first (loop refuses master/main/develop)
> git checkout master && git pull origin master && git checkout -b feat/<goal-slug>
> setsid nohup opencode-loop 20 --goal "<goal text>" \
>   --check "<gate>" --model opencode-go/deepseek-v4-pro \
#   > logs/opencode-loop-<slug>.out 2>&1 < /dev/null &
> ```
> Then monitor `logs/opencode-loop-<slug>.out` (tail + grep the emoji status
> lines). When done: run `pint`, `composer test`, `npm run build`, push, PR,
> merge, verify live — then mark the item ✅ here.

---

## ✅ Done (2026-08-07 session)
1. **Backend unit coverage** — PR #63: suite 475 → 563 (9 services covered).
2. **Frontend vitest coverage** — PR #64: 117 → 481 (25 component/composable specs).
3. **PHPStan level 6** — PR #65: raised 5→6, baseline 294 → 0.
4. **PHPStan level 7** — PR #66: raised 6→7, baseline 43 → 0.
5. **PHPStan over tests/** — PR #67: removed the tests/ exclusion, baseline 219 → 0.
   Installed `phpstan/phpstan-mockery`; established the `@var ServiceClass&\Mockery\MockInterface`
   pattern for constructor mocks.

**Current floor:** 563 PHPUnit tests + 481 vitest tests; PHPStan level 7 over `app/ + tests/`
with a **zero baseline**; pint clean; CI + deploy green on master.

## ✅ Done (2026-08-08 session)
6. **Frontend page-level vitest tests** — PR #69 (opencode-loop, 4 iterations + dedupe):
   Welcome 19 · Restaurants/Index 15 · Restaurants/Show 43 · Favorites/Index 7.
   vitest 481 → **565** (deduped a duplicate FavoritesIndex.spec.ts from iter 1).
   Deployed + live-verified (/, /api both 200).
7. **TypeScript stricter compiler flags** — PR #70 (opencode-loop, 8 iterations): all 11
   strict-adjacent flags enabled in `tsconfig.json` (`noUncheckedIndexedAccess`, `noImplicitReturns`,
   `noUnusedLocals`, `noUnusedParameters`, `noUncheckedSideEffectImports`, `exactOptionalPropertyTypes`,
   `strictPropertyInitialization`, `noPropertyAccessFromIndexSignature`, `noFallthroughCasesInSwitch`,
   `noImplicitOverride`) with zero type errors. Deployed + live-verified (/, /api both 200).

## ✅ Done (2026-08-10 session)
8. **PHPStan level 8** — PR #71 (opencode-loop, 16 iterations): `phpstan.neon` 7 → 8,
   baseline 345 lines / ~70 entries → **2 entries** (both documented PHPStan limitations in
   `RestaurantController`). Null-safety fixes across 6 services, 4 commands, 9 controllers,
   1 request, 15 test files. Deployed + live-verified (/, /api both 200).

**Current floor:** 563 PHPUnit tests + 565 vitest tests; PHPStan level 8 over `app/ + tests/`
with a 2-entry baseline; pint clean; CI + deploy green on master.

## ✅ Done (2026-08-10 session, continued)
9. **Dead-code / cruft sweep** — PR #72 (opencode-loop, 20 iterations): removed 4 dead
   artisan commands, ~35 unused shadcn-vue components (dialog/separator/textarea/sheet/
   popover/input-group), 3 npm packages, 13 dead env vars, dead mariadb/pgsql/sqlsrv/Redis
   DB connections, dead postmark/resend/slack services + mailers, Telescope pulse patterns,
   chart/sidebar CSS vars, stale Tailwind-3 utilities, 2 allow-plugins entries. 57 files,
   +50/−1642. Deployed + live-verified (/, /api 200, homepage renders).
10. **LiveSearchService.search() orchestration coverage** — PR #73 (opencode-loop, 5
    iterations, ALL_DONE early): 4 new tests in `tests/Unit/LiveSearchScoringTest.php`
    covering full orchestration (scored/sorted/bounded output, cuisine-match stamping +
    confidence filtering, multi-source merge/dedup, phone fast-path dedup). 567 PHPUnit
    tests (was 563). PR #74 fixed a post-merge PHPStan level 8 failure (`assertIsFloat`
    on a cast float). Deployed + live-verified (/, /api 200).

**Current floor:** 567 PHPUnit tests + 565 vitest tests; PHPStan level 8 over `app/ + tests/`
with a 2-entry baseline; pint clean; CI + deploy green on master.

## ✅ Done (2026-08-10 session, continued)
11. **PHPStan level 8: zero baseline** — PR #75: removed the last 2 baseline entries
    (redundant `assert($coords !== null)` in `RestaurantController::apiIndex`, already
    narrowed by the `if` condition) and deleted `phpstan-baseline.neon` entirely. Kept the
    `when()`-closure asserts (load-bearing) and the 18 regex `ignoreErrors` patterns in
    `phpstan.neon` (still needed for mockery/Model limitations). CI green before merge;
    deployed + live-verified (/, /api 200).

**Current floor:** 567 PHPUnit tests + 725 vitest tests; PHPStan level 8 over `app/ + tests/`
with a **zero baseline**; pint clean; CI + deploy green on master.

## ✅ Done (2026-08-10 session, continued)
13. **Page-level vitest for all remaining pages** — PR #77 (opencode-loop, 9 iterations,
    ALL_DONE early): 9 new specs (Leaderboard, Blog Index/Show, Compare, Cuisine
    Subcategories, Dashboard, Admin Dashboard, Admin Blog Index/Edit) — +2144 lines.
    vitest 565 → **725**. Every page in `resources/js/Pages/` now has a spec. CI green
    before merge; deployed + live-verified (/, /api 200).

## ✅ Done (2026-08-10 session, continued)
12. **CI actions → Node-24 majors** — PR #76: bumped `actions/checkout@v4` → `@v5`,
    `actions/setup-node@v4` → `@v5`, `gitleaks/gitleaks-action@v2` → `@v3` in both
    workflows. Eliminates the "Node.js 20 is deprecated" Actions annotation — quality +
    deploy now run with zero annotations. CI green before merge; deployed + live (200).

## ✅ Done (2026-08-10 session, continued)
14. **Scheduled-command test coverage** — PR #78 (opencode-loop, 7 iterations, ALL_DONE
    early): 7 new `tests/Feature/` specs — UpdateEngagement (4), GarbageCollectApiCache
    (5), GenerateSitemap (6), ScoreRestaurants (5), UptimeCanary (6), ScrapeRestaurantSocialLinks
    (11), VerifyRestaurantWebsites (12). PHPUnit 567 → **616** (+49). Also fixed a real Carbon 3
    signed `diffInHours()` bug in `UptimeCanary`. Post-loop caught 20 PHPStan level-8 errors
    (CI quality gate) in the new specs — fixed (PendingCommand union, `file_get_contents`
    `string|false`, `fresh()` null, iterable type). CI green; deployed + live-verified
    (/, /api both 200).

**Current floor:** 616 PHPUnit tests + 725 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; CI + deploy green on master.

## ✅ Done (2026-08-11 session)
15. **Complex component vitest specs** — PR #79 (opencode-loop, 9 iterations, ALL_DONE
    early): 9 new `resources/js/Components/__tests__/` specs — RestaurantCardSkeleton (7),
    BlogPreview (11), SearchMap (20), DetailMap (18), HeroBanner (18), PopularRestaurants
    (33), Modal (24), CardGallery (39), BlogEditor (33). vitest 725 → **895** (+170, 64 test
    files). CardGallery needed `window.matchMedia` + `IntersectionObserver` stubs in jsdom.
    CI green; deployed + live-verified (/, /api both 200).

**Current floor:** 616 PHPUnit tests + 895 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; CI + deploy green on master.

## ✅ Done (2026-08-11 session)
16. **CI coverage enforcement** — PR #80 (opencode-loop, 6 iterations, ALL_DONE early):
    PHPUnit coverage via pcov → `coverage/phpunit.xml` clover, enforced by
    `scripts/check-coverage.php` (stmts 50% / methods 45%; conditionals auto-skipped —
    pcov emits `conditionals="0"`). Vitest coverage via `@vitest/coverage-v8` with
    thresholds 70/65/60/70 in `vitest.config.ts`. Added `composer coverage` local script.
    Local verify: PHPUnit 76.27% stmts / 54.37% methods; vitest 72.61% stmts / 69.51%
    branches / 65.21% funcs / 73.05% lines. **Hand-fixes after the loop:** the loop's
    two-job split broke CI (phpunit job missing built manifest → 49 failures; vitest job
    missing composer `vendor/` → ziggy TS2307) — restored the single quality job. Also
    restored `checkout@v5`/`setup-node@v5` + node 22 + PHP 8.5 + `touch .env` + `npm run
    build` (loop had regressed them), and gitignored the committed `coverage/` artifacts.
    CI green; deployed + live-verified (/, /api both 200).

**Current floor:** 616 PHPUnit tests + 895 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; **CI enforces PHPUnit + vitest coverage thresholds**;
CI + deploy green on master.

## ✅ Done (2026-08-11 session)
17. **Align CI PHP with production** — PR #81 (opencode-loop, 3 iterations, ALL_DONE
    early): `php-version: '8.5'` → `'8.4'` in `ci.yml` + both steps of `deploy.yml`;
    corrected stale `AGENTS.md` stack line (`PHP 8.3` → `PHP 8.4`). Config-only change —
    local dev still runs 8.5, prod + CI now 8.4. CI green (both quality runs pass on 8.4);
    deployed + live-verified (/, /api both 200).

**Current floor:** 616 PHPUnit tests + 895 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; **CI enforces coverage thresholds and runs PHP 8.4
(matching prod)**; CI + deploy green on master.

## Next goals (in priority order)

### 1. User roles: admin / editor / user ⬅ NEXT
- `users` currently has a binary `is_admin` boolean. Replace it with a `role` enum column
  (`admin` / `editor` / `user`) and migrate existing `is_admin = true` rows → `admin`.
  Update `User::isAdmin()`, `EnsureUserIsAdmin` middleware (`admin` alias), the
  `HandleInertiaRequests` auth share, and the frontend `User` type in
  `resources/js/types/index.d.ts`. Unblocks goals 2 + 6.
- **Goal:** `replace the is_admin boolean with a role column (admin/editor/user) on users`
- **Gate:** `composer test`

### 2. Blog editor permissions
- Give `editor`-role users blog-writing ability: CRUD their **own** posts while admins
  manage all. Guard the `/admin/blog` resource routes by role, auto-set `author_id` on
  create, and scope drafts/published queries by editor ownership. Reuses the existing
  `Admin/Blog/Edit.vue` WYSIWYG editor. Depends on goal 1 (roles).
- **Goal:** `grant blog-writing permissions to editor-role users (CRUD own posts; admins manage all)`
- **Gate:** `composer test`

### 3. Featured blog section on homepage
- The homepage already has a subtle `BlogPreview` (latest 3 posts, "View all" → `/blog`).
  Replace it with a proper featured section: hero card for the latest post (featured
  image + excerpt) plus a grid of recent posts. Data added to `HomeController`
  (`getHomepageData()` + `/api/homepage-data`). Component: `resources/js/Components/BlogPreview.vue`,
  wired into `Pages/Welcome.vue`.
- **Goal:** `build a featured blog section on the homepage (hero post + grid)`
- **Gate:** `npm run build`

### 4. Blog archive page (upgrade /blog index)
- Add a `category` string column to `blog_posts` (+ factory + admin editor field in
  `Admin/Blog/Edit.vue`), then upgrade the public `/blog` (`Blog/Index.vue`) into a full
  archive: month/year grouping, category filter chips, and search. If categories are
  unwanted, scope can shrink to date grouping + search only.
- **Goal:** `upgrade the blog index into an archive with date grouping, category filter, and search`
- **Gate:** `composer test && npm run build`

### 5. Admin dashboard basic counts
- Surface clear counts on `Admin/Dashboard.vue`: total restaurants, cuisines, users, and
  blog posts — alongside the existing data-quality/SerpApi/scrape cards. Backed by
  `Admin/DashboardController` (`__invoke`).
- **Goal:** `add restaurant, cuisine, user, and blog post counts to the admin dashboard overview`
- **Gate:** `composer test`

### 6. Post-login admin landing + nav discoverability
- After login, redirect `admin`/`editor` users to `/admin` (dashboard) instead of the
  stub `Dashboard.vue` ("You're logged in!"), so editors land where the blog editor
  lives. Make the admin/blog links reachable from the admin nav after auth. Depends on
  goal 1 (roles).
- **Goal:** `redirect admin/editor users to the admin dashboard after login and make blog editing discoverable in nav`
- **Gate:** `composer test && npm run build`

### 7. Homepage nav + hero polish
- Adopt the `AppLayout` top nav (brand left, links right: Browse/Leaderboard/Blog +
  Favorites/Dashboard/Login, admin links when admin) on the homepage instead of the
  sparse hero-only links floating in the slideshow. Tighten the hero: `min-h-screen` →
  `min-h-[80vh]`, make the logo a home link. **No city quick-chips** (declined). Keeps
  the Yelp-style look — polish, not redesign.
- **Goal:** `adopt the AppLayout top nav on the homepage and tighten the hero banner while preserving the current Yelp-style design`
- **Gate:** `npm run build`

### 8. Homepage section rhythm + stats band
- Alternate section backgrounds (e.g. `bg-muted/40` bands) and consistent section
  headers (title, subtitle, "View all" CTA) across `CategoryGrid`, `PopularCuisines`,
  `PopularRestaurants`, `BlogPreview`. Add a slim stats/trust band under the hero
  ("X restaurants · Y cuisines · Z cities") from `HomeController` data. Upgrade the
  `PopularCuisines` text list → clickable pill chips with counts, and add per-category
  counts to `CategoryGrid`. Preserves Yelp-style look (gentle tone alternation + chips).
- **Goal:** `add section background rhythm, a homepage stats band, and cuisine/category pills while preserving the Yelp-style design`
- **Gate:** `npm run build`

### 9. Homepage scroll-reveal motion
- Scroll-reveal animations for homepage sections via IntersectionObserver (reuse
  `resources/css/transitions.css`), honoring `prefers-reduced-motion`. Verify no
  regressions in `resources/js/Pages/__tests__/Welcome.spec.ts` (19 cases) and no
  overflow/layout shift.
- **Goal:** `add prefers-reduced-motion-aware scroll-reveal animations to homepage sections`
- **Gate:** `npm run build`

---

## Getting started (for the next session)
- Repo conventions, commands, deploy: `AGENTS.md`.
- Project principles + loop protocol: `.specify/memory/constitution.md`.
- This file's history: `.specify/memory/history.md` + `.specify/memory/history/`.
- `ITERATION_NOTES.md` is the loop relay — the loop re-seeds it when the goal changes,
  so don't hand-edit it between goals.
