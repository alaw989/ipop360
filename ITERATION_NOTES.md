# Iteration Notes

## Goal
enforce code coverage thresholds in CI for both PHPUnit and vitest

## State
- **Done this iteration**: Fixed the vitest artifact upload path in `ci.yml` from `coverage/vitest` (a non-existent subdirectory) to `coverage/` — `@vitest/coverage-v8` outputs directly into `coverage/`, so the old path would upload nothing. Verified vitest coverage passes all thresholds locally (72.61% stmts / 69.51% branches / 65.21% funcs / 73.05% lines).
- **Next**: The Goal is complete — push to master and watch CI pass on both jobs.
- **Gotchas**: Both phpunit and vitest output to `coverage/` but they run in separate CI jobs (separate runners), so no conflict. The vitest artifact captures the full coverage report directory including HTML report, clover.xml, and coverage-final.json.

## Log
- Iteration 1: Created CI workflow + vitest coverage config + `@vitest/coverage-v8` dependency
- Iteration 2: Raised vitest thresholds to 70/65/60/70, moved into vitest.config.ts, simplified CI step
- Iteration 3: Created Clover XML threshold enforcer script, wired into CI, verified against existing data
- Iteration 4: Added `composer coverage` script for local coverage validation before push
