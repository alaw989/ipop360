# Iteration Notes

## Goal
pull the latest prod DB to local: mysqldump prod ipop360 excluding runtime tables (pulse_*, sessions, cache*, jobs*, failed_jobs, password_reset_tokens) → ~16MB core; restore into local MySQL; verify restaurants count ≈ 8,282 and the dev server on :8090 serves real data

## State
Prod MySQL pulled → local MySQL. Local restaurants now **37,623 == prod**.
Dev server on :8090 serves real data (`api/restaurants` total=37623).
DONE. Note: goal's "≈ 8,282" is STALE — prod grew 8,282→37,623 since 08-15
(data-gap remediation + ingestion added ~29k). Correct success criterion is
"local == prod", which holds exactly.

## Log
- it1: mysqldump prod (excl pulse_*, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, password_reset_tokens) → 10.8MB gz; scp; restore into local MySQL `ipop360`; count 37623==37623; started `php artisan serve --port=8090`, api/restaurants total=37623. Gotcha: prod is live (count drifts); local had stale 37,543 already (prior partial sync).
