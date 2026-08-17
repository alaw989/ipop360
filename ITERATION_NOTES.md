# Iteration Notes

## Goal
scheduler/infra hardening audit: instrument per-command runtime + failure telemetry; set explicit withoutOverlapping() expiries (or skipDuplicates); resolve the 5h35m throttled-enrichment collision with other daily jobs; verify cron + schedule:work redundancy is correct; confirm all 12 scheduled commands fire on time on the droplet

## State

**Changed this iteration (goal part 5 — automated on-time drift detection):**
- `scheduler:report` now computes a signed **drift** for every fired command —
  minutes between its `last_started_at` and the cron slot `CronExpression::getPreviousRunDate(now)`
  says it should have run (positive = late, negative = early). It prints a new
  **Drift (min)** column and a warning block "Commands that fired off-schedule
  (|drift| > N min)" listing each late/early command with magnitude + direction.
  New `--drift-tolerance=15` option tunes the flag threshold. This automates the
  "confirm every command fires on time" half of part 5 — the operator no longer
  eyeballs the Schedule-vs-Last-started columns, the report tells them directly
  which commands drifted off cadence.
- Tests (TDD): `test_command_flags_commands_that_fired_off_schedule` (canary
  started 06:00 vs `*/15` slot → flagged "off-schedule") and
  `test_command_does_not_flag_commands_fired_on_schedule` (started 10:00:00 vs
  10:00:30 now → clean), both using `travelTo()` + a fixed `started_at` so the
  cron reference is deterministic. SchedulerReportTest now 11/11 (36 assertions).
- Verified: full suite **863 passed (3705 assertions)**; Pint clean; PHPStan
  **No errors** (190/190). Live smoke: `uptime:canary` renders `+0.0` drift
  against `*/15 * * * *`; the other 15 still "NEVER FIRED" (times not yet elapsed
  since telemetry shipped).

**Prior work this goal (iteration 7 — Schedule column):**
- `scheduler:report` gained a **Schedule** column (cron expression) sourced from
  `Event::getExpression()`, so an operator can line up expected cadence vs actual
  `Last started`. `registeredCommands()` became `array<command, cron-expression>`.
- Test `test_command_lists_the_cron_expression_for_each_command` (renders
  `0 2 * * *`, `*/15 * * * *`, `0 4 * * *`). Also fixed 8 pre-existing PHPStan
  level-8 errors in `SchedulerReportTest.php` (PendingCommand `@var` chains,
  `telemetryLine()`/`writeLog()` param types).

**Prior work this goal (parts 1–5 tooling):**
- Added `scheduler:report {--days=7}` (new `SchedulerReportCommand`) backed by a
  new `SchedulerTelemetryReport` support class that parses the structured JSON
  `scheduler` telemetry log (`storage/logs/scheduler-YYYY-MM-DD.log`, written by
  the part-1 `SchedulerTelemetry` hooks) and aggregates per-command started /
  completed / failed counts, last-started timestamp, and min/avg/max wall-clock
  runtime. It cross-references those aggregates against the commands actually
  registered in `routes/console.php`, printing a table plus warning blocks:
  registered commands with **no telemetry in the window** ("NEVER FIRED"),
  commands with failures, and now commands with unfinished runs. Read-only, no
  DB/network.
- Reconciliation: the report prints "Registered commands: **16**". The goal's
  "12 scheduled commands" wording is stale — `schedule:list` registers 16 events
  (the 8 daily + 6 weekly + every-15-min + every-6-hour entries). Part 5 must
  treat the target as 16, not 12.
- Verified against real local telemetry: `php artisan scheduler:report --days=7`
  shows `uptime:canary` fired 4× today (0.22s each), the other 15 as
  "NEVER FIRED" (their daily/weekly times hadn't elapsed since telemetry
  shipped). Confirms the part-1 instrumentation is actually landing in the log.
