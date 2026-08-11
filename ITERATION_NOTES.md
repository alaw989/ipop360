# Iteration Notes

## Goal
enforce code coverage thresholds in CI for both PHPUnit and vitest

## State
- **Done this iteration**: Added a `composer coverage` script to composer.json that runs `php artisan test --coverage-clover=coverage/phpunit.xml` followed by `php scripts/check-coverage.php coverage/phpunit.xml`, so devs can validate coverage thresholds locally before pushing (when pcov/xdebug is available). The existing `composer test` remains unchanged (tests only, no coverage generation).
- **Next**: The coverage enforcement in CI is done for both PHPUnit (check-coverage.php step) and vitest (built-in thresholds in vitest.config.ts). Consider adding `vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` as additional steps in the phpunit CI job so style + type checks gate merges alongside coverage.
- **Gotchas**: pcov not available locally; `composer coverage` will fail with "No code coverage driver available" outside CI. The coverage/ directory is gitignored.

## Log
- Iteration 1: Created CI workflow + vitest coverage config + `@vitest/coverage-v8` dependency
- Iteration 2: Raised vitest thresholds to 70/65/60/70, moved into vitest.config.ts, simplified CI step
- Iteration 3: Created Clover XML threshold enforcer script, wired into CI, verified against existing data
- Iteration 4: Added `composer coverage` script for local coverage validation before push
