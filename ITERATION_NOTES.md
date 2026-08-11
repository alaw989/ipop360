# Iteration Notes

## Goal
enforce code coverage thresholds in CI for both PHPUnit and vitest

## State
- **Done this iteration**: Created `scripts/check-coverage.php` — a Clover XML threshold enforcer that parses the `<metrics>` element, computes statement/conditional/method coverage percentages, and exits non-zero if any fall below the configured thresholds (statements: 50%, conditionals: 45%, methods: 45%). Wired it into the PHPUnit CI job as a step right after `php artisan test --coverage-clover=coverage/phpunit.xml`. Verified it passes against the existing clover.xml (73% statements, 70% conditionals, 65% methods) and fails correctly when thresholds are raised above actuals.
- **Next**: Both vitest and PHPUnit now have coverage enforcement in CI. The `composer test` script (`php artisan test`) does NOT generate coverage locally — it only runs tests. Consider adding a `composer coverage` script that runs `php artisan test --coverage-clover=coverage/phpunit.xml` followed by the check, so devs can validate locally before pushing. Alternatively, make the CI run include linting (pint --test) and typecheck (phpstan) steps in the phpunit job.
- **Gotchas**: The script reads the first `<metrics>` element from `<coverage>/<project>/<metrics>`, which is the project-level total. Thresholds are passed as optional CLI args; defaults are 50/45/45. pcov not available locally; coverage enforcement is CI-only for now. The coverage/ directory is gitignored so the clover.xml won't appear in the repo.

## Log
- Iteration 1: Created CI workflow + vitest coverage config + `@vitest/coverage-v8` dependency
- Iteration 2: Raised vitest thresholds to 70/65/60/70, moved into vitest.config.ts, simplified CI step
- Iteration 3: Created Clover XML threshold enforcer script, wired into CI, verified against existing data
