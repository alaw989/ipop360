<?php

namespace Tests\Unit;

use App\Support\SchedulerProblemDetector;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SchedulerProblemDetector. These cover the two detector
 * hardenings behind the scheduler-health false positives:
 *
 *   1. `never_fired` must NOT flag a command that had no scheduled slot in the
 *      window (weekly commands in a 1-day window), but MUST flag one that was
 *      expected and stayed silent.
 *   2. `hung` (unfinished runs) must skip the reporter command passed via
 *      `excludeFromHung` (scheduler:health self-flags otherwise).
 */
class SchedulerProblemDetectorTest extends TestCase
{
    private const WEEKLY = '0 11 * * 0';   // Sundays 11:00 (refresh-awards / verify-websites)

    private const DAILY = '0 2 * * *';     // 02:00 daily (restaurants:score)

    private SchedulerProblemDetector $detector;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new SchedulerProblemDetector;
        $this->now = CarbonImmutable::parse('2026-08-19 15:00:00', 'UTC'); // Wednesday
    }

    public function test_weekly_command_with_no_slot_in_one_day_window_is_not_never_fired(): void
    {
        $registered = ['restaurants:refresh-awards' => self::WEEKLY];

        $detected = $this->detector->detect([], $registered, 15, 1, $this->now);

        $this->assertFalse($detected['has_problem']);
        $this->assertSame([], $detected['never_fired']);
    }

    public function test_weekly_command_is_never_fired_when_window_covers_its_slot(): void
    {
        // A 7-day window includes the Sunday slot, so a silent weekly command
        // becomes a genuine never-fired problem.
        $registered = ['restaurants:refresh-awards' => self::WEEKLY];

        $detected = $this->detector->detect([], $registered, 15, 7, $this->now);

        $this->assertTrue($detected['has_problem']);
        $this->assertSame(['restaurants:refresh-awards'], $detected['never_fired']);
    }

    public function test_daily_command_with_no_fire_is_never_fired_even_in_one_day_window(): void
    {
        $registered = ['restaurants:score' => self::DAILY];

        $detected = $this->detector->detect([], $registered, 15, 1, $this->now);

        $this->assertSame(['restaurants:score'], $detected['never_fired']);
    }

    public function test_hung_skips_excluded_reporter_command(): void
    {
        $registered = [
            'scheduler:health' => '0 15 * * *',
            'restaurants:scrape-social' => '45 10 * * *',
        ];

        // Both started but neither completed (their completion is pending).
        $aggregates = [
            'scheduler:health' => $this->aggregate(structured_started: 1),
            'restaurants:scrape-social' => $this->aggregate(structured_started: 1),
        ];

        $detected = $this->detector->detect(
            $aggregates,
            $registered,
            15,
            1,
            $this->now,
            ['scheduler:health'],
        );

        $this->assertSame(['restaurants:scrape-social'], array_keys($detected['hung']));
    }

    public function test_hung_reports_reporter_command_when_not_excluded(): void
    {
        $aggregates = ['scheduler:health' => $this->aggregate(structured_started: 1)];

        $detected = $this->detector->detect(
            $aggregates,
            ['scheduler:health' => '0 15 * * *'],
            15,
            1,
            $this->now,
        );

        $this->assertSame(['scheduler:health'], array_keys($detected['hung']));
    }

    public function test_failed_skips_excluded_reporter_command(): void
    {
        $registered = [
            'scheduler:health' => '0 15 * * *',
            'restaurants:scrape-social' => '45 10 * * *',
        ];

        // Both recorded a failure — scheduler:health's is its own deliberate
        // FAILURE exit on detecting a problem elsewhere, not a crash.
        $aggregates = [
            'scheduler:health' => $this->aggregate(structured_started: 1, failed: 1),
            'restaurants:scrape-social' => $this->aggregate(structured_started: 1, failed: 1),
        ];

        $detected = $this->detector->detect(
            $aggregates,
            $registered,
            15,
            1,
            $this->now,
            ['scheduler:health'],
        );

        $this->assertSame(['restaurants:scrape-social'], array_keys($detected['failed']));
    }

    public function test_failed_reports_reporter_command_when_not_excluded(): void
    {
        $aggregates = ['scheduler:health' => $this->aggregate(structured_started: 1, failed: 1)];

        $detected = $this->detector->detect(
            $aggregates,
            ['scheduler:health' => '0 15 * * *'],
            15,
            1,
            $this->now,
        );

        $this->assertSame(['scheduler:health'], array_keys($detected['failed']));
    }

    public function test_hung_flags_immediately_when_no_expiry_map_supplied(): void
    {
        // Backward compatibility: omitting $expiryMinutes entirely reproduces
        // the old "flag the instant there's a dangling start" behavior.
        $aggregates = [
            'restaurants:backfill-websites --limit=400' => $this->aggregate(
                structured_started: 1,
                started_ats: ['2026-08-19T11:45:00+00:00'],
            ),
        ];

        $detected = $this->detector->detect(
            $aggregates,
            ['restaurants:backfill-websites --limit=400' => '45 11 * * *'],
            15,
            1,
            $this->now,
        );

        $this->assertSame(['restaurants:backfill-websites --limit=400'], array_keys($detected['hung']));
    }

    public function test_hung_grace_period_not_flagged_when_still_within_own_expiry_budget(): void
    {
        // restaurants:backfill-websites fires 11:45 with a 240-minute mutex
        // (worst case 15:45). Checked at 15:00 (195 minutes after start) it's
        // still within its own declared runtime budget — a dangling start
        // here is a healthy still-running job, not a hang.
        $aggregates = [
            'restaurants:backfill-websites --limit=400' => $this->aggregate(
                structured_started: 1,
                started_ats: ['2026-08-19T11:45:00+00:00'],
            ),
        ];

        $detected = $this->detector->detect(
            $aggregates,
            ['restaurants:backfill-websites --limit=400' => '45 11 * * *'],
            15,
            1,
            $this->now,
            [],
            ['restaurants:backfill-websites --limit=400' => 240],
        );

        $this->assertSame([], array_keys($detected['hung']));
    }

    public function test_hung_grace_period_flagged_once_own_expiry_budget_elapses(): void
    {
        // Same command/timestamps as above, but with a mutex TTL shorter than
        // the elapsed time (195 minutes) — a genuine hang past its own budget.
        $aggregates = [
            'restaurants:backfill-websites --limit=400' => $this->aggregate(
                structured_started: 1,
                started_ats: ['2026-08-19T11:45:00+00:00'],
            ),
        ];

        $detected = $this->detector->detect(
            $aggregates,
            ['restaurants:backfill-websites --limit=400' => '45 11 * * *'],
            15,
            1,
            $this->now,
            [],
            ['restaurants:backfill-websites --limit=400' => 60],
        );

        $this->assertSame(['restaurants:backfill-websites --limit=400'], array_keys($detected['hung']));
    }

    public function test_over_fired_still_detected(): void
    {
        // daily command fired 3x in a window with 2 daily slots → over-fired.
        $aggregates = [
            'restaurants:score' => $this->aggregate(
                structured_started: 3,
                started_ats: ['2026-08-18T02:00:00+00:00', '2026-08-19T02:00:00+00:00', '2026-08-19T02:05:00+00:00'],
            ),
        ];

        $detected = $this->detector->detect(
            $aggregates,
            ['restaurants:score' => self::DAILY],
            15,
            1,
            $this->now,
        );

        $this->assertArrayHasKey('restaurants:score', $detected['over_fired']);
    }

    public function test_over_fired_not_flagged_when_window_boundary_lands_on_a_cron_slot(): void
    {
        // Window boundary (startOfDay of 2026-08-18) is midnight, which is
        // itself a valid slot for a 6-hourly cadence. A fire landing exactly
        // on that boundary must still count as an expected slot — otherwise
        // this legitimate set of on-schedule fires (one per slot, including
        // the boundary one) would be false-flagged as over_fired.
        $startedAts = [
            '2026-08-18T00:00:00+00:00',
            '2026-08-18T06:00:00+00:00',
            '2026-08-18T12:00:00+00:00',
            '2026-08-18T18:00:00+00:00',
            '2026-08-19T00:00:00+00:00',
            '2026-08-19T06:00:00+00:00',
            '2026-08-19T12:00:00+00:00',
        ];

        $aggregates = [
            'restaurants:ai-enrich' => $this->aggregate(
                structured_started: count($startedAts),
                started_ats: $startedAts,
            ),
        ];

        $detected = $this->detector->detect(
            $aggregates,
            ['restaurants:ai-enrich' => '0 */6 * * *'],
            15,
            1,
            $this->now,
        );

        $this->assertArrayNotHasKey('restaurants:ai-enrich', $detected['over_fired']);
    }

    /**
     * @param  list<string>  $started_ats
     * @return array{started:int, structured_started:int, completed:int, failed:int, last_started_at:string|null, started_ats:list<string>, runtimes:list<float>, source:string, last_failure_output:string|null}
     */
    private function aggregate(int $structured_started = 0, int $completed = 0, int $failed = 0, array $started_ats = []): array
    {
        return [
            'started' => $structured_started,
            'structured_started' => $structured_started,
            'completed' => $completed,
            'failed' => $failed,
            'last_started_at' => $started_ats[count($started_ats) - 1] ?? null,
            'started_ats' => $started_ats,
            'runtimes' => [],
            'source' => 'structured',
            'last_failure_output' => null,
        ];
    }
}
