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

---

## Next goals (in priority order)

### 1. PHPStan level 8 ⬅ NEXT
- Level 7 is fully clean. Bump `level: 8`, generate baseline, loop shrinks to zero
  (same playbook as #3/#4 above).
- **Goal:** `shrink the PHPStan level-8 baseline by fixing real type issues in code`
- **Gate:** `./vendor/bin/phpstan analyse && composer test`

### 3. PHPStan level 8
- Level 7 is fully clean. Bump `level: 8`, generate baseline, loop shrinks to zero
  (same playbook as #3/#4 above).
- **Goal:** `shrink the PHPStan level-8 baseline by fixing real type issues in code`
- **Gate:** `./vendor/bin/phpstan analyse && composer test`

### 4. Dead-code / cruft sweep
- Remove unused services, dead config keys, orphaned scopes/helpers (e.g. any remaining
  `scope*`/`byPopularity`-style dead code; unused imports after the type work).
- **Goal:** `find and remove dead code and unused configuration across the app`
- **Gate:** `composer test && npm run build`

### 5. LiveSearchService.search() orchestration coverage
- `LiveSearchScoringTest` covers scoring; the full `search()` orchestration (pooling,
  cache-first, merge, bounds) is only partly covered.
- **Goal:** `add unit coverage for LiveSearchService.search() orchestration`
- **Gate:** `composer test`

---

## Getting started (for the next session)
- Repo conventions, commands, deploy: `AGENTS.md`.
- Project principles + loop protocol: `.specify/memory/constitution.md`.
- This file's history: `.specify/memory/history.md` + `.specify/memory/history/`.
- `ITERATION_NOTES.md` is the loop relay — the loop re-seeds it when the goal changes,
  so don't hand-edit it between goals.
