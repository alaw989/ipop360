# 2026-08-10 — opencode-loop quality sprint (PRs #71–#77)

**Date:** 2026-08-10 · **Branches:** `feat/phpstan8*`, `feat/dead-code-sweep`,
`feat/livesearch-orchestration-coverage`, `fix/phpstan-assertisfloat`,
`feat/phpstan-zero-baseline`, `chore/ci-node24-actions`, `feat/page-vitest-round2` ·
**Status:** COMPLETE

## What shipped (all deployed + live-verified)
- **#71** PHPStan **level 8** (16 loop iterations), baseline 345 → 2.
- **#72** dead-code / cruft sweep (20 loop iterations) — 4 dead artisan commands, ~35
  unused shadcn-vue components, 3 npm packages, 13 dead env vars, dead DB/mailer/service
  config. 57 files, +50/−1642.
- **#73** `LiveSearchService.search()` orchestration coverage (5 iterations, ALL_DONE
  early) — 4 new tests, suite 563 → **567**.
- **#74** post-merge fix: `assertIsFloat((float) …)` was `method.alreadyNarrowedType` —
  the cast made the assertion trivially true.
- **#75** PHPStan level 8 **zero baseline** — removed one redundant
  `assert($coords !== null)` (the surrounding `if` already narrowed it) and deleted
  `phpstan-baseline.neon`. Kept the 18 regex `ignoreErrors` patterns in `phpstan.neon`
  — verified still load-bearing (mockery/`Model::$prop` in tests).
- **#76** CI actions → Node-24 majors: `actions/checkout@v5`, `actions/setup-node@v5`,
  `gitleaks/gitleaks-action@v3` (confirmed `runs.using: node24` in action.yml). Zero
  annotations on both workflows.
- **#77** page-level vitest for every remaining page (9 iterations, ALL_DONE early) —
  9 new specs, vitest 565 → **725**. Every page in `resources/js/Pages/` now has a spec.

## Key lesson — wait for CI green BEFORE merging
PR #73 was merged before its CI finished; the quality job then failed on PHPStan level 8
and **no deploy ran** (master red, deploy silently skipped). The fix had to ride a second
PR (#74) through the same gate.

The merge-deploy discipline going forward (do NOT skip):
1. Open the PR, then `gh run list --branch <branch> --limit 1` for the run id.
2. `gh run watch <id> --exit-status` until the quality job reports `success`.
3. Only then merge, and watch the master run (quality → deploy) to completion.
4. `gh run view <id> --json conclusion,jobs` to confirm BOTH jobs green.

The branch run only runs `quality`; the master run (after merge) is the one with the
`deploy` job. Also: `gh run list --branch master --limit 1` can return a stale run for an
older commit — verify the `headSha` matches the merge commit before watching.

## Lessons / gotchas
- **`opencode-loop` finishes early when the goal is fully met** — `ALL_DONE` stops the
  loop (exit 0) well under the cap: 5, 5, 9 iterations on the three goals this session.
  First iterations can be stalls (committed but not accepted); that's normal — the loop
  only accepts commits + passing check + `<promise>DONE</promise>`.
- **The PHPStan "always true" family** (`function.alreadyNarrowedType`,
  `notIdentical.alwaysTrue`, `method.alreadyNarrowedType`) means the assertion is
  provably redundant from the types — remove the redundancy, don't cast or add
  `@phpstan-ignore`. `assert()` inside a `when()` closure is load-bearing (PHPStan can't
  narrow through the closure) and must stay.
- **Two baseline entries can point at one line** — #75's "2 entries" were the same
  `assert($coords !== null)` reported twice (the call + the `!==` inside it). Fix one
  line, both vanish.
- **Backlog is the work source**, not `specs/` — per `project-state.md` note. New goals
  added at the end of this session (ranked): scheduled-command coverage → complex
  component specs → CI coverage thresholds → CI/prod PHP alignment.

## Verification
- Final suite: **567 PHPUnit (2507 assertions) + 725 vitest**; `phpstan analyse` →
  "No errors" at level 8 with no baseline; `pint --test` clean; `npm run build` clean;
  CI quality + deploy green with zero annotations; live `/` + `/api` both 200.
