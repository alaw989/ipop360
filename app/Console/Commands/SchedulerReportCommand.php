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
                            {--drift-tolerance=15 : Minutes a start may deviate from its cron slot before being flagged off-schedule}';

    protected $description = 'Report per-command scheduler fire/runtime/failure telemetry (read-only)';

    public function handle(SchedulerTelemetryReport $report): int
    {
        $days = max(1, (int) $this->option('days'));
        $tolerance = max(1, (int) $this->option('drift-tolerance'));
        $now = now();
        $aggregates = $report->aggregate($days);
        $registered = $this->registeredCommands();

        $this->newLine();
        $this->line('<options=bold>Scheduler telemetry — last '.$days.' day(s)</>');
        $this->line(sprintf(
            'Registered commands: %d | Commands with telemetry: %d',
            count($registered),
            count($aggregates),
        ));
        $this->newLine();

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

        $this->table(
            ['Command', 'Schedule', 'Started', 'Completed', 'Failed', 'Last started', 'Drift (min)', 'Runtime (min/avg/max s)', 'Note'],
            $rows,
        );

        $neverFired = array_values(array_filter(
            array_keys($registered),
            fn (string $command) => ! isset($aggregates[$command]),
        ));
        if ($neverFired !== []) {
            $this->newLine();
            $this->warn('Registered commands with NO telemetry in window:');
            foreach ($neverFired as $command) {
                $this->warn('  - '.$command);
            }
        }

        $withFailures = array_filter($aggregates, fn (array $agg) => $agg['failed'] > 0);
        if ($withFailures !== []) {
            $this->newLine();
            $this->warn('Commands with failures in window:');
            foreach ($withFailures as $command => $agg) {
                $this->warn(sprintf('  - %s: %d failed', $command, $agg['failed']));
            }
        }

        // Structured telemetry alone can attest completion; commands ingested
        // only from the raw cron-redirect log (source=raw) can't attribute the
        // trailing `... DONE`, so skip them here to avoid false "hung" flags.
        $hung = array_filter(
            $aggregates,
            fn (array $agg) => ($agg['source'] ?? 'structured') === 'structured'
                && $agg['started'] > ($agg['completed'] + $agg['failed']),
        );
        if ($hung !== []) {
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

        $this->newLine();

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
}
