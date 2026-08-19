<?php

namespace App\Console\Commands;

use App\Notifications\SchedulerHealthAlert;
use App\Support\SchedulerProblemDetector;
use App\Support\SchedulerTelemetryReport;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/**
 * Operational alert gate for the scheduler. Reuses the shared
 * {@see SchedulerProblemDetector} to flag any command that never fired, failed,
 * hung, ran off-schedule, stopped firing, or over-fired in the window, and —
 * when a problem exists — emails the configured operator addresses. Exits
 * FAILURE on a problem so it can also run manually / from CI. Read-only: no DB
 * writes, no network beyond the mail channel.
 */
class SchedulerHealthCommand extends Command
{
    protected $signature = 'scheduler:health
                            {--days=1 : Number of days of telemetry to analyze}
                            {--drift-tolerance=15 : Minutes a start may deviate from its cron slot before being flagged off-schedule}';

    protected $description = 'Alert operators when any scheduled command has a problem (read-only)';

    public function handle(SchedulerTelemetryReport $report, SchedulerProblemDetector $detector): int
    {
        $days = max(1, (int) $this->option('days'));
        $tolerance = max(1, (int) $this->option('drift-tolerance'));

        $aggregates = $report->aggregate($days);
        $registered = $this->registeredCommands();
        // scheduler:health flags ITSELF as an unfinished run because its own
        // completion telemetry is written only after handle() returns. Exclude it
        // from the hung check so the daily alert isn't a self-referential false
        // positive (its failures are still reported).
        $detected = $detector->detect($aggregates, $registered, $tolerance, $days, now(), ['scheduler:health']);

        if (! $detected['has_problem']) {
            $this->info('Scheduler healthy: no problems in the last '.$days.' day(s).');

            return self::SUCCESS;
        }

        $problems = $this->categoryNames($detected);
        $this->notifyOperators(new SchedulerHealthAlert($problems, $days));

        $this->warn('Scheduler problems found — operator notified.');
        foreach ($problems as $category => $commands) {
            $this->warn(sprintf('  - %s: %s', $category, implode(', ', $commands)));
        }

        return self::FAILURE;
    }

    /**
     * @param  array{
     *     has_problem: bool,
     *     flagged: list<string>,
     *     never_fired: list<string>,
     *     failed: array<string, mixed>,
     *     hung: array<string, mixed>,
     *     off_schedule: array<string, float>,
     *     off_schedule_fires: array<string, mixed>,
     *     stopped_firing: array<string, float>,
     *     over_fired: array<string, mixed>,
     * }  $detected
     * @return array<string, list<string>> problem category => command names
     */
    private function categoryNames(array $detected): array
    {
        return array_filter([
            'never_fired' => $detected['never_fired'],
            'failed' => array_keys($detected['failed']),
            'unfinished_runs' => array_keys($detected['hung']),
            'off_schedule' => array_keys($detected['off_schedule']),
            'off_schedule_fires' => array_keys($detected['off_schedule_fires']),
            'stopped_firing' => array_keys($detected['stopped_firing']),
            'over_fired' => array_keys($detected['over_fired']),
        ], fn (array $commands) => $commands !== []);
    }

    private function notifyOperators(SchedulerHealthAlert $notification): void
    {
        $emails = (array) config('services.admin_notify_emails', []);

        if ($emails === []) {
            return;
        }

        Notification::route('mail', $emails)->notify($notification);
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
}
