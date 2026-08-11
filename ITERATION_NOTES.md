# Iteration Notes

## Goal
enforce code coverage thresholds in CI for both PHPUnit and vitest

## State
- **Done this iteration**: Raised vitest coverage thresholds from 50% to reflect actual current coverage (statements: 70%, branches: 65%, functions: 60%, lines: 70%). Moved threshold config into `vitest.config.ts` so they're enforced on local dev and CI alike. Simplified CI workflow to `npx vitest run --coverage` (reads thresholds from config).
- **Next**: PHPUnit 12 does NOT support coverage thresholds in phpunit.xml (no `<threshold>` element exists in schema; `--min` CLI flag was removed). Need to enforce PHPUnit thresholds via either a PHPUnit extension, a post-test Clover-parsing script, or a third-party package like `dvdh/phpunit-coverage-check`. Figure out the approach and wire it into CI.
- **Gotchas**: PHPUnit 12.5.31 confirmed that coverage thresholds are not a native feature — the HTML report `Thresholds` class is only for color-coding. pcov driver not available locally but works in CI via `shivammathur/setup-php@v2`. Vitest thresholds in `vitest.config.ts` apply to all vitest runs including local dev.

## Log
- Iteration 1: Created CI workflow + vitest coverage config + `@vitest/coverage-v8` dependency
- Iteration 2: Raised vitest thresholds to 70/65/60/70, moved into vitest.config.ts, simplified CI step
