# Iteration Notes

## Goal
enforce code coverage thresholds in CI for both PHPUnit and vitest

## State
- **Done this iteration**: Created `.github/workflows/ci.yml` with two parallel jobs: `phpunit` (PHP 8.3 + pcov coverage, runs `php artisan test --coverage-clover`) and `vitest` (Node 20 + `@vitest/coverage-v8`, runs with 50% thresholds on all metrics). Added `@vitest/coverage-v8` to devDependencies and configured vitest coverage provider in `vitest.config.ts`. Workflow triggers on push/PR to master. Both jobs upload coverage artifacts.
- **Next**: Add explicit coverage thresholds to `phpunit.xml` (PHPUnit 12 uses `<coverage><report>` with threshold elements, not CLI flags) and raise vitest thresholds beyond 50%. Then update the workflow to fail if thresholds aren't met.
- **Gotchas**: PHPUnit 12 removed `--min` CLI flag for coverage — thresholds must be in phpunit.xml. pcov driver not available locally but works in CI via `shivammathur/setup-php@v2`. Vitest 4.x uses `coverage.thresholds.*` CLI flags which work fine.

## Log
- Iteration 1: Created CI workflow + vitest coverage config + `@vitest/coverage-v8` dependency
