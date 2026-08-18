<?php

namespace Tests\Feature;

use App\Notifications\SchedulerHealthAlert;
use App\Support\SchedulerTelemetryReport;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * scheduler:health is the operational alert gate: it reads the same telemetry as
 * scheduler:report and, when any command has a problem (never-fired / failed /
 * hung / off-schedule / stopped-firing / over-fired), notifies the configured
 * operator emails so a broken scheduler is caught without someone running the
 * report by hand. Read-only (no DB writes, no network beyond the mail channel).
 */
class SchedulerHealthTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/scheduler-health-'.bin2hex(random_bytes(6));
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

    public function test_healthy_scheduler_exits_success_and_does_not_notify(): void
    {
        Notification::fake();
        config()->set('services.admin_notify_emails', ['admin@example.com']);

        $this->travelTo(Carbon::parse('2026-08-17 15:00:30', 'UTC'));

        $lines = [];
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
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

        $code = Artisan::call('scheduler:health', ['--days' => 7]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('healthy', Artisan::output());
        Notification::assertSentOnDemandTimes(SchedulerHealthAlert::class, 0);
    }

    public function test_problem_notifies_configured_admins_and_exits_failure(): void
    {
        Notification::fake();
        config()->set('services.admin_notify_emails', ['admin@example.com', 'ops@example.com']);

        $this->travelTo(Carbon::parse('2026-08-17 15:00:30', 'UTC'));

        // One command silent (restaurants:score) -> never-fired problem.
        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'uptime:canary', ['started_at' => '2026-08-17T15:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'uptime:canary', ['runtime_seconds' => 1.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        $code = Artisan::call('scheduler:health', ['--days' => 7]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Scheduler problems found', Artisan::output());

        Notification::assertSentOnDemand(
            SchedulerHealthAlert::class,
            function (SchedulerHealthAlert $notification, array $channels, $notifiable) {
                return in_array('mail', $channels, true)
                    && str_contains($notification->summary(), 'restaurants:score')
                    && $notifiable->routes['mail'] === ['admin@example.com', 'ops@example.com'];
            }
        );
    }

    public function test_problem_still_fails_but_does_not_notify_when_admins_unconfigured(): void
    {
        Notification::fake();
        config()->set('services.admin_notify_emails', []);

        $this->travelTo(Carbon::parse('2026-08-17 15:00:30', 'UTC'));

        $this->writeLog('scheduler-2026-08-17.log', [
            $this->telemetryLine('Scheduled command started', 'uptime:canary', ['started_at' => '2026-08-17T15:00:00+00:00']),
            $this->telemetryLine('Scheduled command completed', 'uptime:canary', ['runtime_seconds' => 1.0]),
        ]);

        $this->app->instance(SchedulerTelemetryReport::class, new SchedulerTelemetryReport($this->dir));

        $code = Artisan::call('scheduler:health', ['--days' => 7]);

        $this->assertNotSame(0, $code);
        Notification::assertSentOnDemandTimes(SchedulerHealthAlert::class, 0);
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
    private function writeLog(string $name, array $lines): void
    {
        file_put_contents($this->dir.'/'.$name, implode("\n", $lines)."\n");
    }
}
