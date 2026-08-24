<?php

namespace Tests\Feature;

use App\Support\SchedulerTelemetryReport;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SchedulerReportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/scheduler-report-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_aggregate_counts_started_completed_failed_and_runtimes(): void
    {
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'uptime:canary', ['started_at' => '2026-08-17T00:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'uptime:canary', ['runtime_seconds' => 0.22]),
            $this->telemetryLine('Scheduled command started', 'restaurants:score'),
            $this->telemetryLine('Scheduled command failed', 'restaurants:score', ['runtime_seconds' => 5.1]),
        ]);

        $report = new SchedulerTelemetryReport($this->dir);
        $aggregates = $report->aggregate(7);

        $this->assertArrayHasKey('uptime:canary', $aggregates);
        $this->assertSame(1, $aggregates['uptime:canary']['started']);
        $this->assertSame(1, $aggregates['uptime:canary']['completed']);
        $this->assertSame(0, $aggregates['uptime:canary']['failed']);
        $this->assertSame([0.22], $aggregates['uptime:canary']['runtimes']);

        $this->assertArrayHasKey('restaurants:score', $aggregates);
        $this->assertSame(1, $aggregates['restaurants:score']['started']);
        $this->assertSame(0, $aggregates['restaurants:score']['completed']);
        $this->assertSame(1, $aggregates['restaurants:score']['failed']);
        $this->assertSame([5.1], $aggregates['restaurants:score']['runtimes']);
    }

    public function test_aggregate_normalizes_the_php_artisan_prefix(): void
    {
        $phpBin = "'/usr/bin/php8.5'";
        $full = $phpBin." 'artisan' restaurants:scrape-social --force";

        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', $full),
            $this->telemetryLine('Scheduled command completed', $full, ['runtime_seconds' => 12.0]),
        ]);

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);

        $this->assertArrayHasKey('restaurants:scrape-social --force', $aggregates);
        $this->assertArrayNotHasKey($full, $aggregates);
    }

    public function test_aggregate_skips_logs_outside_the_window(): void
    {
        $this->writeLog('scheduler-'.now()->subDays(10)->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score'),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);

        $this->assertSame([], $aggregates, 'logs older than the window must be excluded');
    }

    public function test_aggregate_ignores_non_telemetry_and_malformed_lines(): void
    {
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            'this is not json',
            $this->telemetryLine('Some unrelated message', 'restaurants:score'),
            $this->telemetryLine('Scheduled command started', 'uptime:canary'),
        ]);

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);

        $this->assertSame(['uptime:canary'], array_keys($aggregates));
        $this->assertSame(1, $aggregates['uptime:canary']['started']);
    }

    public function test_command_lists_registered_commands_and_flags_never_fired(): void
    {
        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('restaurants:score')
            ->expectsOutputToContain('NEVER FIRED')
            ->assertSuccessful();
    }

    public function test_command_lists_the_cron_expression_for_each_command(): void
    {
        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('0 2 * * *')    // restaurants:score dailyAt 02:00
            ->expectsOutputToContain('*/15 * * * *') // uptime:canary everyFifteenMinutes
            ->expectsOutputToContain('0 4 * * *')    // restaurants:enrich --throttled dailyAt 04:00
            ->assertSuccessful();
    }

    public function test_command_reports_all_registered_commands_in_the_header(): void
    {
        // The operational "confirm all commands fire on time" workflow depends
        // on scheduler:report LISTING every registered command in its header
        // count ("Registered commands: 18"), even when none have telemetry
        // yet. If the command set drifts, the count diverges and this fails.
        // (We assert the header count rather than each table cell — the
        // test's narrow BufferedOutput truncates long table cells, while the
        // header is rendered whole.)
        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('Registered commands: 18')
            ->expectsOutputToContain('NEVER FIRED')
            ->assertSuccessful();
    }

    public function test_exit_on_problem_returns_failure_when_commands_never_fired(): void
    {
        // The operational "confirm all 16 fire on time" workflow needs a
        // scriptable gate: with --exit-on-problem, a command that never fired
        // must fail the run so CI/alerting can act on it.
        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7, '--exit-on-problem' => true]);
        $command->expectsOutputToContain('NEVER FIRED')->assertFailed();
    }

    public function test_without_exit_flag_returns_success_even_with_problems(): void
    {
        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('NEVER FIRED')->assertSuccessful();
    }

    public function test_exit_on_problem_returns_success_when_all_commands_fired(): void
    {
        // A healthy window (every registered command fired on time, no failures)
        // must NOT fail the run even with --exit-on-problem. Because "never
        // fired" is itself a problem, every one of the 16 commands must appear.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $lines = [];
        foreach ($schedule->events() as $event) {
            // Real telemetry logs the full "{php} {artisan} <command>" string,
            // which the report normalizes down to the bare command + args. Mirror
            // that so commands with args (e.g. backfill-photos --apply) resolve
            // to the same key the report's registeredCommands() produces.
            $prefixed = "'/usr/bin/php' 'artisan' ".trim((string) preg_replace('/^\S+\s+\S+\s+/', '', $event->command ?? ''));
            $startedAt = CronExpression::factory($event->getExpression())
                ->getPreviousRunDate(now(), 0, true)
                ->format('Y-m-d\TH:i:s+00:00');
            $lines[] = $this->telemetryLine('Scheduled command started', $prefixed, ['started_at' => $startedAt]);
            $lines[] = $this->telemetryLine('Scheduled command completed', $prefixed, ['runtime_seconds' => 0.5]);
        }
        $this->writeLog('scheduler-2026-08-17.log', $lines);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7, '--exit-on-problem' => true]);
        $command->assertSuccessful();
    }

    public function test_command_reports_failures(): void
    {
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'apicache:gc'),
            $this->telemetryLine('Scheduled command failed', 'apicache:gc', ['runtime_seconds' => 1.5]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('apicache:gc')
            ->expectsOutputToContain('Commands with failures')
            ->assertSuccessful();
    }

    public function test_command_reports_unfinished_runs(): void
    {
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:enrich --throttled', ['started_at' => '2026-08-17T04:00:00+00:00']),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('restaurants:enrich --throttled')
            ->expectsOutputToContain('unfinished runs')
            ->assertSuccessful();
    }

    public function test_command_does_not_report_finished_runs_as_unfinished(): void
    {
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'uptime:canary', ['started_at' => '2026-08-17T00:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'uptime:canary', ['runtime_seconds' => 0.22]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('uptime:canary')
            ->doesntExpectOutputToContain('unfinished runs')
            ->assertSuccessful();
    }

    public function test_command_flags_commands_that_fired_off_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'uptime:canary', ['started_at' => '2026-08-17T06:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'uptime:canary', ['runtime_seconds' => 0.22]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('off-schedule')
            ->expectsOutputToContain('uptime:canary')
            ->assertSuccessful();
    }

    public function test_command_does_not_flag_commands_fired_on_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'uptime:canary', ['started_at' => '2026-08-17T10:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'uptime:canary', ['runtime_seconds' => 0.22]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->doesntExpectOutputToContain('off-schedule')
            ->assertSuccessful();
    }

    public function test_command_flags_an_earlier_fire_that_ran_late_when_latest_fire_is_on_time(): void
    {
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-16.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-16T02:30:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);
        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-17T02:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('ran off their own cron slot')
            ->expectsOutputToContain('30.0 min late')
            ->assertSuccessful();
    }

    public function test_command_flags_a_command_that_stopped_firing_as_stale(): void
    {
        // A daily command that fired on-schedule but then went silent for >1.5x
        // its cadence must be surfaced as a distinct "stopped firing" problem,
        // not just a confusing huge drift. (score is dailyAt 02:00; last fire 3
        // days ago is ~3 cycles behind.)
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-14.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-14T02:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('stopped firing')
            ->expectsOutputToContain('restaurants:score')
            ->assertSuccessful();
    }

    public function test_command_does_not_flag_a_command_that_fired_recently_as_stale(): void
    {
        // A daily command that last fired within its cadence (yesterday) is not
        // stale — even though it's not today's slot yet, it hasn't missed a cycle.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-16.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-16T02:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->doesntExpectOutputToContain('stopped firing')
            ->assertSuccessful();
    }

    public function test_command_does_not_flag_an_early_fire_as_stale(): void
    {
        // A daily command that fired EARLY (its last fire sits before the current
        // cron slot, e.g. right after a schedule migration) must not be flagged
        // "stopped firing". The cadence is the gap between two consecutive
        // SCHEDULED runs (1440 min for seo:sitemap), not the gap from the
        // off-slot fire to the next run (~315 min here) — otherwise a healthy
        // command that just fired this morning looks >1.5x cadence old.
        $this->travelTo(Carbon::parse('2026-08-17 14:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'seo:sitemap', ['started_at' => '2026-08-17T05:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'seo:sitemap', ['runtime_seconds' => 3.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->doesntExpectOutputToContain('stopped firing')
            ->assertSuccessful();
    }

    public function test_aggregate_keeps_the_newest_fire_and_only_structured_starts_count_as_unfinished(): void
    {
        // Hybrid window: a raw cron-redirect fire at 14:00 (pre-structured era,
        // completion unattributable) PLUS structured started/completed at 14:15.
        // The newest fire must win for last_started_at (not the oldest
        // non-deduped raw fire), and the raw-era start must not count against
        // the structured completion in the unfinished-run check.
        $this->travelTo(Carbon::parse('2026-08-17 14:20:00', 'UTC'));

        $this->writeLog('scheduler.log', [
            "  2026-08-17 14:00:01 Running ['artisan' uptime:canary] ........ 822ms DONE",
            "  2026-08-17 14:15:00 Running ['artisan' uptime:canary] ........ 822ms DONE",
        ]);
        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'uptime:canary', ['started_at' => '2026-08-17T14:15:02+00:00']),
            $this->telemetryLine('Scheduled command completed', 'uptime:canary', ['runtime_seconds' => 1.5]),
        ]);

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);

        // Raw 14:15 fire deduped against structured; raw 14:00 kept.
        $this->assertSame(2, $aggregates['uptime:canary']['started']);
        $this->assertSame(1, $aggregates['uptime:canary']['structured_started']);
        $this->assertSame(1, $aggregates['uptime:canary']['completed']);
        $this->assertSame(
            '2026-08-17T14:15:02+00:00',
            $aggregates['uptime:canary']['last_started_at'],
            'the newest (structured) fire must win over the older raw fire',
        );

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->doesntExpectOutputToContain('unfinished runs')
            ->doesntExpectOutputToContain('stopped firing')
            ->assertSuccessful();
    }

    public function test_exit_on_problem_returns_failure_when_a_command_stopped_firing(): void
    {
        // The item-5 gate must fail when any command went silent, so CI/alerting
        // catches a scheduler that stopped delivering a job.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-14.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-14T02:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7, '--exit-on-problem' => true]);
        $command->expectsOutputToContain('stopped firing')
            ->assertFailed();
    }

    public function test_command_does_not_flag_fires_within_tolerance_of_their_slot(): void
    {
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-16.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-16T02:10:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);
        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-17T02:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->doesntExpectOutputToContain('ran off their own cron slot')
            ->assertSuccessful();
    }

    public function test_drift_tolerance_option_is_honored(): void
    {
        // The item-5 gate must let an operator widen --drift-tolerance for a
        // noisier window without misfiring. A fire 30 min late is flagged at the
        // default 15-min tolerance but NOT at --drift-tolerance=60.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-17T02:30:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('off-schedule')
            ->assertSuccessful();

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7, '--drift-tolerance' => 60]);
        $command->doesntExpectOutputToContain('off-schedule')
            ->assertSuccessful();
    }

    public function test_raw_log_respects_the_days_window(): void
    {
        // The raw scheduler.log accumulates every fire since the file was created
        // (unlike the date-stamped structured logs). It must NOT pull in fires
        // older than the --days window, or it would inflate started counts and
        // drag last_started_at back far enough to false-flag staleness/drift.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler.log', [
            "  2026-08-03 02:00:00 Running ['artisan' restaurants:score] ",   // 14 days old — outside 7-day window
            "  2026-08-17 02:00:00 Running ['artisan' restaurants:score] ",   // today — inside
        ]);

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);

        $this->assertSame(1, $aggregates['restaurants:score']['started']);
        $this->assertSame(['2026-08-17 02:00:00'], $aggregates['restaurants:score']['started_ats']);
    }

    public function test_raw_log_does_not_double_count_fires_already_in_structured_telemetry(): void
    {
        // The raw cron-redirect and the structured telemetry record the SAME fire.
        // If both are present (as on the droplet), started must not double-count —
        // otherwise started(2) > completed(1) false-flags "unfinished runs".
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-17T02:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);
        $this->writeLog('scheduler.log', [
            "  2026-08-17 02:00:00 Running ['artisan' restaurants:score] ",
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);
        $this->assertSame(1, $aggregates['restaurants:score']['started'], 'the shared fire must be counted once');

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('restaurants:score')
            ->doesntExpectOutputToContain('unfinished runs')
            ->assertSuccessful();
    }

    public function test_aggregate_ingests_raw_cron_redirect_log_for_fire_counts(): void
    {
        // Structured JSON telemetry absent — only the raw `scheduler.log`
        // cron-redirect exists (as on the droplet before telemetry deploys).
        $this->writeLog('scheduler.log', [
            "  2026-08-17 02:00:00 Running ['artisan' restaurants:score] ",
            "  2026-08-17 02:00:01 Running ['artisan' uptime:canary] ........ 822.73ms DONE",
            "  2026-08-17 04:00:01 Running ['artisan' restaurants:enrich --throttled] ",
            '   INFO  No scheduled commands are ready to run.  ',
        ]);

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);

        $this->assertSame(1, $aggregates['restaurants:score']['started']);
        $this->assertSame('raw', $aggregates['restaurants:score']['source']);
        $this->assertSame(['2026-08-17 02:00:00'], $aggregates['restaurants:score']['started_ats']);

        $this->assertSame(1, $aggregates['uptime:canary']['started']);
        $this->assertSame(1, $aggregates['restaurants:enrich --throttled']['started']);

        // Non-start lines (INFO output, blanks) are ignored.
        $this->assertCount(3, $aggregates);
    }

    public function test_raw_only_commands_are_not_flagged_as_unfinished(): void
    {
        $this->writeLog('scheduler.log', [
            "  2026-08-17 02:00:00 Running ['artisan' restaurants:score] ",
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('restaurants:score')
            ->doesntExpectOutputToContain('unfinished runs')
            ->assertSuccessful();
    }

    public function test_raw_log_merges_with_structured_telemetry(): void
    {
        // Structured JSON marks the command as structured; the raw log is a
        // secondary source and must not overwrite it.
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => '2026-08-17T02:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
        ]);
        $this->writeLog('scheduler.log', [
            "  2026-08-17 02:00:00 Running ['artisan' restaurants:score] ",
            "  2026-08-17 02:00:01 Running ['artisan' uptime:canary] ........ 822.73ms DONE",
        ]);

        $aggregates = (new SchedulerTelemetryReport($this->dir))->aggregate(7);

        $this->assertSame('structured', $aggregates['restaurants:score']['source']);
        $this->assertSame(1, $aggregates['restaurants:score']['completed']);
        $this->assertSame([3.0], $aggregates['restaurants:score']['runtimes']);

        // uptime:canary only has raw data -> raw source.
        $this->assertSame('raw', $aggregates['uptime:canary']['source']);
        $this->assertSame(1, $aggregates['uptime:canary']['started']);
    }

    public function test_command_flags_a_command_that_double_fired(): void
    {
        // A redundant second scheduler instance (cron + schedule:work both live)
        // makes every command fire twice per cycle. The drift/stale checks can't
        // see this (each fire is on-slot, last fire is fresh), so the report must
        // compare fire count against the cron expression's slot count in the
        // window. Here restaurants:score (daily 02:00) fires twice on each of the
        // 2 days in a 2-day window = 4 fires vs 2 allowed slots.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        foreach (['2026-08-16', '2026-08-17'] as $day) {
            $this->writeLog("scheduler-{$day}.log", [
                $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => $day.'T02:00:00+00:00']),
                $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
                $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => $day.'T02:00:00+00:00']),
                $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
            ]);
        }

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 2]);
        $command->expectsOutputToContain('fired MORE often')
            ->expectsOutputToContain('restaurants:score')
            ->assertSuccessful();
    }

    public function test_command_does_not_flag_a_command_that_fired_once_per_slot(): void
    {
        // One fire per cron slot in the window is healthy — no over-fired flag.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        foreach (['2026-08-16', '2026-08-17'] as $day) {
            $this->writeLog("scheduler-{$day}.log", [
                $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => $day.'T02:00:00+00:00']),
                $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
            ]);
        }

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 2]);
        $command->doesntExpectOutputToContain('fired MORE often')
            ->assertSuccessful();
    }

    public function test_exit_on_problem_returns_failure_when_a_command_double_fired(): void
    {
        // The item-4/5 gate must fail when any command double-fires, so CI/alerting
        // catches a redundant scheduler before it snowballs into contention.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        foreach (['2026-08-16', '2026-08-17'] as $day) {
            $this->writeLog("scheduler-{$day}.log", [
                $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => $day.'T02:00:00+00:00']),
                $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
                $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => $day.'T02:00:00+00:00']),
                $this->telemetryLine('Scheduled command completed', 'restaurants:score', ['runtime_seconds' => 3.0]),
            ]);
        }

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 2, '--exit-on-problem' => true]);
        $command->expectsOutputToContain('fired MORE often')
            ->assertFailed();
    }

    public function test_command_prints_healthy_verdict_when_all_commands_fired(): void
    {
        // The item-5 workflow needs one scannable line: when every registered
        // command is healthy, print "Verdict: all 18 registered commands healthy".
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $lines = [];
        foreach ($schedule->events() as $event) {
            $prefixed = "'/usr/bin/php' 'artisan' ".trim((string) preg_replace('/^\S+\s+\S+\s+/', '', $event->command ?? ''));
            $startedAt = CronExpression::factory($event->getExpression())
                ->getPreviousRunDate(now(), 0, true)
                ->format('Y-m-d\TH:i:s+00:00');
            $lines[] = $this->telemetryLine('Scheduled command started', $prefixed, ['started_at' => $startedAt]);
            $lines[] = $this->telemetryLine('Scheduled command completed', $prefixed, ['runtime_seconds' => 0.5]);
        }
        $this->writeLog('scheduler-2026-08-17.log', $lines);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('Verdict: all 18 registered commands healthy')
            ->assertSuccessful();
    }

    public function test_command_prints_problem_verdict_with_flagged_count(): void
    {
        // When a command never fired, the verdict names the flagged count so the
        // operator sees "17 healthy, 1 problem" at a glance.
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $lines = [];
        foreach ($schedule->events() as $event) {
            if ($this->commandName($event->command) === 'apicache:gc') {
                continue; // leave one command silent -> it becomes the problem
            }
            $prefixed = "'/usr/bin/php' 'artisan' ".trim((string) preg_replace('/^\S+\s+\S+\s+/', '', $event->command ?? ''));
            $startedAt = CronExpression::factory($event->getExpression())
                ->getPreviousRunDate(now(), 0, true)
                ->format('Y-m-d\TH:i:s+00:00');
            $lines[] = $this->telemetryLine('Scheduled command started', $prefixed, ['started_at' => $startedAt]);
            $lines[] = $this->telemetryLine('Scheduled command completed', $prefixed, ['runtime_seconds' => 0.5]);
        }
        $this->writeLog('scheduler-2026-08-17.log', $lines);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        /** @var PendingCommand $command */
        $command = $this->artisan('scheduler:report', ['--days' => 7]);
        $command->expectsOutputToContain('Verdict: 1 of 18 registered commands have a problem (see above); 17 healthy')
            ->assertSuccessful();
    }

    public function test_json_output_is_parseable_and_flags_problems(): void
    {
        // Automation needs a machine-readable verdict, not just the table. With
        // --json the report emits one parseable document with the healthy flag,
        // counts, and per-category problem command lists. (Asserted by decoding
        // the JSON — substring matching against the harness's mocked output only
        // evaluates the first expectation per write.)
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score'),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        Artisan::call('scheduler:report', ['--days' => 7, '--json' => true]);
        $doc = json_decode(Artisan::output(), true);

        $this->assertIsArray($doc);
        $this->assertFalse($doc['healthy']);
        $this->assertSame(18, $doc['registered_count']);
        $this->assertSame(0, $doc['healthy_count']);
        $this->assertContains('restaurants:score', $doc['problems']['unfinished_runs']);
    }

    public function test_json_output_returns_failure_with_exit_on_problem(): void
    {
        // The JSON doc is still governed by --exit-on-problem: a never-fired
        // command makes the process exit FAILURE so CI can gate on it.
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score'),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        $code = Artisan::call('scheduler:report', ['--days' => 7, '--json' => true, '--exit-on-problem' => true]);
        $this->assertNotSame(0, $code);
        $this->assertFalse(json_decode(Artisan::output(), true)['healthy']);
    }

    public function test_json_output_reports_healthy_when_all_commands_fired(): void
    {
        $this->travelTo(Carbon::parse('2026-08-17 10:00:30', 'UTC'));

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $lines = [];
        foreach ($schedule->events() as $event) {
            $prefixed = "'/usr/bin/php' 'artisan' ".trim((string) preg_replace('/^\S+\s+\S+\s+/', '', $event->command ?? ''));
            $startedAt = CronExpression::factory($event->getExpression())
                ->getPreviousRunDate(now(), 0, true)
                ->format('Y-m-d\TH:i:s+00:00');
            $lines[] = $this->telemetryLine('Scheduled command started', $prefixed, ['started_at' => $startedAt]);
            $lines[] = $this->telemetryLine('Scheduled command completed', $prefixed, ['runtime_seconds' => 0.5]);
        }
        $this->writeLog('scheduler-2026-08-17.log', $lines);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        Artisan::call('scheduler:report', ['--days' => 7, '--json' => true]);
        $doc = json_decode(Artisan::output(), true);

        $this->assertIsArray($doc);
        $this->assertTrue($doc['healthy']);
        $this->assertSame(0, $doc['problem_count']);
        $this->assertSame(18, $doc['healthy_count']);
    }

    public function test_json_output_includes_runtime_and_drift_telemetry(): void
    {
        // In --json mode, telemetry for a command no longer in routes/console.php
        // (orphaned — e.g. a stale scheduler still firing a removed job) must be
        // surfaced so automation can see it, not silently dropped. The human table
        // shows these as "NOT REGISTERED" rows, but the JSON doc previously only
        // iterated registered commands, hiding them from CI.
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'stale:command'),
            $this->telemetryLine('Scheduled command completed', 'stale:command', ['runtime_seconds' => 1.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        Artisan::call('scheduler:report', ['--days' => 7, '--json' => true]);
        $doc = json_decode(Artisan::output(), true);

        $this->assertIsArray($doc);
        $this->assertContains('stale:command', $doc['unregistered']);
        $this->assertSame(18, count($doc['commands']), 'orphaned command must live in "unregistered", not "commands"');
    }

    public function test_json_output_reports_unregistered_telemetry_commands(): void
    {
        // In --json mode, telemetry for a command no longer in routes/console.php
        // (orphaned — e.g. a stale scheduler still firing a removed job) must be
        // surfaced so automation can see it, not silently dropped. The human table
        // shows these as "NOT REGISTERED" rows, but the JSON doc previously only
        // iterated registered commands, hiding them from CI.
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'stale:command'),
            $this->telemetryLine('Scheduled command completed', 'stale:command', ['runtime_seconds' => 1.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        Artisan::call('scheduler:report', ['--days' => 7, '--json' => true]);
        $doc = json_decode(Artisan::output(), true);

        $this->assertIsArray($doc);
        $this->assertContains('stale:command', $doc['unregistered']);
        $this->assertSame(18, count($doc['commands']), 'orphaned command must live in "unregistered", not "commands"');
    }

    public function test_json_output_includes_last_failure_output(): void
    {
        // The failed record now carries captured output (SchedulerTelemetry), so
        // the report must expose it — CI/operator can see WHY a command failed
        // without digging through raw logs.
        $this->writeLog('scheduler-'.now()->format('Y-m-d').'.log', [
            $this->telemetryLine('Scheduled command started', 'restaurants:score', ['started_at' => now()->toIso8601String()]),
            $this->telemetryLine('Scheduled command failed', 'restaurants:score', [
                'runtime_seconds' => 1.5,
                'output' => "ERROR: could not reach SerpApi\n",
            ]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        Artisan::call('scheduler:report', ['--days' => 7, '--json' => true]);

        /** @var array<string, mixed> $doc */
        $doc = json_decode(Artisan::output(), true);

        $commands = $doc['commands'] ?? [];
        $this->assertIsArray($commands);

        $score = collect($commands)->firstWhere('command', 'restaurants:score');
        $this->assertNotNull($score);
        $this->assertSame(1, $score['failed']);
        $this->assertSame("ERROR: could not reach SerpApi\n", $score['last_failure_output']);
        $this->assertContains('restaurants:score', $doc['problems']['failed']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function telemetryLine(string $message, string $command, array $extra = []): string
    {
        return json_encode([
            'message' => $message,
            'context' => array_merge(['command' => $command], $extra),
            'level' => $message === 'Scheduled command failed' ? 400 : 200,
            'level_name' => $message === 'Scheduled command failed' ? 'ERROR' : 'INFO',
            'channel' => 'local',
            'datetime' => '2026-08-17T00:00:00+00:00',
            'extra' => (object) [],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Strip the fully-qualified executable prefix ("{phpBinary} {artisanBinary} ")
     * that Schedule::command() prepends, leaving just the artisan command + args.
     */
    private function commandName(?string $command): string
    {
        return (string) preg_replace('/^\S+\s+\S+\s+/', '', $command ?? '');
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLog(string $filename, array $lines): void
    {
        file_put_contents($this->dir.'/'.$filename, implode("\n", $lines)."\n");
    }
}
