# iPop360 — Backlog (opencode-loop goals)

> ⚠️ **Binding rule: backlog goals are ALWAYS executed via `opencode-loop` — never
> implemented directly.** This file is the loop's work queue. When work starts on
> this backlog, run the **first unfinished item** below through the loop on its own
> feature branch; finish with the documented post-loop steps, then mark the item ✅
> here.
>
> The loop lives at `~/.local/bin/opencode-loop` (globally installed).
>
> Loop recipe — **per-iteration PR mode** (`--pr`; each iteration ships as its own
> branch → PR → all-checks-pass → squash-merge, so master updates only via merged
> PRs). Run from master (the base branch); the loop creates the per-iteration
> branches itself:
> ```bash
> git checkout master && git pull origin master
> setsid nohup opencode-loop 20 --goal "<goal text>" \
>   --check "<gate>" --pr --model opencode-go/deepseek-v4-pro \
>   > logs/opencode-loop-<slug>.out 2>&1 < /dev/null &
> ```
> Legacy single-branch mode (one pre-created `feat/<goal-slug>` branch, one PR per
> goal, manual merge) still exists for interactive runs: create the branch, drop
> `--pr`, finish with a manual PR. `--pr` requires the `gh` CLI and is the default
> for backlog work.
>
> Then monitor `logs/opencode-loop-<slug>.out` (tail + grep the emoji status
> lines). In `--pr` mode the loop runs `pint`/`composer test`/`npm run build` via
> `--check` and waits for CI green on every PR before merging — the post-loop gate
> shrinks to a final live-verify. In legacy mode, run the gates yourself after the
> loop signals done, then push, PR, merge, verify live — and mark the item ✅ here.
>
> **NOT loop work** (done directly, no loop): docs/memory-bank edits (this file,
> `.specify/memory/history/`, `ITERATION_NOTES.md`), backlog mark-done/renumbering,
> the legacy-mode post-loop gates → PR → merge → deploy → verify steps, and the
> live-verify after a `--pr` run. Any code change that implements a backlog goal
> goes through the loop.

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

## ✅ Done (2026-08-11 session)
18. **User roles: admin / editor / user** — PR #82 (opencode-loop, 4 iterations, ALL_DONE):
    migration `2026_08_10_000001` adds `role` string (default `user`) + backfills
    `is_admin=true` → `admin`; `2026_08_11_000001` drops `is_admin`. `User::isAdmin()` now
    checks `role === 'admin'`; factory default `role => 'user'`; `BlogAdminTest` uses
    `role`; frontend `User.role?: string` + `AuthenticatedLayout` `role === 'admin'`.
    Verified on local MySQL + droplet prod (role present, is_admin absent, 1 admin
    backfilled). **Note for goal #2:** no `UserRole` enum yet — plain string compares;
    candidate for the editor-permissions goal. CI green; deployed + live-verified
    (/, /api both 200).

**Current floor:** 616 PHPUnit tests + 895 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; **CI enforces coverage thresholds and runs PHP 8.4**;
**users have admin/editor/user roles**; CI + deploy green on master.

## ✅ Done (2026-08-11 session)
19. **Blog editor permissions** — PR #83 (hand-built, TDD): editor-role users CRUD their
    **own** posts (incl. publishing); admins manage all. New `UserRole` enum
    (`app/Enums/UserRole.php`, the flagged sub-task) — `canManageBlog()`/`canManageAllBlogPosts()`
    helpers; `User` gained `isAdmin`/`isEditor`/`canManageBlog`/`canManageAllBlogPosts`.
    `EnsureUserIsAdmin` → parameterized `EnsureUserHasRole` (`role:admin,editor` alias);
    `/admin` dashboard stays `role:admin`, `/admin/blog` resource is `role:admin,editor`.
    `BlogPostController` scopes `index` to own posts for editors and aborts 403 on
    edit/update/destroy of others' posts. Frontend: Blog nav link for editors, per-row
    Edit/Delete gated by ownership, `User.role` TS union. PHPUnit 631 (+15), vitest 937
    (+9), PHPStan level 8 clean, pint clean, local coverage above thresholds.
    **Note:** no `role` cast on the User model — Larastan infers `role` as `string` from
    `#[Fillable]`; the enum is used via `UserRole::tryFrom($role)` compares instead.
    Deployed + live-verified behaviorally (editor login → blog CRUD → public publish;
    `/admin` 403; admin-dashboard route stays admin-only).

**Current floor:** 631 PHPUnit tests + 937 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; **CI enforces coverage thresholds and runs PHP 8.4**;
**users have admin/editor/user roles; editors CRUD their own blog posts**;
CI + deploy green on master.

