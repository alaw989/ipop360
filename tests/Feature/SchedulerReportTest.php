<?php

namespace Tests\Feature;

use App\Support\SchedulerTelemetryReport;
use Illuminate\Support\Carbon;
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
     * @param  list<string>  $lines
     */
    private function writeLog(string $filename, array $lines): void
    {
        file_put_contents($this->dir.'/'.$filename, implode("\n", $lines)."\n");
    }
}