- Tests (TDD): `tests/Feature/SchedulerReportTest.php` — 6 tests covering
  aggregation, php/artisan prefix normalization, window filtering, malformed/
  non-telemetry line tolerance, never-fired flagging, and failure reporting.
  Full suite 858 passed (3690 assertions). Pint + PHPStan clean on the new files.
- Iteration 6 added a third warning block: commands where `started > completed +
  failed` are flagged "unfinished runs (hung or still-running)" — the signal a
  hard-crashed run (that the part-2 expiries are meant to unblock) leaves in
  telemetry. 2 tests (`test_command_reports_unfinished_runs`,
  `test_command_does_not_report_finished_runs_as_unfinished`).

**Gotchas / findings for the rest of the goal:**
- The goal says "12 scheduled commands" but `routes/console.php` registers **16**
  (`schedule:list` confirms). Audit part 5 must reconcile this count.
- `Event::command` stores the fully-qualified `"{phpBinary} {artisanBinary} …"`
  string, not the bare command — the test strips it with a regex. Same pattern
  applies to any future schedule-introspection test.
- `combos_per_run=60` is a SWAG for "finishes before 05:00" — the real per-combo
  cost varies (45s–2.5min in the local log). Tune via `ENRICH_COMBOS_PER_RUN`
  against live scheduler telemetry (part 1) once it's been collecting a few days.
- The supervisor program is only *de-provisioned* when the deploy runs (the
  removal step is `continue-on-error`). Until then it is still running on the
  droplet; the drop-off is a deploy-time side effect, not a pre-deploy change.
- `tests/Unit/ExternalApiCacheStatsTest::test_stats_counts_serpapi_calls_within_30_days_only`
  is flaky in the full suite (passes in isolation). The boundary entry's
  `fetched_at = now()->subDays(30)` is compared against `now()->subDays(30)`
  inside `stats()`; SQLite truncates both to whole seconds, so it toggles when a
  second boundary falls between `create()` and `stats()`. Unrelated to the
  scheduler work — note only, do not fold a fix into this goal.

**Next (part 5 — remaining):** the live on-droplet confirmation is still open.
The report tool is done (Schedule column + Drift column + off-schedule/failure/
hung/never-fired blocks all automated), but the telemetry hasn't shipped yet
(deploy is operator-gated). Once the loop's changes deploy, run
`php artisan scheduler:report --days=7` on the droplet and confirm every command
has started+completed records with sane runtimes, zero failures, zero hung runs,
and no "off-schedule" flags. Watch for:
- the 05:00 sitemap / 05:30 social / 06:00 website window — verify the 04:00
  throttled enrichment actually finishes before 05:00 now that `combos_per_run`
  is capped (see part-3 gotcha).
- `withoutOverlapping` expiries were added for all 16 commands (part 2), so a
  crashed run should never block the next day's tick — confirm no command shows
  `started` without a matching `completed`/`failed` (a hung/crashed run).

## Log

- Iteration 1: added scheduler telemetry helper + channel + wiring + tests (part 1).
- Iteration 2: added explicit `withoutOverlapping()` expiries to all 16 commands + expiry test (part 2).
- Iteration 3: capped the throttled-enrichment combo grid (`combos_per_run` config + cap in `enrichAllCitiesThrottled`) so the run fits before 05:00 (part 3).
- Iteration 4: verified cron + schedule:work were BOTH driving the scheduler; removed the redundant `schedule:work` supervisor program, made cron the single driver (part 4).
- Iteration 5: added `scheduler:report` command + `SchedulerTelemetryReport` parser (part 5 tooling); reconciled the 12-vs-16 command count.
- Iteration 6: added hung-run detection (started > completed+failed) to `scheduler:report` + 2 tests (part 5).
- Iteration 7: added the Schedule (cron expression) column to `scheduler:report` for on-time cross-reference + 1 test; fixed 8 pre-existing PHPStan level-8 errors in the scheduler report test (part 5).
- Iteration 8: added automated on-time drift detection (Drift column + off-schedule warning block + `--drift-tolerance`) to `scheduler:report` + 2 tests (part 5).
