<?php

namespace Tests\Feature;

use App\Support\SchedulerTelemetry;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use ReflectionProperty;
use Tests\TestCase;

class SchedulerTelemetryTest extends TestCase
{
    public function test_scheduler_log_channel_is_configured(): void
    {
        $channel = config('logging.channels.scheduler');

        $this->assertNotNull($channel, 'a dedicated scheduler log channel must exist');
        $this->assertSame(storage_path('logs/scheduler.log'), $channel['path']);
        $this->assertSame('daily', $channel['driver']);
    }

    public function test_every_scheduled_event_has_runtime_and_failure_telemetry(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $this->assertNotEmpty($schedule->events(), 'expected scheduled events to inspect');

        foreach ($schedule->events() as $event) {
            $before = $this->readProtected($event, 'beforeCallbacks');
            $after = $this->readProtected($event, 'afterCallbacks');

            $this->assertGreaterThanOrEqual(
                1,
                count($before),
                "event [{$event->command}] is missing the started heartbeat (before callback)"
            );
            $this->assertGreaterThanOrEqual(
                2,
                count($after),
                "event [{$event->command}] is missing completed/failed telemetry (after callbacks)"
            );
        }
    }

    public function test_attach_logs_started_completed_and_failed_with_runtime(): void
    {
        $handler = new TestHandler;
        Log::extend('scheduler-test', fn () => new MonologLogger('scheduler', [$handler]));
        config(['logging.channels.scheduler' => ['driver' => 'scheduler-test']]);
        Log::forgetChannel('scheduler');

        $event = new Event(app(CacheEventMutex::class), 'restaurants:score');
        SchedulerTelemetry::attach($event);

        $event->callBeforeCallbacks($this->app);
        $this->assertTrue(
            $handler->hasInfoThatContains('Scheduled command started'),
            'before callback must log a started heartbeat'
        );

        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);
        $this->assertTrue(
            $handler->hasInfoThatContains('Scheduled command completed'),
            'onSuccess must log a completed record'
        );

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);
        $this->assertTrue(
            $handler->hasErrorThatContains('Scheduled command failed'),
            'onFailure must log a failed record'
        );

        $completed = collect($handler->getRecords())
            ->first(fn (LogRecord $record) => $record->message === 'Scheduled command completed');

        $this->assertInstanceOf(LogRecord::class, $completed);
        $this->assertArrayHasKey('runtime_seconds', $completed->context, 'completed record must carry runtime_seconds');
        $this->assertArrayHasKey('command', $completed->context, 'completed record must carry the command name');
        $this->assertSame('restaurants:score', $completed->context['command']);
    }

    public function test_attach_includes_captured_output_in_failed_record(): void
    {
        // The failure telemetry must let an operator see WHY a scheduled command
        // failed, not just that it did. The failed record carries a truncated
        // copy of the command's captured output.
        $handler = new TestHandler;
        Log::extend('scheduler-test', fn () => new MonologLogger('scheduler', [$handler]));
        config(['logging.channels.scheduler' => ['driver' => 'scheduler-test']]);
        Log::forgetChannel('scheduler');

        $event = new Event(app(CacheEventMutex::class), 'restaurants:score');
        SchedulerTelemetry::attach($event);

        // onFailureWithOutput triggers output capture; write the failure output to
        // the path the event now points at, then run the after callbacks.
        $outputPath = $event->output;
        file_put_contents($outputPath, "ERROR: could not reach SerpApi\n");

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $failed = collect($handler->getRecords())
            ->first(fn (LogRecord $record) => $record->message === 'Scheduled command failed');

        $this->assertInstanceOf(LogRecord::class, $failed);
        $this->assertArrayHasKey('output', $failed->context, 'failed record must carry the captured output');
        $this->assertStringContainsString('could not reach SerpApi', $failed->context['output']);
    }

    public function test_attach_truncates_very_long_failure_output(): void
    {
        // A command that dumps a huge trace must not bloat the telemetry log; the
        // captured output is capped and marked truncated.
        $handler = new TestHandler;
        Log::extend('scheduler-test', fn () => new MonologLogger('scheduler', [$handler]));
        config(['logging.channels.scheduler' => ['driver' => 'scheduler-test']]);
        Log::forgetChannel('scheduler');

        $event = new Event(app(CacheEventMutex::class), 'restaurants:score');
        SchedulerTelemetry::attach($event);

        $outputPath = $event->output;
        file_put_contents($outputPath, str_repeat('x', 5000)."\n");

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $failed = collect($handler->getRecords())
            ->first(fn (LogRecord $record) => $record->message === 'Scheduled command failed');

        $this->assertInstanceOf(LogRecord::class, $failed);
        $this->assertLessThanOrEqual(2100, mb_strlen($failed->context['output']));
        $this->assertStringContainsString('[truncated]', $failed->context['output']);
    }

    /**
     * @return array<int, mixed>
     */
    private function readProtected(object $object, string $property): array
    {
        $prop = new ReflectionProperty($object, $property);

        return $prop->getValue($object);
    }
}
