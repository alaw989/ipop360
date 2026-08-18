<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The throttled enrichment (restaurants:enrich --throttled) runs a long
 * free-source sweep that can take up to ~5h35m (04:00–~10:00 UTC), holding the
 * SQLite write lock for most of that window. Other DAILY jobs must never start
 * inside that window, or they contend for the write lock and stall.
 *
 * This test encodes that invariant: no job (daily OR weekly fixed-time) that
 * writes to the DB may start inside the enrichment window derived from the
 * enrichment event's own start time + mutex expiry. The throttled enrichment
 * runs every day, so a weekly job scheduled inside the window collides on its
 * weekday too. The always-on ai-enrich (every 6h) and the every-15-min uptime
 * canary are exempt by design.
 */
class SchedulerCollisionTest extends TestCase
{
    /** Documented worst-case free-source sweep runtime: ~5h35m. The mutex expiry
     * must stay at or above this, otherwise this test derives a window narrower
     * than the real run and would silently stop catching collisions. */
    private const DOCUMENTED_RUNTIME_MIN = 335;

    public function test_no_job_starts_inside_throttled_enrichment_window(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events());

        $enrich = $events->first(
            fn ($event) => str_contains((string) $event->command, 'restaurants:enrich --throttled')
        );
        $this->assertNotNull($enrich, 'expected the throttled enrichment event');

        $enrichStart = $this->dailyStartMinutes($enrich->getExpression());
        $this->assertNotNull($enrichStart, 'enrichment must run at a fixed daily time');

        // The window we test against is derived from the mutex expiry, so that
        // expiry must be >= the documented runtime. Otherwise the guard would
        // silently pass jobs that the real ~5h35m run would collide with.
        $this->assertGreaterThanOrEqual(
            self::DOCUMENTED_RUNTIME_MIN,
            $enrich->expiresAt,
            'enrichment mutex expiry must cover the documented ~5h35m runtime, or the '
            .'collision window derived from it is narrower than the real run'
        );

        $enrichEnd = $enrichStart + $enrich->expiresAt;

        foreach ($events as $event) {
            $command = $this->commandName($event->command);

            if (str_contains($command, 'restaurants:enrich --throttled')) {
                continue;
            }
            if (str_starts_with($command, 'restaurants:ai-enrich')) {
                continue; // intentionally continuous (every 6h) — exempt
            }
            if (str_contains($command, 'uptime:canary')) {
                continue; // every 15 min, non-fixed — exempt
            }

            $start = $this->dailyStartMinutes($event->getExpression());
            if ($start === null) {
                continue; // not a fixed daily/weekly-time job (interval/hourly)
            }

            $this->assertFalse(
                $start >= $enrichStart && $start < $enrichEnd,
                "job [{$command}] starts at minute {$start}, inside the throttled-enrichment "
                ."window [{$enrichStart}, {$enrichEnd}) — move it after enrichment to avoid SQLite write contention"
            );
        }
    }

    /**
     * Minutes-of-day a cron expression runs at, but ONLY when it is a fixed
     * time (single minute + hour, all other fields `*` or a single weekday).
     * Handles both daily (`M H * * *`) and weekly (`M H * * W`) expressions.
     * Returns null for interval or hourly expressions.
     */
    private function dailyStartMinutes(string $expression): ?int
    {
        if (! preg_match('/^(\d+)\s+(\d+)\s+\*\s+\*\s+(\*|\d+)$/', $expression, $m)) {
            return null;
        }

        return ((int) $m[2] * 60) + (int) $m[1];
    }

    /**
     * Strip the fully-qualified executable prefix ("{phpBinary} {artisanBinary} ")
     * that Schedule::command() prepends, leaving just the artisan command + args.
     */
    private function commandName(?string $command): string
    {
        return (string) preg_replace('/^\S+\s+\S+\s+/', '', $command ?? '');
    }
}
