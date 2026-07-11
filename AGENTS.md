# Agent Instructions

**Read the constitution:** `.specify/memory/constitution.md`

That file is your single source of truth for this project.

## Workflow rules

- **Before creating a PR:** Always run `php artisan test`, `vendor/bin/pint --test`, and `npm run build` locally first. All tests must pass and the build must succeed before pushing or creating a pull request.
