# Iteration Notes

## Goal
scheduler/infra hardening audit: instrument per-command runtime + failure telemetry; set explicit withoutOverlapping() expiries (or skipDuplicates); resolve the 5h35m throttled-enrichment collision with other daily jobs; verify cron + schedule:work redundancy is correct; confirm all 16 scheduled commands fire on time on the droplet

## State
Checkpoint 282277d already shipped items 1 (telemetry), 2 (explicit mutex expiries), 4 (single-driver cron), and the `scheduler:report` tool. This iteration resolved item 3: the daily jobs that started inside the throttled-enrichment window.

- Moved 4 colliding daily jobs OUT of the enrichment mutex window [04:00, ~10:00): seo:sitemap 05:00→10:15, scrape-social 05:30→10:45, backfill-websites 06:00→11:45, backfill-photos(apply) 06:30→13:45 (chained, no mutual overlap).
- Added `tests/Feature/SchedulerCollisionTest.php` — asserts no fixed-daily DB-write job starts inside the enrichment window (derived from the event's own dailyAt + mutex expiry); exempts ai-enrich (every 6h, intentional) and uptime:canary. Verified it FAILS if a job is moved back into the window, passes otherwise.
- Updated stale/contradictory comments in routes/console.php (the old "after enrichment 04:00–05:05" was wrong vs the actual 5h35m run) and corrected the AGENTS.md scheduler summary.

This iteration resolved the remaining weekly-job collisions from item 3: all 5 weekly jobs that started inside the throttled-enrichment window [04:00, ~10:00) on their days are now moved out.

- Moved weekly jobs after the enrichment window: refresh-awards Sun 07:00→11:30, verify-websites Sun 06:00→11:00, scrape-social --force Sat 06:30→12:00, backfill-photos --verify Wed 07:30→12:30, coverage (read-only) Mon 06:30→11:00.
- Extended `SchedulerCollisionTest` to cover WEEKLY jobs too (regex now accepts `M H * * W` as well as `M H * * *`), so any daily OR weekly job scheduled inside the window fails the guard. Exemptions unchanged (ai-enrich, uptime:canary). Verified it fails when a weekly job is moved back into the window, passes otherwise.
- Updated the stale comments on the moved weekly jobs (all now reference the post-enrichment window).

Next: item 5 — run `scheduler:report` on the droplet to confirm all 16 scheduled commands fire on time. (All collision work under item 3 — daily + weekly — is now complete.)

Gotchas: The generalized test regex `^(\d+)\s+(\d+)\s+\*\s+\*\s+(\*|\d+)$` still returns null for interval expressions like ai-enrich (`0 */6 * * *`) and uptime (`*/15 * * * *`), so the exemptions remain naturally skipped. Weekly times chosen to sit in gaps between adjacent daily jobs and clear of each other on their shared days.

## Log
- Iteration: moved 4 daily jobs out of the enrichment window; added SchedulerCollisionTest (regression guard); fixed stale schedule docs/comments.
- Iteration: moved all 5 weekly jobs out of the enrichment window; extended SchedulerCollisionTest to guard weekly jobs too; updated stale weekly-job comments.
