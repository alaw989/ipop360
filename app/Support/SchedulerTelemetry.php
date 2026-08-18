<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;

/**
 * Attaches runtime + failure telemetry to scheduled command events.
 *
 * Every scheduled event gets a "started" heartbeat (before the command runs)
 * and a "completed"/"failed" record (after it finishes) carrying the
 * wall-clock runtime in seconds. Writes to the dedicated `scheduler` log
 * channel so per-command runtimes can be compared across days without
 * polluting the enrichment log. The failed record also carries a truncated
 * copy of the command's captured output, so an operator can see why a
 * scheduled command failed without digging through the full logs.
 */
final class SchedulerTelemetry
{
    /** Max chars of captured output kept in a failed telemetry record. */
    private const MAX_FAILURE_OUTPUT = 2000;

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

        $event->onFailureWithOutput(function (Stringable $output) use (&$start, $command) {
            Log::channel('scheduler')->error('Scheduled command failed', [
                'command' => $command,
                'runtime_seconds' => round(microtime(true) - $start, 2),
                'output' => self::truncate((string) $output),
            ]);
        });

        return $event;
    }

    private static function truncate(string $output): string
    {
        $output = trim($output);
        if (mb_strlen($output) <= self::MAX_FAILURE_OUTPUT) {
            return $output;
        }

        return mb_substr($output, 0, self::MAX_FAILURE_OUTPUT).' … [truncated]';
    }
}