## ✅ Done (2026-08-11 session)
20. **Featured blog section on homepage** — PR #84 (opencode-loop, **15 iterations,
    ALL_DONE on iter 15**): `BlogPreview.vue` rewritten to a magazine-style hero
    (latest post: full-bleed 21:9 image, gradient overlay, white text, excerpt,
    "Read more") + 2-column grid. Added `is_featured` (column + admin checkbox +
    amber badge; featured sorts first) and nullable `category` (column + admin field +
    pill badge). Author bylines eager-loaded. `HomeController` homepage data now
    includes latest posts (featured-first, limit 3); `/api/homepage-data` trimmed.
    PHPUnit 631 → **646** (+15), vitest 937 → **956** (+19). Post-loop hand-fixes:
    phpstan `assertIsString` in BlogPublicTest (3 errors), removed a stray
    `blog-mobile-375.png` the agent committed. Deployed + live-verified behaviorally
    (temp-published a post on prod → hero + Featured badge + byline render; section
    hides correctly when no published posts).

**Current floor:** 646 PHPUnit tests + 956 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; **CI enforces coverage thresholds and runs PHP 8.4**;
**users have admin/editor/user roles; editors CRUD their own blog posts; homepage has a
featured blog section**; CI + deploy green on master.

## ✅ Done (2026-08-11 session)
21. **Blog archive page (upgrade /blog index)** — opencode-loop `--pr` mode, **4
    iterations, ALL_DONE on iter 4**; shipped as 3 per-iteration PRs (#85/#86/#87,
    all CI-green before the poll fix confirmed post-hoc, deployed on each merge):
    date/month-year grouping on `/blog` (`Blog/Index.vue` sections + `<h2>` headers),
    `?category=` filter chips (case-insensitive, count-ordered, stacked with search),
    and `?search=` (title/excerpt LIKE). 9 new PHPUnit + 12 new vitest cases.
    Iteration 3 stalled (agent forgot the `<promise>` tag) → its search work was
    re-done in iteration 4. Post-loop: fixed a `pr_wait_checks` bug in the loop
    (it merged before CI appeared — "no checks → proceed" after 45s; now waits
    180s via `gh pr view --json statusCheckRollup`, counts SKIPPED/NEUTRAL as
    green, and never merges with pending/failed checks). Deployed + live-verified
    behaviorally (temp-published post → "August 2026" group, All/Guides chips,
    `?search=test` filter).

**Current floor:** 646 PHPUnit tests + 956 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; **CI enforces coverage thresholds and runs PHP 8.4**;
**users have admin/editor/user roles; editors CRUD their own blog posts; homepage has a
featured blog section; /blog is a grouped/filtered/searchable archive**; CI + deploy green.

## Next goals (in priority order)

### 1. Admin dashboard basic counts ⬅ NEXT
- Surface clear counts on `Admin/Dashboard.vue`: total restaurants, cuisines, users, and
  blog posts — alongside the existing data-quality/SerpApi/scrape cards. Backed by
  `Admin/DashboardController` (`__invoke`).
- **Goal:** `add restaurant, cuisine, user, and blog post counts to the admin dashboard overview`
- **Gate:** `composer test`

