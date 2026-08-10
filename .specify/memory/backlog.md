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

## Next goals (in priority order)

### 1. Scheduled-command test coverage ⬅ NEXT
- 7 artisan commands run daily/weekly in production but have **zero test references**:
  `restaurants:update-engagement`, `seo:sitemap`, `restaurants:score`,
  `uptime:canary`, `restaurants:scrape-social`, `apicache:gc`,
  `restaurants:verify-websites`. All scheduled in `routes/console.php`; none tested.
- **Goal:** `add unit coverage for the 7 scheduled artisan commands (UpdateEngagement, GenerateSitemap, ScoreRestaurants, UptimeCanary, ScrapeRestaurantSocialLinks, GarbageCollectApiCache, VerifyRestaurantWebsites)`
- **Gate:** `composer test`

### 2. Complex component vitest specs
- 9 non-trivial Vue components still lack specs: `Modal`, `CardGallery`, `BlogEditor`,
  `BlogPreview`, `SearchMap`, `DetailMap`, `HeroBanner`, `PopularRestaurants`,
  `RestaurantCardSkeleton` (thin primitives like `InputLabel`/`BrandLogo` are not worth it).
- **Goal:** `add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)`
- **Gate:** `npm run test`

### 3. CI coverage enforcement
- `phpunit.xml` has no coverage config and CI never fails on coverage loss — the suite
  can regress silently between PRs. Add PHPUnit (xdebug) + vitest coverage thresholds to
  the quality gate.
- **Goal:** `enforce code coverage thresholds in CI for both PHPUnit and vitest`
- **Gate:** `composer test && npm run test`

### 4. Align CI PHP with production
- CI runs `php-version: '8.5'` but the droplet runs `php8.4` (all artisan/fpm commands).
  Tests pass on a different PHP than prod. Either run both in CI or upgrade the droplet.
- **Goal:** `align the CI PHP version with production (php 8.4), or run both 8.4 and 8.5`
- **Gate:** `composer test`

---

## Getting started (for the next session)
- Repo conventions, commands, deploy: `AGENTS.md`.
- Project principles + loop protocol: `.specify/memory/constitution.md`.
- This file's history: `.specify/memory/history.md` + `.specify/memory/history/`.
- `ITERATION_NOTES.md` is the loop relay — the loop re-seeds it when the goal changes,
  so don't hand-edit it between goals.
