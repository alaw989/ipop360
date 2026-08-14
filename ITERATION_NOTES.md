# Iteration Notes

## Goal
schedule the free-source restaurants:backfill-photos command daily with a bounded --limit so image retrieval is decoupled from the SerpApi-bound enrichment, and remove the dead enrich-loop supervisor program from deploy config (deploy/supervisor-ipop360-enrich-loop.conf + its registration/restart steps in .github/workflows/deploy.yml)

## State
- DONE (iter 1): scheduled `restaurants:backfill-photos --apply --limit=100` daily at 06:30 UTC in `routes/console.php` (after backfill-websites at 06:00 so website_url is populated). Decouples free-source image retrieval from the SerpApi-bound enrichment. `--limit=100` bounds the daily sweep so the Google CSE last-resort source (~100 req/day free) is never exhausted. PhotoBackfillScheduleTest 4/4 green.
- DONE (iter 2): removed the dead enrich-loop supervisor program — deleted `deploy/supervisor-ipop360-enrich-loop.conf`, dropped the "Register the enrich loop supervisor program" step, and removed both `supervisorctl restart ipop360-enrich-loop:*` lines (Restart services + rollback). `grep -rn enrich-loop` in deploy.yml/deploy/ now returns nothing. deploy.yml still parses as valid YAML.
- GOAL COMPLETE: both halves done — (1) bounded daily photo backfill scheduled, (2) enrich-loop supervisor wiring fully removed. No further code changes needed toward this goal.
- NEXT (operator, post-goal): run the harden gate (pint → composer test → npm run test → phpstan → npm run build → coverage) before stacking the next backlog goal; shipping is operator-gated (one PR per goal).
- GOTCHA: `--apply` is required (default is dry-run no-op). Keep time clear of weekly 06:30 jobs (Mon coverage, Sat scrape-social --force).

## Log
- iter 1: added photo backfill schedule + bounded limit (tests were pre-written, now green).
- iter 2: deleted enrich-loop supervisor conf + removed its registration step and both restart lines from deploy.yml.