### 2. Post-login admin landing + nav discoverability
- After login, redirect `admin`/`editor` users to `/admin` (dashboard) instead of the
  stub `Dashboard.vue` ("You're logged in!"), so editors land where the blog editor
  lives. Make the admin/blog links reachable from the admin nav after auth. Roles
  foundation landed (goal 18, PR #82).
- **Goal:** `redirect admin/editor users to the admin dashboard after login and make blog editing discoverable in nav`
- **Gate:** `composer test && npm run build`

### 3. Homepage nav + hero polish
- Adopt the `AppLayout` top nav (brand left, links right: Browse/Leaderboard/Blog +
  Favorites/Dashboard/Login, admin links when admin) on the homepage instead of the
  sparse hero-only links floating in the slideshow. Tighten the hero: `min-h-screen` →
  `min-h-[80vh]`, make the logo a home link. **No city quick-chips** (declined). Keeps
  the Yelp-style look — polish, not redesign.
- **Goal:** `adopt the AppLayout top nav on the homepage and tighten the hero banner while preserving the current Yelp-style design`
- **Gate:** `npm run build`

### 4. Homepage section rhythm + stats band
- Alternate section backgrounds (e.g. `bg-muted/40` bands) and consistent section
  headers (title, subtitle, "View all" CTA) across `CategoryGrid`, `PopularCuisines`,
  `PopularRestaurants`, `BlogPreview`. Add a slim stats/trust band under the hero
  ("X restaurants · Y cuisines · Z cities") from `HomeController` data. Upgrade the
  `PopularCuisines` text list → clickable pill chips with counts, and add per-category
  counts to `CategoryGrid`. Preserves Yelp-style look (gentle tone alternation + chips).
- **Goal:** `add section background rhythm, a homepage stats band, and cuisine/category pills while preserving the Yelp-style design`
- **Gate:** `npm run build`

### 5. Homepage scroll-reveal motion
- Scroll-reveal animations for homepage sections via IntersectionObserver (reuse
  `resources/css/transitions.css`), honoring `prefers-reduced-motion`. Verify no
  regressions in `resources/js/Pages/__tests__/Welcome.spec.ts` (19 cases) and no
  overflow/layout shift.
- **Goal:** `add prefers-reduced-motion-aware scroll-reveal animations to homepage sections`
- **Gate:** `npm run build`

### 6. SerpApi quota honesty
- **Audit finding:** the SerpApi account is genuinely exhausted (429 "out of
  searches") but the app assumes a 250/mo quota, counts only SUCCESSFUL cached
  calls (failures never counted → the 80% circuit breaker at 200 never trips),
  and skips the provider-exhausted flag on pool timeouts/throwables — so every
  cold search fires a doomed call. Count EVERY live SerpApi call (success +
  failure) in a 30d window via a new `serpapi_call_logs` table (used by the
  circuit breaker AND the enrichment budget); set the exhaustion flag on all
  failure paths (incl. pool throwables/timeouts); gate `allowLiveSerpApiFetch()`
  on it; lower `SERPAPI_FREE_QUOTA` default to the real plan (env-overridable).
- **Goal:** `make SerpApi quota accounting honest — count all calls incl. failures, trip the circuit breaker early, and honor provider exhaustion on every failure path`
- **Gate:** `composer test`

### 7. Photon venue source
- **Audit finding:** the Overpass name-regex fallback is broken (takes 60s+ /
  504s on both mirrors — too heavy for Overpass) and its keyword regex wouldn't
  match real names like "Jerk Pit". Add a free `PhotonVenueService` (geo-bias +
  `osm_tag=amenity:restaurant|fast_food|cafe|bar|…`, ~2s, already a dependency)
  to the live-search pool for scoped searches; remove the broken Overpass
  name-regex fallback (`applyOverpassNameFallback` + read-path `fetchByNameRaw`).
- **Goal:** `add a free Photon venue source to the live search and remove the broken Overpass name-regex fallback`
- **Gate:** `composer test`

### 8. Cuisine keyword lexicon fix
- **Audit finding:** jamaican keywords are `jerk.chicken|jerk.pork|jerk.sauce`
  (dotted dish names) — no bare `jerk`, so "Jerk Pit" / "Jerk House Caribbean"
  never match the cuisine and get mis-classified/dropped. Add bare name tokens
  (`jerk`, `caribbean`, `irie`, `pattie`) to the jamaican/caribbean entries; add
  a guard test so dotted dish keywords can't silently break name matching.
- **Goal:** `fix the jamaican/caribbean cuisine keywords so real venue names like "Jerk Pit" match`
- **Gate:** `composer test`

### 9. Live-first search page
- **Audit finding:** `/search` queries the DB, then on empty dispatches an async
  `EnrichSearchResults` job and returns a spinner — an 8×4s poll gamble that ends
  in a bare empty when live sources are thin. Make `/search` run the free-source
  live search SYNCHRONOUSLY when the DB is empty (mirror `/api/restaurants`),
  persist + return results in the first response; add a relevance guard so
  weak/unrelated DB rows trigger a live refresh; surface an honest "limited
  coverage in this area" state instead of a bare empty.
- **Goal:** `make the search page run a live search immediately when the DB has no results and show an honest empty state`
- **Gate:** `composer test && npm run build`

### 10. BizData resilience
- **Audit finding:** BizData's upstream is flaky (intermittent 502 "fetch
  failed") and passing the ignored `query` param (always sent on scoped
  searches) can itself trigger the 502. Stop sending `query`; add a bounded
  live retry so a flaky response doesn't zero out the source.
- **Goal:** `stop passing BizData's ignored query param and add a bounded live retry for its flaky upstream`
- **Gate:** `composer test`

---

## Getting started (for the next session)
- Repo conventions, commands, deploy: `AGENTS.md`.
- Project principles + loop protocol: `.specify/memory/constitution.md`.
- This file's history: `.specify/memory/history.md` + `.specify/memory/history/`.
- `ITERATION_NOTES.md` is the loop relay — the loop re-seeds it when the goal changes,
  so don't hand-edit it between goals.
