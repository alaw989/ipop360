# iPop360 — Backlog (opencode-loop goals)

> ⚠️ **Binding rule: backlog goals are ALWAYS executed via `opencode-loop` — never
> implemented directly.** This file is the loop's work queue. When work starts on
> this backlog, run the **first unfinished item** below through the loop on its own
> feature branch; finish with the documented post-loop steps, then mark the item ✅
> here.
>
> The loop lives at `~/.local/bin/opencode-loop` (globally installed).
>
> Loop recipe — **legacy single-branch mode** (the ONLY mode; `--pr` was removed
> 2026-08-11 — the harness rejects the flag). One pre-created `feat/<goal-slug>`
> branch, one commit per iteration, one PR per goal created by the operator after
> the loop finishes:
> ```bash
> git checkout master && git pull origin master && git checkout -b feat/<goal-slug>
> setsid nohup opencode-loop 20 --goal "<goal text>" \
>   --check "<gate>" --model opencode-go/deepseek-v4-pro \
>   > logs/opencode-loop-<slug>.out 2>&1 < /dev/null &
> ```
>
> Then monitor `logs/opencode-loop-<slug>.out` (tail + grep the emoji status
> lines). The loop commits each accepted iteration and stops on ALL_DONE or cap;
> it never pushes or opens PRs.
>
> **Local-first, operator-gated deploy (binding):** looping sessions run LOCALLY.
> Stack goal branches on top of each other (goal N branches off goal N−1's local
> branch). After EVERY goal's loop signals done, harden on the branch before
> stacking the next: `pint --test` → `composer test` → `npm run test` →
> `./vendor/bin/phpstan analyse` → `npm run build` → coverage pre-check
> (`composer coverage` + `npx vitest run --coverage`) — fix anything red, no debt
> carries forward. Do NOT push, open PRs, or deploy until the operator says so.
> Backlog ✅ marks happen at MERGE time, never during local looping.
>
> **Shipping (operator says so):** each goal ships as its OWN PR — **one major
> feature per PR**, never a combined mega-PR. Push the branch, run the full gate,
> **create ONE PR and stop to notify the operator** before merging. Merge in
> sequence (stacked order), then deploy → live-verify and mark each item ✅ here.
>
> **NOT loop work** (done directly, no loop): docs/memory-bank edits (this file,
> `.specify/memory/history/`, `ITERATION_NOTES.md`), backlog mark-done/renumbering,
> and the post-loop gates → PR → merge → deploy → verify steps. Any code change
> that implements a backlog goal goes through the loop.

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

## ✅ Done (2026-08-13 session, continued — PRs #100/#101)
34. **BizData resilience** — PR #100 (opencode-loop legacy, ALL_DONE): stopped
    passing BizData's ignored `query` param (it can trigger the 502) and added a
    bounded live retry (`live_search.bizdata_attempts`, default 2) on the read
    path only. Merged + deployed + live-verified.
35. **Admin Users page** — PR #101 (opencode-loop legacy, ALL_DONE): `/admin/users`
    admin-only page listing users with a role selector (promote/demote
    user/editor/admin), self-demote confirm + last-admin guard via new
    `UserRoleService` (shared with the `user:role` command); Ziggy exposes
    `admin.users.index`/`admin.users.update`; admin layout logo swapped to
    `BrandLogo`. PHPUnit 682→691. Merged + deployed + live-verified.

**Current floor:** 691 PHPUnit tests + 1056 vitest tests; PHPStan level 8 over
`app/ + tests/` with a zero baseline; pint clean; **CI enforces coverage
thresholds and runs PHP 8.4**; **search covers: honest SerpApi quota accounting,
free Photon source, real-name Caribbean cuisine matching, synchronous live-first
search page, BizData resilience, and an admin users page**; CI + deploy green.

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

