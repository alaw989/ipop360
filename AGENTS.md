# Agent Instructions

**Read the constitution:** `.specify/memory/constitution.md`

That file is your single source of truth for this project.

## Workflow rules

- **All work goes through PRs** — never push directly to master.
- Open a PR → quality checks run (tests, Pint, PHPStan) → you review → you merge → deploy.
- **Before opening a PR:** Always run `php artisan test`, `vendor/bin/pint --test`, and `npm run build` locally first. All tests must pass and the build must succeed before pushing.
