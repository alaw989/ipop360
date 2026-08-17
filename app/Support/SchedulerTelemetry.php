<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;

/**
 * Attaches runtime + failure telemetry to scheduled command events.
 *
 * Every scheduled event gets a "started" heartbeat (before the command runs)
 * and a "completed"/"failed" record (after it finishes) carrying the
 * wall-clock runtime in seconds. Writes to the dedicated `scheduler` log
 * channel so per-command runtimes can be compared across days without
 * polluting the enrichment log.
 */
final class SchedulerTelemetry
{
    /**
     * Attach before/onSuccess/onFailure telemetry hooks to an event.
     *
     * Callbacks are additive (beforeCallbacks / afterCallbacks stack), so
     * existing onFailure handlers are preserved.
     *
     * @return Event The same event, for fluent chaining.
     */
    public static function attach(Event $event): Event
    {
        $command = $event->command;
        $start = 0.0;

        $event->before(function () use (&$start, $command) {
            $start = microtime(true);

            Log::channel('scheduler')->info('Scheduled command started', [
                'command' => $command,
                'started_at' => now()->toIso8601String(),
            ]);
        });

        $event->onSuccess(function () use (&$start, $command) {
            Log::channel('scheduler')->info('Scheduled command completed', [
                'command' => $command,
                'runtime_seconds' => round(microtime(true) - $start, 2),
            ]);
        });

        $event->onFailure(function () use (&$start, $command) {
            Log::channel('scheduler')->error('Scheduled command failed', [
                'command' => $command,
                'runtime_seconds' => round(microtime(true) - $start, 2),
            ]);
        });

        return $event;
    }
}
