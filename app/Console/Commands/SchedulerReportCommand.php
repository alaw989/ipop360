<?php

namespace App\Console\Commands;

use App\Support\SchedulerTelemetryReport;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/**
 * Report per-command scheduler fire/runtime/failure telemetry.
 *
 * Reads the structured `scheduler` telemetry log (JSON, daily) and cross-
 * references it against the commands actually registered in routes/console.php,
 * so an operator can confirm every scheduled command fires on time, spot
 * failures, and see per-command wall-clock runtimes at a glance. Read-only:
 * no DB writes, no network calls.
 */
class SchedulerReportCommand extends Command
{
    protected $signature = 'scheduler:report
                            {--days=7 : Number of days of telemetry to analyze}
                            {--drift-tolerance=15 : Minutes a start may deviate from its cron slot before being flagged off-schedule}
                            {--exit-on-problem : Return a non-zero exit code if any command never fired, failed, or ran off-schedule}
                            {--json : Emit a single JSON document instead of the human-readable table}';

    protected $description = 'Report per-command scheduler fire/runtime/failure telemetry (read-only)';

    public function handle(SchedulerTelemetryReport $report): int
    {
        $days = max(1, (int) $this->option('days'));
        $tolerance = max(1, (int) $this->option('drift-tolerance'));
        $exitOnProblem = (bool) $this->option('exit-on-problem');
        $json = (bool) $this->option('json');
        $now = now();
        $aggregates = $report->aggregate($days);
        $registered = $this->registeredCommands();

        $hasProblem = false;

        if (! $json) {
            $this->newLine();
            $this->line('<options=bold>Scheduler telemetry — last '.$days.' day(s)</>');
            $this->line(sprintf(
                'Registered commands: %d | Commands with telemetry: %d',
                count($registered),
                count($aggregates),
            ));
            $this->newLine();
        }

        $rows = [];

        foreach ($registered as $command => $expression) {
            $agg = $aggregates[$command] ?? null;

            $drift = ($agg['last_started_at'] ?? null) !== null
                ? $this->driftMinutes($expression, (string) $agg['last_started_at'], $now)
                : null;

            $rows[] = [
                $command,
                $expression,
                (string) ($agg['started'] ?? 0),
                (string) ($agg['completed'] ?? 0),
                (string) ($agg['failed'] ?? 0),
                (string) ($agg['last_started_at'] ?? '—'),
                $drift !== null ? sprintf('%+.1f', $drift) : '—',
                $agg ? $this->runtimeSummary($agg['runtimes']) : '—',
                $agg ? '' : 'NEVER FIRED',
            ];
        }

        foreach ($aggregates as $command => $agg) {
            if (array_key_exists($command, $registered)) {
                continue;
            }

            $rows[] = [
                $command,
                '—',
                (string) $agg['started'],
                (string) $agg['completed'],
                (string) $agg['failed'],
                (string) ($agg['last_started_at'] ?? '—'),
                '—',
                $this->runtimeSummary($agg['runtimes']),
                'NOT REGISTERED',
            ];
        }

        if (! $json) {
            $this->table(
                ['Command', 'Schedule', 'Started', 'Completed', 'Failed', 'Last started', 'Drift (min)', 'Runtime (min/avg/max s)', 'Note'],
                $rows,
            );
        }

        $neverFired = array_values(array_filter(
            array_keys($registered),
            fn (string $command) => ! isset($aggregates[$command]),
        ));
        if ($neverFired !== []) {
            $hasProblem = true;
            if (! $json) {
                $this->newLine();
                $this->warn('Registered commands with NO telemetry in window:');
                foreach ($neverFired as $command) {
                    $this->warn('  - '.$command);
                }
            }
        }

        $withFailures = array_filter($aggregates, fn (array $agg) => $agg['failed'] > 0);
        if ($withFailures !== []) {
            $hasProblem = true;
            if (! $json) {
                $this->newLine();
                $this->warn('Commands with failures in window:');
                foreach ($withFailures as $command => $agg) {
                    $this->warn(sprintf('  - %s: %d failed', $command, $agg['failed']));
                    if (is_string($agg['last_failure_output'] ?? null) && ($agg['last_failure_output'] !== '')) {
                        $this->line('      last failure output: '.$this->shorten((string) $agg['last_failure_output']));
                    }
                }
            }
        }

        // Structured telemetry alone can attest completion; commands ingested
        // only from the raw cron-redirect log (source=raw) can't attribute the
        // trailing `... DONE`, so skip them here to avoid false "hung" flags.
        $hung = array_filter(
            $aggregates,
            fn (array $agg) => $agg['source'] === 'structured'
                && $agg['started'] > ($agg['completed'] + $agg['failed']),
        );
        if ($hung !== []) {
            $hasProblem = true;
            if (! $json) {
                $this->newLine();
                $this->warn('Commands with unfinished runs (started without completed/failed — hung or still-running):');
                foreach ($hung as $command => $agg) {
                    $this->warn(sprintf(
                        '  - %s: %d started, %d finished',
                        $command,
                        $agg['started'],
                        $agg['completed'] + $agg['failed'],
                    ));
                }
            }
        }

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
        if ($offSchedule !== []) {
            $hasProblem = true;
            if (! $json) {
                $this->newLine();
                $this->warn(sprintf('Commands that fired off-schedule (|drift| > %d min):', $tolerance));
                foreach ($offSchedule as $command => $drift) {
                    $this->warn(sprintf(
                        '  - %s: %.1f min %s',
                        $command,
                        abs($drift),
                        $drift >= 0 ? 'late' : 'early',
                    ));
                }
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
        if ($offScheduleFires !== []) {
            $hasProblem = true;
            if (! $json) {
                $this->newLine();
                $this->warn(sprintf('Individual fires that ran off their own cron slot (|drift| > %d min):', $tolerance));
                foreach ($offScheduleFires as $command => $fires) {
                    foreach ($fires as $fire) {
                        $this->warn(sprintf(
                            '  - %s @ %s: %.1f min %s',
                            $command,
                            $fire['at'],
                            abs($fire['drift']),
                            $fire['drift'] >= 0 ? 'late' : 'early',
                        ));
                    }
                }
            }
        }

        // A command can fire on-schedule at its slot and STILL be a problem if it
        // has since gone silent. The drift checks above flag a *wrong* fire time;
        // this flags a *missing* cycle — a command whose latest fire is more than
        // 1.5x its own cadence in the past (it stopped firing).
        $stale = [];
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
                $stale[$command] = $ageMinutes;
            }
        }
        if ($stale !== []) {
            $hasProblem = true;
            if (! $json) {
                $this->newLine();
                $this->warn('Commands that stopped firing (last fire > 1.5x cadence ago):');
                foreach ($stale as $command => $ageMinutes) {
                    $this->warn(sprintf('  - %s: last fired %.1f hours ago', $command, $ageMinutes / 60));
                }
            }
        }

        // The drift/stale checks above can't see a command that fires TOO often:
        // a daily job that fires twice each day still looks healthy on every
        // per-fire drift and has a fresh last_started_at. That is the signature
        // of a redundant second scheduler instance (e.g. cron AND schedule:work
        // both running) — item 4. Flag any command whose fires exceed the number
        // of cron slots its expression provides in the window.
        $overFired = [];
        foreach ($registered as $command => $expression) {
            $fires = count($aggregates[$command]['started_ats'] ?? []);
            if ($fires === 0) {
                continue;
            }
            $expected = $this->expectedFiresInWindow($expression, now()->subDays($days)->startOfDay(), $now);
            if ($expected >= 0 && $fires > $expected) {
                $overFired[$command] = ['fires' => $fires, 'expected' => $expected];
            }
        }
        if ($overFired !== []) {
            $hasProblem = true;
            if (! $json) {
                $this->newLine();
                $this->warn('Commands that fired MORE often than their cron slot allows (redundant scheduler / double-run):');
                foreach ($overFired as $command => $info) {
                    $this->warn(sprintf(
                        '  - %s: %d fires in window, cron slot allows %d',
                        $command,
                        $info['fires'],
                        $info['expected'],
                    ));
                }
            }
        }

        $notRegistered = array_values(array_filter(
            array_keys($aggregates),
            fn (string $command) => ! isset($registered[$command]),
        ));

        $flaggedCommands = array_values(array_unique(array_merge(
            $neverFired,
            array_keys($withFailures),
            array_keys($hung),
            array_keys($offSchedule),
            array_keys($offScheduleFires),
            array_keys($stale),
            array_keys($overFired),
        )));

        if ($this->option('json')) {
            return $this->renderJson(
                registered: $registered,
                aggregates: $aggregates,
                days: $days,
                hasProblem: $hasProblem,
                flaggedCommands: $flaggedCommands,
                neverFired: $neverFired,
                withFailures: array_keys($withFailures),
                hung: array_keys($hung),
                offSchedule: array_keys($offSchedule),
                offScheduleFires: array_keys($offScheduleFires),
                stale: array_keys($stale),
                overFired: array_keys($overFired),
                notRegistered: $notRegistered,
                exitOnProblem: $exitOnProblem,
            );
        }

        $this->newLine();
        if ($hasProblem) {
            $this->warn(sprintf(
                'Verdict: %d of %d registered commands have a problem (see above); %d healthy.',
                count($flaggedCommands),
                count($registered),
                count($registered) - count($flaggedCommands),
            ));
        } else {
            $this->info(sprintf(
                'Verdict: all %d registered commands healthy in the last %d day(s).',
                count($registered),
                $days,
            ));
        }

        $this->newLine();

        if ($exitOnProblem && $hasProblem) {
            $this->warn('scheduler:report --exit-on-problem: problems found (see above).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> Bare artisan command + args => cron expression,
     *                               for every scheduled event, sorted by command.
     */
    private function registeredCommands(): array
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        return collect($schedule->events())
            ->mapWithKeys(fn ($event) => [
                (string) preg_replace('/^\S+\s+\S+\s+/', '', $event->command ?? '') => $event->getExpression(),
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  list<float>  $runtimes
     */
    private function runtimeSummary(array $runtimes): string
    {
        if ($runtimes === []) {
            return '—';
        }

        $min = min($runtimes);
        $max = max($runtimes);
        $avg = array_sum($runtimes) / count($runtimes);

        return sprintf('%.2f / %.2f / %.2f', $min, $avg, $max);
    }

    /**
     * Collapse a long failure output to a single readable line for the human
     * report (newlines → spaces, trimmed). The full value stays in --json.
     */
    private function shorten(string $output): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $output));
    }

    /**
     * Signed minutes between a command's last start and its expected cron slot
     * (positive = late, negative = early). Returns null if the timestamp is
     * unparseable or the cron expression is invalid.
     */
    private function driftMinutes(string $expression, string $lastStartedAt, \DateTimeInterface $now): ?float
    {
        try {
            $expected = CronExpression::factory($expression)->getPreviousRunDate($now);
            $actual = Carbon::parse($lastStartedAt);

            return $actual->diffInMinutes($expected, false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cadence (period) of a cron expression in minutes — the gap between a
     * reference instant and its next matching run. Used to judge whether a
     * command has gone a full cycle (or more) without firing. Returns null if
     * the expression is invalid or yields no next run.
     */
    private function cadenceMinutes(string $expression, \DateTimeInterface $reference): ?float
    {
        try {
            $next = CronExpression::factory($expression)->getNextRunDate($reference);

            return abs(Carbon::instance($next)->diffInMinutes($reference, false));
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

    /**
     * Emit the report as a single JSON document so automation/CI can consume the
     * item-5 "confirm all 16 fire on time" verdict programmatically (exit code is
     * still governed by --exit-on-problem).
     *
     * @param  array<string, string>  $registered  bare command => cron expression
     * @param  array<string, array{started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string, last_failure_output:string|null}>  $aggregates
     * @param  list<string>  $flaggedCommands
     * @param  list<string>  $neverFired
     * @param  list<string>  $withFailures
     * @param  list<string>  $hung
     * @param  list<string>  $offSchedule
     * @param  list<string>  $offScheduleFires
     * @param  list<string>  $stale
     * @param  list<string>  $overFired
     * @param  list<string>  $notRegistered
     */
    private function renderJson(
        array $registered,
        array $aggregates,
        int $days,
        bool $hasProblem,
        array $flaggedCommands,
        array $neverFired,
        array $withFailures,
        array $hung,
        array $offSchedule,
        array $offScheduleFires,
        array $stale,
        array $overFired,
        array $notRegistered,
        bool $exitOnProblem,
    ): int {
        $commands = [];
        foreach ($registered as $command => $expression) {
            $agg = $aggregates[$command] ?? null;
            $runtimes = $agg['runtimes'] ?? [];
            $commands[] = [
                'command' => $command,
                'schedule' => $expression,
                'started' => $agg['started'] ?? 0,
                'completed' => $agg['completed'] ?? 0,
                'failed' => $agg['failed'] ?? 0,
                'last_started_at' => $agg['last_started_at'] ?? null,
                'never_fired' => $agg === null,
                'runtime_seconds' => $runtimes === [] ? null : [
                    'min' => min($runtimes),
                    'avg' => array_sum($runtimes) / count($runtimes),
                    'max' => max($runtimes),
                ],
                'last_drift_minutes' => ($agg['last_started_at'] ?? null) !== null
                    ? $this->driftMinutes($expression, (string) $agg['last_started_at'], now())
                    : null,
                'last_failure_output' => $agg['last_failure_output'] ?? null,
            ];
        }

        $this->line(json_encode([
            'days' => $days,
            'healthy' => ! $hasProblem,
            'registered_count' => count($registered),
            'healthy_count' => count($registered) - count($flaggedCommands),
            'problem_count' => count($flaggedCommands),
            'problems' => [
                'never_fired' => $neverFired,
                'failed' => $withFailures,
                'unfinished_runs' => $hung,
                'off_schedule' => $offSchedule,
                'off_schedule_fires' => $offScheduleFires,
                'stopped_firing' => $stale,
                'over_fired' => $overFired,
            ],
            'unregistered' => $notRegistered,
            'commands' => $commands,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $exitOnProblem && $hasProblem ? self::FAILURE : self::SUCCESS;
    }
}
