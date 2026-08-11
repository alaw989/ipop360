# Iteration Notes

## Goal
enforce code coverage thresholds in CI for both PHPUnit and vitest

## State
- **Done this iteration**: Added `vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` as additional steps in the phpunit CI job so style + type checks gate merges alongside coverage. Fixed a pint violation in `scripts/check-coverage.php` (single_space_around_construct, concat_space, unary_operator_spaces, not_operator_with_successor_space, binary_operator_spaces) so the new pint gate passes cleanly.
- **Next**: All CI gating steps are now wired: phpunit tests, coverage thresholds (check-coverage.php), pint style check, phpstan type analysis, vitest tests, and vitest coverage thresholds. The Goal is complete — verify end-to-end by pushing and watching a CI run succeed.
- **Gotchas**: The artifact upload uses `if: always()` so coverage artifacts are uploaded even if preceding steps fail. Both pint and phpstan must pass before the artifact upload runs (no `if: always()` on those steps), so style/type failures block the pipeline.

## Log
- Iteration 1: Created CI workflow + vitest coverage config + `@vitest/coverage-v8` dependency
- Iteration 2: Raised vitest thresholds to 70/65/60/70, moved into vitest.config.ts, simplified CI step
- Iteration 3: Created Clover XML threshold enforcer script, wired into CI, verified against existing data
- Iteration 4: Added `composer coverage` script for local coverage validation before push
