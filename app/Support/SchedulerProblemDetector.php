<?php

namespace App\Support;

use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Categorizes per-command scheduler telemetry into the problem set that both the
 * human `scheduler:report` tool and the operational `scheduler:health` alert
 * gate consume. Extracted so the bug-prone drift/cadence/fire-count logic lives
 * in exactly one place instead of being duplicated across two commands.
 *
 * Read-only: pure computation over the aggregated telemetry + registered
 * schedule map.
 */
final class SchedulerProblemDetector
{
    /**
     * @param  array<string, array{started:int, structured_started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string, last_failure_output:string|null}>  $aggregates
     * @param  array<string, string>  $registered  bare command => cron expression
     * @param  int  $days  Telemetry window length (days) — bounds the over-fired
     *                     slot-count so it is consistent with the report window.
     * @param  list<string>  $excludeFromHung  Commands to skip in the hung/
     *                                         unfinished and failed checks —
     *                                         the reporter command
     *                                         (scheduler:health) is
     *                                         self-referential in both: its
     *                                         own completion is recorded only
     *                                         after it returns, and its
     *                                         deliberate non-zero exit on a
     *                                         detected problem isn't a crash.
     * @param  array<string, int>  $expiryMinutes  bare command => its own
     *                                             withoutOverlapping() mutex
     *                                             TTL in minutes. Used as the
     *                                             hung-check grace period: a
     *                                             command that's still within
     *                                             its own declared runtime
     *                                             budget is legitimately
     *                                             still running, not hung.
     * @return array{
     *     has_problem: bool,
     *     flagged: list<string>,
     *     never_fired: list<string>,
     *     failed: array<string, array{started:int, structured_started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string, last_failure_output:string|null}>,
     *     hung: array<string, array{started:int, structured_started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string, last_failure_output:string|null}>,
     *     off_schedule: array<string, float>,
     *     off_schedule_fires: array<string, list<array{at:string, drift:float}>>,
     *     stopped_firing: array<string, float>,
     *     over_fired: array<string, array{fires:int, expected:int}>,
     * }
     */
    public function detect(array $aggregates, array $registered, int $tolerance, int $days, \DateTimeInterface $now, array $excludeFromHung = [], array $expiryMinutes = []): array
    {
        $neverFired = array_values(array_filter(
            array_keys($registered),
            // A command is only "never fired" if it had at least one scheduled
            // slot within the window but produced no fire. Weekly commands (e.g.
            // refresh-awards on Sunday) have no slot in a 1-day window, so they
            // must NOT be flagged — otherwise the daily health alert is pure noise
            // six days out of seven.
            fn (string $command) => ! isset($aggregates[$command])
                && $this->expectedFiresInWindow($registered[$command], $this->windowStart($now, $days), $now) > 0,
        ));

        // scheduler:health itself exits FAILURE whenever it detects a real
        // problem elsewhere — that's a deliberate signal, not a crash, but
        // telemetry logs it identically to one. Without this exclusion it
        // would flag itself `failed` on every subsequent run for as long as
        // any other problem persists in the window.
        $failed = array_filter(
            array_diff_key($aggregates, array_fill_keys($excludeFromHung, true)),
            fn (array $agg) => $agg['failed'] > 0,
        );

        // Only runs whose START was recorded by structured telemetry can have
        // their completion attested. Raw cron-redirect fires can't name their
        // trailing `... DONE`, so mixing raw-era starts with structured
        // completions would false-flag every command once telemetry is live on
        // top of raw history. Compare structured starts against (attributable)
        // completions + failures only.
        //
        // A dangling start isn't necessarily hung — it may simply still be
        // running. Only flag it once its own declared withoutOverlapping()
        // mutex would have expired (its real worst-case runtime budget);
        // before that it's indistinguishable from a healthy long run. E.g.
        // restaurants:backfill-websites fires at 11:45 with a 240-minute
        // mutex (worst case 15:45); scheduler:health checks at 15:00 — a run
        // still inside its own budget at check time is not a problem.
        $hung = array_filter(
            array_diff_key($aggregates, array_fill_keys($excludeFromHung, true)),
            function (array $agg, string $command) use ($expiryMinutes, $now) {
                if ($agg['structured_started'] <= ($agg['completed'] + $agg['failed'])) {
                    return false;
                }

                $last = $agg['last_started_at'] ?? null;
                if ($last === null) {
                    return true;
                }

                $expiry = $expiryMinutes[$command] ?? 0;

                return Carbon::parse($last)->diffInMinutes($now, false) > $expiry;
            },
            ARRAY_FILTER_USE_BOTH,
        );

        $offSchedule = [];
        foreach ($registered as $command => $expression) {
            $last = $aggregates[$command]['last_started_at'] ?? null;
            if ($last === null) {
                continue;
            }
            $drift = $this->driftMinutes($expression, (string) $last, $now);
            if ($drift !== null && abs($drift) > $tolerance) {
                $offSchedule[$command] = $drift;
            }
        }

        // The block above only inspects the *most recent* fire, so a command
        // that fired on time today but late earlier in the window would slip
        // through. Re-check every individual fire against its own cron slot.
        $offScheduleFires = [];
        foreach ($registered as $command => $expression) {
            foreach ($aggregates[$command]['started_ats'] ?? [] as $startedAt) {
                $drift = $this->fireDriftMinutes($expression, $startedAt);
                if ($drift !== null && abs($drift) > $tolerance) {
                    $offScheduleFires[$command][] = ['at' => $startedAt, 'drift' => $drift];
                }
            }
        }

        // A command can fire on-schedule at its slot and STILL be a problem if
        // it has since gone silent. The drift checks above flag a *wrong* fire
        // time; this flags a *missing* cycle — a command whose latest fire is
        // more than 1.5x its cadence in the past (it stopped firing).
        $stoppedFiring = [];
        foreach ($registered as $command => $expression) {
            $last = $aggregates[$command]['last_started_at'] ?? null;
            if ($last === null) {
                continue;
            }
            $lastAt = Carbon::parse($last);
            $cadence = $this->cadenceMinutes($expression, $lastAt);
            if ($cadence === null) {
                continue;
            }
            $ageMinutes = $lastAt->diffInMinutes($now, false);
            if ($ageMinutes > $cadence * 1.5) {
                $stoppedFiring[$command] = $ageMinutes;
            }
        }

        // The drift/stale checks above can't see a command that fires TOO often:
        // a daily job that fires twice each day still looks healthy on every
        // per-fire drift and has a fresh last_started_at. That is the signature
        // of a redundant second scheduler instance (e.g. cron AND schedule:work
        // both running). Flag any command whose fires exceed the number of cron
        // slots its expression provides in the window.
        $overFired = [];
        foreach ($registered as $command => $expression) {
            $fires = count($aggregates[$command]['started_ats'] ?? []);
            if ($fires === 0) {
                continue;
            }
            $expected = $this->expectedFiresInWindow($expression, $this->windowStart($now, $days), $now);
            if ($expected >= 0 && $fires > $expected) {
                $overFired[$command] = ['fires' => $fires, 'expected' => $expected];
            }
        }

        $flagged = array_values(array_unique(array_merge(
            $neverFired,
            array_keys($failed),
            array_keys($hung),
            array_keys($offSchedule),
            array_keys($offScheduleFires),
            array_keys($stoppedFiring),
            array_keys($overFired),
        )));

        return [
            'has_problem' => $flagged !== [],
            'flagged' => $flagged,
            'never_fired' => $neverFired,
            'failed' => $failed,
            'hung' => $hung,
            'off_schedule' => $offSchedule,
            'off_schedule_fires' => $offScheduleFires,
            'stopped_firing' => $stoppedFiring,
            'over_fired' => $overFired,
        ];
    }

