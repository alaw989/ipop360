# 2026-08-11 — opencode-loop coverage + blog/admin foundation sprint (PRs #78–#82)

**Date:** 2026-08-11 · **Branches:** `feat/scheduled-command-test-coverage`,
`feat/complex-component-vitest-specs`, `feat/ci-coverage-enforcement`,
`feat/align-ci-php-84`, `feat/user-roles` · **Status:** COMPLETE

## What shipped (all deployed + live-verified, CI green before merge)
- **#78** scheduled-command test coverage (7 iterations, ALL_DONE) — 7 new
  `tests/Feature/` specs for the 7 artisan commands, PHPUnit 567 → **616** (+49). Also
  fixed a real Carbon 3 signed `diffInHours()` bug surfaced by `UptimeCanary` tests.
- **#79** complex component vitest specs (9 iterations, ALL_DONE) — 9 new specs
  (RestaurantCardSkeleton, BlogPreview, SearchMap, DetailMap, HeroBanner,
  PopularRestaurants, Modal, CardGallery, BlogEditor), vitest 725 → **895** (+170).
- **#80** CI coverage enforcement (6 iterations, ALL_DONE) — PHPUnit coverage via pcov →
  clover, enforced by `scripts/check-coverage.php`; vitest coverage via
  `@vitest/coverage-v8` (thresholds 70/65/60/70); `composer coverage` local script.
- **#81** CI PHP aligned to production: `8.5` → `8.4` in `ci.yml` + both `deploy.yml`
  steps; AGENTS.md stack line `8.3` → `8.4` (3 iterations, ALL_DONE).
- **#82** user roles foundation (4 iterations, ALL_DONE) — `is_admin` boolean → `role`
  column (`admin`/`editor`/`user`) with data backfill; `isAdmin()` reads `role === 'admin'`.
  Unblocks blog editor permissions + post-login admin landing.

## Key lesson — hand-review the loop's output before PR (it can regress CI)
PR #80's loop **rewrote `ci.yml` into two split jobs** (phpunit + vitest) that both
failed on cross-dependencies: the phpunit job had no built frontend (`Vite manifest not
found` → 49 failures) and the vitest job had no composer `vendor/` (`Cannot find module
ziggy` → TS2307). Also regressed `checkout@v5`→`@v4`, node 22→20, PHP 8.5→8.3, dropped
`touch .env`, dropped `npm run build`. Hand-fixes restored the proven single quality job
and conventions. **Every loop needs a diff review before merge.**

Other hand-fixes this session:
- Loop committed the vitest `coverage/` report artifacts (159 files, ~48k lines) — added
  `/coverage` to `.gitignore` and `git rm --cached`.
- `scripts/check-coverage.php` initially failed: pcov emits `conditionals="0"`, so the
  script now **skips unmeasured metrics** instead of failing on them.
- Backlog mark-done edits can leave a stale old-goal body under the new heading — verify
  with `grep -n "^### "` and renumber + fix cross-refs after each completion.

## Lessons / gotchas
- **`opencode-loop` stops early on ALL_DONE** — most goals finished in 3–9 iterations,
  well under the 20 cap. The cap doesn't need raising per-goal; there is no multi-goal
  chaining in the harness, so each backlog item is its own loop run on its own branch.
- **CI is now stricter** — coverage thresholds (PHPUnit clover + vitest) + pint + phpstan
  + build all gate merges, so the pre-push gate (`composer test` → pint → `npm run build`)
  is only part of what CI checks.
- **`gh pr create --body` with backticks**: bash `$'...'` still runs command
  substitution — escape backticks or use a heredoc file to avoid a mangled PR body.
- **Local dev DB is MySQL** (`DB_CONNECTION=mysql`, `DB_DATABASE=ipop360`), not the
  `database/database.sqlite` file — verify schema migrations against MySQL via tinker.
- `user-roles` used plain string compares (`role === 'admin'`); a `UserRole` enum is a
  candidate sub-task for the blog-editor-permissions goal.

## Verification
- Final floor: **616 PHPUnit + 895 vitest**; PHPStan level 8 zero-baseline; pint clean;
  CI enforces coverage + runs PHP 8.4; live `/` + `/api` both 200. Prod DB verified:
  `role` present, `is_admin` absent, 1 admin backfilled.