## ✅ Done (2026-08-11 session, continued)
22. **Admin dashboard basic counts** — PR #88 (opencode-loop `--pr` mode, **1 iteration,
    ALL_DONE on iter 1**): `Admin/DashboardController` now passes `entityCounts`
    (restaurants, cuisines, users, blog_posts); `Admin/Dashboard.vue` renders a new
    Overview section with 4 count cards; `Admin.Dashboard.spec.ts` updated. Deployed +
    live-verified (counts on droplet: 7969 restaurants, 59 cuisines, 7 users, 1 post).

**Current floor:** 646 PHPUnit tests + 956 vitest tests; PHPStan level 8 over `app/ + tests/`
with a zero baseline; pint clean; **CI enforces coverage thresholds and runs PHP 8.4**;
**users have admin/editor/user roles; editors CRUD their own blog posts; homepage has a
featured blog section; /blog is a grouped/filtered/searchable archive; admin dashboard
shows entity counts**; CI + deploy green.

## ✅ Done (2026-08-11 session, continued)
23. **Post-login admin landing + nav discoverability** — PR #89 (opencode-loop legacy
    mode, **2 iterations, ALL_DONE on iter 2**): `AuthenticatedSessionController::store`
    now redirects admins → `admin.dashboard`, editors → `admin.blog.index`, users →
    `dashboard` (unchanged); public `AppLayout` gains a "Manage Blog" link for
    admin/editor users. 2 new auth redirect tests + `AppLayout.spec.ts`. Post-loop
    hand-fix: PHPStan null-guard on `$request->user()` in the login `match` (2 errors).
    PHPUnit 656, vitest 982. Deployed + live-verified (/, /api 200).

**Current floor:** 656 PHPUnit tests + 982 vitest tests; PHPStan level 8 over
`app/ + tests/` with a zero baseline; pint clean; **CI enforces coverage thresholds and
runs PHP 8.4**; **users have admin/editor/user roles; editors CRUD their own blog posts;
homepage has a featured blog section; /blog is a grouped/filtered/searchable archive;
admin dashboard shows entity counts; admin/editor users land in admin after login**;
CI + deploy green.

## ✅ Done (2026-08-11 session, continued)
24. **Assign user roles via artisan** — PR #90 (opencode-loop legacy mode, **1 iteration,
    ALL_DONE on iter 1**): `user:role <email> admin|editor|user` command validates the
    role against the `UserRole` enum, errors on unknown role / missing email, prints the
    before→after role. 6 tests in `UserRoleCommandTest` (assign admin/editor/user,
    invalid role, unknown email, reassign). Post-loop hand-fixes: pint formatting +
    PHPStan null-safety on `$user->fresh()` (one line `refresh()` instead of `?->`).
    PHPUnit 662. Keeps `users.role` string column (no roles table). Deployed +
    live-verified (command registered + error paths verified on the droplet).

## ✅ Done (2026-08-12 session)
25. **Homepage nav + hero polish** — PR #91 (opencode-loop legacy mode, 4 iterations,
    ALL_DONE on iter 4): shared `TopNav.vue` component (Browse link, mobile-responsive
    collapse menu, Escape/outside-click close) adopted on the homepage; hero tightened.
    420+/124−. PHPUnit 656→, vitest 982→ (TopNav.spec.ts added). Merged + deployed +
    live-verified (/, /api both 200). **Process note:** this was the last goal merged
    via the per-goal deploy cycle — from here the operator-gated local-first protocol
    (binding, persisted 2026-08-12) applies: batch goals locally, deploy only on
    operator signal, one feature per PR.

**Current floor:** 662 PHPUnit tests + 982 vitest tests; PHPStan level 8 over
`app/ + tests/` with a zero baseline; pint clean; **CI enforces coverage thresholds and
runs PHP 8.4**; **users have admin/editor/user roles assignable via `user:role`;
homepage has a shared top nav**; CI + deploy green.