    /**
     * Window start for the over-fired count: the start of the earliest day
     * covered by the `--days` window, so the number of cron slots is bounded
     * consistently with the report's aggregation window.
     */
    private function windowStart(\DateTimeInterface $now, int $days): Carbon
    {
        return Carbon::instance($now)->subDays($days)->startOfDay();
    }

    /**
     * Signed minutes between a command's last start and its expected cron slot
     * (positive = late, negative = early). Uses allowCurrentDate=true so a fire
     * landing on the current slot-minute reads as 0 drift rather than a full
     * cadence early — without it, a command whose slot is the current minute
     * (e.g. scheduler:health checking itself at 15:00) would be false-flagged a
     * day off. Returns null if the timestamp is unparseable or the cron
     * expression is invalid. Public so the report's JSON per-command
     * `last_drift_minutes` uses the same helper as detection.
     */
    public function driftMinutes(string $expression, string $lastStartedAt, \DateTimeInterface $now): ?float
    {
        try {
            $expected = CronExpression::factory($expression)->getPreviousRunDate($now, 0, true);
            $actual = Carbon::parse($lastStartedAt);

            return $actual->diffInMinutes($expected, false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cadence (period) of a cron expression in minutes — the gap between two
     * consecutive scheduled runs. Used to judge whether a command has gone a full
     * cycle (or more) without firing. Returns null if the expression is invalid
     * or yields no next run.
     *
     * The period is measured between two consecutive *scheduled* runs, never
     * from the actual (possibly off-slot) fire time — otherwise a command that
     * fired early (e.g. right after a schedule migration) would yield a tiny
     * period (fire → next slot) and be false-flagged as stopped firing.
     */
    private function cadenceMinutes(string $expression, \DateTimeInterface $reference): ?float
    {
        try {
            $cron = CronExpression::factory($expression);
            $first = Carbon::instance($cron->getNextRunDate($reference));
            $second = Carbon::instance($cron->getNextRunDate($first));

            return $first->diffInMinutes($second, false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Signed minutes between a single fire and its own expected cron slot
     * (positive = late, negative = early). Uses allowCurrentDate=true so a fire
     * landing exactly on its slot boundary reads as 0 drift rather than +period.
     * Returns null if the timestamp is unparseable or the cron expression invalid.
     */
    private function fireDriftMinutes(string $expression, string $startedAt): ?float
    {
        try {
            $actual = Carbon::parse($startedAt);
            $expected = CronExpression::factory($expression)->getPreviousRunDate($actual, 0, true);

            return Carbon::instance($expected)->diffInMinutes($actual, false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Number of times a cron expression is scheduled to run inside the half-open
     * interval [from, to). Used to judge whether a command has fired MORE often
     * than its schedule allows (a redundant-scheduler / double-run signal).
     * Returns -1 if the expression is invalid (caller treats -1 as "unknown").
     */
    private function expectedFiresInWindow(string $expression, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        try {
            $count = 0;
            $cursor = Carbon::instance($from);
            $end = Carbon::instance($to);
            $cron = CronExpression::factory($expression);

            for ($i = 0; $i < 10000; $i++) {
                $next = Carbon::instance($cron->getNextRunDate($cursor));
                if ($next >= $end) {
                    break;
                }
                $cursor = $next;
                $count++;
            }

            return $count;
        } catch (\Throwable) {
            return -1;
        }
    }
}
