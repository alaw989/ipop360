<?php

namespace App\Console\Commands;

use App\Support\SchedulerProblemDetector;
use App\Support\SchedulerTelemetryReport;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

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

    public function handle(SchedulerTelemetryReport $report, SchedulerProblemDetector $detector): int
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
                ? $detector->driftMinutes($expression, (string) $agg['last_started_at'], $now)
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

        $problems = $detector->detect($aggregates, $registered, $tolerance, $days, $now);
        $hasProblem = $problems['has_problem'];
        $neverFired = $problems['never_fired'];
        $withFailures = $problems['failed'];
        $hung = $problems['hung'];
        $offSchedule = $problems['off_schedule'];
        $offScheduleFires = $problems['off_schedule_fires'];
        $stale = $problems['stopped_firing'];
        $overFired = $problems['over_fired'];
        $flaggedCommands = $problems['flagged'];

        if ($neverFired !== []) {
            if (! $json) {
                $this->newLine();
                $this->warn('Registered commands with NO telemetry in window:');
                foreach ($neverFired as $command) {
                    $this->warn('  - '.$command);
                }
            }
        }

        if ($withFailures !== []) {
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

        if ($hung !== []) {
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

        if ($offSchedule !== []) {
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

        if ($offScheduleFires !== []) {
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

        if ($stale !== []) {
            if (! $json) {
                $this->newLine();
                $this->warn('Commands that stopped firing (last fire > 1.5x cadence ago):');
                foreach ($stale as $command => $ageMinutes) {
                    $this->warn(sprintf('  - %s: last fired %.1f hours ago', $command, $ageMinutes / 60));
                }
            }
        }

        if ($overFired !== []) {
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
                detector: $detector,
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
     * Emit the report as a single JSON document so automation/CI can consume the
     * item-5 "confirm all 16 fire on time" verdict programmatically (exit code is
     * still governed by --exit-on-problem).
     *
     * @param  array<string, string>  $registered  bare command => cron expression
     * @param  array<string, array{started:int, structured_started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string, last_failure_output:string|null}>  $aggregates
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
        SchedulerProblemDetector $detector,
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
                    ? $detector->driftMinutes($expression, (string) $agg['last_started_at'], now())
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