## ✅ Done (2026-08-13 session)
26. **Homepage section rhythm + stats band** — PR #92 (opencode-loop legacy, 5 iterations,
    ALL_DONE): alternating section background bands, `StatsBand.vue` under the hero
    (restaurants/cuisines/cities from HomeController), cuisine + category pill chips.
    15 files +533/−271. PHPUnit 664, vitest 1017. Merged + deployed + live-verified.
27. **Homepage scroll-reveal motion** — PR #94 (opencode-loop legacy, 4 iterations,
    ALL_DONE): `ScrollReveal.vue` wrapper (one-shot IntersectionObserver) + CSS in
    transitions.css, reduced-motion-aware via `@media (prefers-reduced-motion: reduce)`;
    wired into the 5 idle homepage sections. PHPUnit 665, vitest 1033. Rebased on master
    after the nav-transparency fix merged; merged + deployed + live-verified.
28. **Transparent top nav over the homepage hero** — PR #93 (direct feedback fix on PR
    #91's nav): `TopNav` gained a `transparent` prop — `absolute inset-x-0 top-0
    bg-transparent` + white/light links over the dark hero in the idle phase, solid
    `bg-card/80` in results/regular pages (AppLayout unchanged). Merged + deployed +
    live-verified.
29. **PR #62 closed as superseded** (2026-08-07, branch `fix/phpstan-photo-scraper`):
    its 3 service test files are all covered on master in evolved form — HtmlSanitizerTest
    (10 tests), AiEnrichmentServiceTest (11, larger than #62's 7), LiveVenuePersister via
    LiveVenuePersisterAwardTest + EnrichSearchResultsTest. Branch was 311 commits behind
    master and conflicting; no rebase value.

**Current floor:** 665 PHPUnit tests + 1033 vitest tests; PHPStan level 8 over
`app/ + tests/` with a zero baseline; pint clean; **CI enforces coverage thresholds and
runs PHP 8.4**; **homepage has section rhythm, stats band, pills, scroll-reveal motion,
and a transparent top nav over the hero**; CI + deploy green.

## ✅ Done (2026-08-13 session, continued — search/coverage backlog)

30. **SerpApi quota honesty** — PR #96 (opencode-loop legacy, 5 iterations,
    ALL_DONE): `recordFailedCall()` counts EVERY failed call (429/5xx + pool
    `\Throwable`) as an empty cache row across `search`/`fetchRaw`/
    `consumePoolResponses` → they count toward the 30d quota so the circuit
    breaker trips early; `fetchRaw` gained exhaustion detection; enrichment's
    throttled caller breaks with `quota_exhausted`; `search()`/`fetchRaw()`
    skip live calls when exhausted (cached results still serve free). PHPUnit
    665→674. Merged + deployed + live-verified (/, /api 200).
31. **Photon venue source** — PR #97 (opencode-loop legacy, 3 iterations,
    ALL_DONE): new free `PhotonService` (keyless OSM text-search, `bbox`
    geofence, `osm_tag=amenity:*` filter) wired as the 5th live-search source;
    removed the broken Overpass name-regex fallback everywhere
    (`applyOverpassNameFallback`, `consumeOverpassResponses`,
    `fetchByNameRaw`/`searchByName`/`executeSearchByName` + tests). PHPUnit
    674→677. Rebased off master pre-PR. Merged + deployed + live-verified.
32. **Cuisine keyword lexicon fix** — PR #98 (opencode-loop legacy, 13
    iterations, ALL_DONE): bare name tokens (`jerk`, `caribbean`, `irie`,
    `pattie`, …) + `coal.pot` (trinidadian, with negative guard vs "Coal Fired
    Pizza") so real venue names like "Jerk Pit" match. Independent DB re-audit:
    0 remaining Caribbean misses across all 4 Caribbean cuisines. PHPUnit
    677→678 (21 CuisineMatcherTest cases). Rebased off master pre-PR.
33. **Live-first search page** — PR #99 (opencode-loop legacy, 5 iterations,
    ALL_DONE): `/search` runs the free-source live search SYNCHRONOUSLY when
    the DB is empty (mirror `/api/restaurants`), persists via evidence-gated
    `LiveVenuePersister` + re-queries; relevance guard refreshes on weak rows;
    honest "limited coverage" empty state; deleted `EnrichSearchResults` job.
    PHPUnit 678→682. Stacked on PR #98 (merge #98 first).

**Current floor:** 682 PHPUnit tests + 1034 vitest tests; PHPStan level 8 over
`app/ + tests/` with a zero baseline; pint clean; **CI enforces coverage
thresholds and runs PHP 8.4**; **search covers: honest SerpApi quota accounting,
free Photon source (Overpass name-regex fallback removed), real-name Caribbean
cuisine matching, and a synchronous live-first search page**; CI + deploy green.

## Next goals (in priority order)

### 1. Unified merged search ⬅ NEXT
- **Audit finding:** both search endpoints (`/search`, `/api/restaurants`) are
  DB-first — the live free-source API search runs ONLY when the DB returns zero
  rows. So a city/cuisine with even one stale DB row never sees fresh venues from
  the 4 free unlimited sources (BizData, Overpass, Photon, Socrata), and DB + live
  results are never ranked together. Goal: for ANY city × cuisine, ALWAYS run the
  live search (cache-first; SerpApi stays behind its existing circuit breaker /
  per-IP limiter / thundering-herd lock so it never exhausts), merge persisted DB
  rows into it (DB row wins as base, live overlays rating/price/photo/website/
  description/place_types; match by google_place_id → slug → phone-last-10 →
  fuzzy name + 200m via `VenuePipeline::venuesMatch`), score the merged union in
  ONE pass (`PopularityScoreService`), stamp `cuisine_match` on DB rows too (else
  the 0.50 weight renormalizes away and DB rows are unfairly inflated), raise
  result caps (`live_search.max_results` 60→150; Photon 30→50; Overpass/BizData
  50→75), add a per-IP cold-miss limiter for the free sources (mirror SerpApi's),
  and serve via the existing pagination snapshot. Both controllers delegate to a
  new `UnifiedSearchService`. Tests: merger unit tests (match keys, precedence,
  overlay), endpoint feature tests (DB + live co-present, ranked together, guards
  respected), update `SearchControllerTest` / `LiveSearchScoringTest` that lock in
  DB-only behavior.
- **Goal:** `unified merged search: always run live free-source search, merge DB rows, rank the union by popularity score, raise result caps, guard free-source cold misses`
- **Gate:** `composer test && npm run build`

### 2. Data-driven popularity-score audit + rebalance
- **Audit finding:** score weights are tuned by reading the code, not measured
  against the live DB. `has_award` (0.05, always-active) reads 0 for the whole
  population; `quality` (0.35) vanishes entirely on an exhausted/no-key SerpApi
  deploy; the unrated cohort clumps at 0.10–0.30. Goal: add a `ranking:audit`
  diagnostic (signal activation %, score distribution/clumping, dead-weight
  detection, quality coverage), document findings in `docs/ranking-metrics.md`,
  rebalance weights (config + `PopularityScoreService::DEFAULT_WEIGHTS` together),
  pin with tests, re-run audit to show spread improvement.
- **Goal:** `data-driven popularity-score audit: add ranking:audit diagnostic, document findings, rebalance weights with tests locking the change`
- **Gate:** `composer test && ./vendor/bin/phpstan analyse`

---

## Getting started (for the next session)
- Repo conventions, commands, deploy: `AGENTS.md`.
- Project principles + loop protocol: `.specify/memory/constitution.md`.
- This file's history: `.specify/memory/history.md` + `.specify/memory/history/`.
- `ITERATION_NOTES.md` is the loop relay — the loop re-seeds it when the goal changes,
  so don't hand-edit it between goals.
