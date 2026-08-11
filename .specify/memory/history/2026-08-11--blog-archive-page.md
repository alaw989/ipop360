# 2026-08-11 — Blog archive page (PRs #85/#86/#87)

**Date:** 2026-08-11 · **Mode:** opencode-loop `--pr` (per-iteration PR) ·
**Status:** COMPLETE (ALL_DONE on iteration 4 of 20)

## What shipped
Backlog goal #1: the public `/blog` index became a full archive. Each iteration
merged as its own PR (all CI-passed, deployed per merge):

- **Iter 1 → PR #85**: date/month-year grouping — `Blog/Index.vue` renders a
  `<section>` per month-year with an `<h2>` header ("August 2026", …); same-month
  posts share a grid. 3 new vitest cases.
- **Iter 2 → PR #86**: `?category=` filter — `BlogController::index()` takes the
  param (case-insensitive `LOWER()` match), passes distinct non-null categories
  (count desc, then alpha) + active filter to Inertia; pill-chip nav (All + one
  per category) with active highlight; `withQueryString()` preserves across pages.
  4 new PHPUnit + 6 new vitest cases.
- **Iter 3**: STALLED — the agent implemented search but forgot the exact
  `<promise>DONE</promise>` line, so the commit stayed on its iteration branch and
  the branch was discarded (search work re-done in iter 4). Good catch by the
  loop's accept gate: a real change never merged without the DONE signal.
- **Iter 4 → PR #87**: `?search=` — title/excerpt `LOWER() LIKE` (SQLite/MySQL
  compatible via `whereRaw`), debounced search input + hidden category input so
  search and category stack; distinct empty states ("No posts match your search"
  vs "No articles yet"). 5 new PHPUnit + 6 new vitest cases. ALL_DONE.

PHPUnit 646 → **655**, vitest 956 → **968** (approx; +9/+12 new cases across iters).

## Key lesson — the `pr_wait_checks` bug (fixed after this run)
The loop's PR-check waiter treated "no checks reported after 45s" as "this repo
has no CI" and **merged before CI ran**. It happened to be safe here (the local
`--check` was `composer test && npm run build`, and all three PRs' CI came back
green post-merge), but the mechanism was wrong. Fixed in `~/.local/bin/opencode-loop`:
- Wait up to **180s** (`PR_CHECK_APPEAR_WAIT`) for checks to materialize before
  assuming none exist.
- Poll `gh pr view --json statusCheckRollup` (reliable right after PR creation)
  instead of `gh pr checks` (which returns `[]` while runs are still being created).
- Count `SKIPPED`/`NEUTRAL` conclusions (e.g. the deploy job on a PR) as **green**,
  so the wait resolves instead of timing out.
- Never merge with pending or failed checks; print failing check names on red.

## Verification (live)
Temp-published the prod draft post with `category=Guides`: `/blog` rendered the
"August 2026" group, All/Guides category chips, "Search posts…" input, and
`/blog?search=test` filtered to the post. Reverted the post to draft after.

## Follow-ups
- Next backlog goal is now #1: **admin dashboard basic counts**.
- The loop's per-iteration PR flow is now safe (waits for CI) for future runs.
