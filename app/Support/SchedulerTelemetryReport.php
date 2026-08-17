<?php

namespace App\Support;

/**
 * Reads the scheduler telemetry log(s) written by {@see SchedulerTelemetry}
 * and aggregates per-command fire counts, failure counts and wall-clock
 * runtimes so an operator can confirm every scheduled command fires on time.
 *
 * The `scheduler` log channel is a JSON `daily` driver, so records land in
 * `storage/logs/scheduler-YYYY-MM-DD.log` (one file per day).
 *
 * It ALSO ingests the plain-text `scheduler.log` written by the cron redirect
 * (the raw `schedule:run` stdout). That file reliably records every fire via
 * its `Running ['artisan' <command>]` start lines with a timestamp, which is
 * enough to confirm each command fires and to measure drift — the primary
 * purpose here. Completion (`... DONE`) lines in that file don't name the
 * command, so they're not attributed; structured JSON remains authoritative
 * for completion/failure/runtime when it exists.
 */
final class SchedulerTelemetryReport
{
    private const TRACKED = [
        'Scheduled command started',
        'Scheduled command completed',
        'Scheduled command failed',
    ];

    private const RAW_FILE = 'scheduler.log';

    /** Matches a raw cron-redirect start line: `YYYY-MM-DD HH:MM:SS Running ['artisan' <cmd>]`. */
    private const RAW_START = '/^\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+Running \[\'artisan\' ([^\]]+)\]/';

    public function __construct(private readonly ?string $directory = null) {}

    private function directory(): string
    {
        return $this->directory ?? storage_path('logs');
    }

    /**
     * @return array<int, string> Absolute paths to scheduler-*.log files within the window.
     */
    public function logFiles(int $days): array
    {
        $cutoff = now()->subDays($days)->startOfDay()->format('Y-m-d');

        $files = glob($this->directory().'/scheduler-*.log') ?: [];

        return array_values(array_filter($files, function (string $file) use ($cutoff): bool {
            if (! preg_match('/scheduler-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                return true;
            }

            return $matches[1] >= $cutoff;
        }));
    }

    /**
     * Aggregate telemetry records per (normalized) command.
     *
     * @return array<string, array{started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string}>
     */
    public function aggregate(int $days): array
    {
        $aggregates = [];

        foreach ($this->logFiles($days) as $file) {
            foreach ($this->readLines($file) as $record) {
                if (! isset($record['message'], $record['context'])) {
                    continue;
                }

                $message = $record['message'];
                if (! in_array($message, self::TRACKED, true)) {
                    continue;
                }

                $command = $this->normalize((string) ($record['context']['command'] ?? ''));

                $aggregates[$command] ??= $this->emptyAggregate();

                if ($message === 'Scheduled command started') {
                    $aggregates[$command]['started']++;
                    $aggregates[$command]['last_started_at'] = $record['context']['started_at'] ?? null;

                    $startedAt = $record['context']['started_at'] ?? null;
                    if (is_string($startedAt) && $startedAt !== '') {
                        $aggregates[$command]['started_ats'][] = $startedAt;
                    }
                } elseif ($message === 'Scheduled command completed') {
                    $aggregates[$command]['completed']++;
                    $aggregates[$command]['runtimes'][] = (float) ($record['context']['runtime_seconds'] ?? 0.0);
                } elseif ($message === 'Scheduled command failed') {
                    $aggregates[$command]['failed']++;
                    $aggregates[$command]['runtimes'][] = (float) ($record['context']['runtime_seconds'] ?? 0.0);
                }
            }
        }

        $this->mergeRawLog($aggregates);

        return $aggregates;
    }

    /**
     * Fall back to the plain-text cron-redirect `scheduler.log` so fires can be
     * confirmed even when the structured JSON telemetry isn't present (e.g. the
     * droplet before the telemetry is deployed). Merges only what the raw file
     * can reliably provide — start fires + timestamps — and marks commands that
     * have no structured data as source=raw so the report doesn't misread their
     * (unattributable) completion as "unfinished".
     *
     * @param  array<string, array{started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string}>  $aggregates
     */
    private function mergeRawLog(array &$aggregates): void
    {
        $path = $this->directory().'/'.self::RAW_FILE;
        if (! is_file($path)) {
            return;
        }

        foreach ($this->readPlainLines($path) as $line) {
            if (! preg_match(self::RAW_START, $line, $m)) {
                continue;
            }

            $command = trim($m[2]);
            $startedAt = $m[1];

            $isNew = ! isset($aggregates[$command]);
            $aggregates[$command] ??= $this->emptyAggregate();
            $aggregates[$command]['started']++;
            $aggregates[$command]['last_started_at'] = $startedAt;
            $aggregates[$command]['started_ats'][] = $startedAt;
            if ($isNew) {
                $aggregates[$command]['source'] = 'raw';
            }
        }
    }

    /**
     * @return array{started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string}
     */
    private function emptyAggregate(): array
    {
        return [
            'started' => 0,
            'completed' => 0,
            'failed' => 0,
            'last_started_at' => null,
            'started_ats' => [],
            'runtimes' => [],
            'source' => 'structured',
        ];
    }

    /**
     * Strip the "{phpBinary} {artisanBinary} " prefix Schedule::command() prepends,
     * leaving the bare artisan command + args. Matches the normalizer used by the
     * scheduler-expiry/telemetry tests.
     */
    private function normalize(string $command): string
    {
        return (string) preg_replace('/^\S+\s+\S+\s+/', '', $command);
    }

    /**
     * @return \Generator<int, string> Trimmed plain-text lines (for the raw
     *                                 cron-redirect log, which is not JSON).
     */
    private function readPlainLines(string $file): \Generator
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function readLines(string $file): \Generator
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
