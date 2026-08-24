<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Locks the complete scheduler manifest: every registered command must fire in
 * exactly the cron slot it is supposed to. This is the local, regression-guarded
 * counterpart of item 5 in the scheduler/infra hardening goal — "confirm all 16
 * scheduled commands fire on time on the droplet". If someone changes a
 * dailyAt/weeklyOn/everyN call and forgets that a downstream job depends on the
 * ordering, this test fails and names the drifted command.
 */
class SchedulerManifestTest extends TestCase
{
    /**
     * Bare artisan command + args => expected cron expression.
     *
     * @var array<string, string>
     */
    private const EXPECTED_SCHEDULE = [
        'restaurants:score' => '0 2 * * *',
        'apicache:gc' => '0 3 * * *',
        'uptime:canary' => '*/15 * * * *',
        'serpapi:sync-account-status' => '*/15 * * * *',
        'restaurants:enrich --throttled' => '0 4 * * *',
        'seo:sitemap' => '15 10 * * *',
        'restaurants:backfill-websites --limit=400' => '45 11 * * *',
        'restaurants:backfill-photos --apply --limit=200 --min-photos=2' => '45 13 * * *',
        'restaurants:backfill-photos --verify --apply --limit=200' => '30 12 * * 3',
        'restaurants:scrape-social --limit=400' => '45 10 * * *',
        'restaurants:scrape-social --force --limit=1500' => '0 12 * * 6',
        'restaurants:refresh-awards' => '30 11 * * 0',
        'restaurants:update-engagement' => '30 0 * * *',
        'restaurants:data-hygiene --apply --limit=200' => '0 1 * * *',
        'restaurants:ai-enrich' => '0 */6 * * *',
        'restaurants:coverage' => '0 11 * * 1',
        'restaurants:verify-websites --limit=200' => '0 11 * * 0',
        'scheduler:health' => '0 15 * * *',
    ];

    public function test_manifest_exactly_matches_the_scheduled_commands(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $scheduled = collect($schedule->events())
            ->map(fn ($event) => $this->commandName($event->command))
            ->sort()
            ->values()
            ->all();

        $expected = array_keys(self::EXPECTED_SCHEDULE);
        sort($expected);

        $this->assertSame(
            $expected,
            $scheduled,
            'the scheduler manifest must contain exactly these 17 commands — add/remove a '
            .'route in routes/console.php AND update this map together'
        );
    }

    public function test_each_command_fires_in_its_expected_cron_slot(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            $command = $this->commandName($event->command);

            $this->assertArrayHasKey(
                $command,
                self::EXPECTED_SCHEDULE,
                "event [{$command}] is missing from the expected-schedule map"
            );

            $this->assertSame(
                self::EXPECTED_SCHEDULE[$command],
                $event->getExpression(),
                "event [{$command}] drifted from its cron slot — align the dailyAt/weeklyOn/everyN "
                .'call (and any downstream ordering) with the manifest'
            );
        }
    }

    public function test_no_two_commands_share_a_cron_slot(): void
    {
        // Two commands firing in the same cron slot would contend for the
        // SQLite write lock and/or double-run. Each fixed-time command must own
        // its slot. (The every-6h ai-enrich and every-15-min uptime are interval
        // expressions and inherently overlap their own slot class — skipped.)
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $fixedTime = collect($schedule->events())
            ->reject(fn ($event) => $this->isInterval($event->getExpression()))
            ->map(fn ($event) => $event->getExpression());

        $this->assertSame(
            $fixedTime->count(),
            $fixedTime->unique()->count(),
            'two or more fixed-time commands share a cron slot — stagger them to avoid '
            .'contention and double-running'
        );
    }

    public function test_photo_backfill_apply_runs_after_website_backfill(): void
    {
        // backfill-photos (--apply) sources its primary image from website_url's
        // og:image, which backfill-websites populates first. If these slots ever
        // flip, the photo backfill would run against a stale/empty website_url set
        // and silently miss its best image source. The exact-slot map above can't
        // catch this (both slots are valid) — this asserts the data-flow ordering.
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $this->assertTrue(
            $this->slotMinutes('restaurants:backfill-photos --apply --limit=200 --min-photos=2', $schedule)
            > $this->slotMinutes('restaurants:backfill-websites --limit=400', $schedule),
            'backfill-photos --apply must run AFTER backfill-websites (it sources og:image from '
            .'the website_url that backfill-websites populates)'
        );
    }

    public function test_score_runs_after_engagement_and_data_hygiene(): void
    {
        // restaurants:score must reflect the freshest engagement counts and a clean
        // (de-duplicated, normalized) corpus, so it has to run after both
        // update-engagement (00:30) and data-hygiene (01:00). Flipping this order
        // would compute scores on stale engagement and/or un-hygiened data.
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $this->assertTrue(
            $this->slotMinutes('restaurants:score', $schedule)
            > $this->slotMinutes('restaurants:update-engagement', $schedule),
            'restaurants:score must run AFTER restaurants:update-engagement (scores reflect fresh engagement)'
        );

        $this->assertTrue(
            $this->slotMinutes('restaurants:score', $schedule)
            > $this->slotMinutes('restaurants:data-hygiene --apply --limit=200', $schedule),
            'restaurants:score must run AFTER restaurants:data-hygiene (scores reflect a clean corpus)'
        );
    }

    public function test_only_the_two_monitoring_commands_run_during_maintenance_mode(): void
    {
        // uptime:canary and scheduler:health are read-only/log-based, so they opt
        // into evenInMaintenanceMode() — otherwise every deploy's artisan down/up
        // window silently skips their tick, producing false never_fired/off_schedule
        // alerts. Every other (write-capable) command must keep skipping during
        // maintenance — running mid-migration risks real data issues.
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $runsDuringMaintenance = ['uptime:canary', 'scheduler:health'];

        foreach ($schedule->events() as $event) {
            $command = $this->commandName($event->command);
            $expected = in_array($command, $runsDuringMaintenance, true);

            $this->assertSame(
                $expected,
                $event->runsInMaintenanceMode(),
                $expected
                    ? "event [{$command}] must keep evenInMaintenanceMode() enabled"
                    : "event [{$command}] must NOT run during maintenance mode (it writes data)"
            );
        }
    }

    public function test_every_scheduled_command_resolves_to_a_registered_artisan_command(): void
    {
        // A scheduled command referencing a command class that was renamed or
        // removed (but left in routes/console.php) would crash every schedule:run
        // tick — surfacing only as a late failure in the enrichment log. Guard it
        // here so a dangling scheduled command fails the build at commit time.
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $registered = Artisan::all();

        foreach ($schedule->events() as $event) {
            $command = trim((string) preg_replace('/^\S+\s+\S+\s+/', '', $event->command ?? ''));
            $tokens = preg_split('/\s+/', $command) ?: [];
            $signature = $tokens[0] ?? '';

            $this->assertArrayHasKey(
                $signature,
                $registered,
                "scheduled command [{$command}] references [{$signature}], which is not a registered "
                .'artisan command — it would crash every schedule:run tick; fix or remove the route'
            );
        }
    }

    /**
     * Minutes-of-day for a fixed-time daily cron slot ("M H * * *").
     */
    private function slotMinutes(string $command, Schedule $schedule): int
    {
        $event = collect($schedule->events())->first(
            fn ($e) => $this->commandName($e->command) === $command,
        );

        $this->assertNotNull($event, "expected [{$command}] in the schedule");

        $expression = $event->getExpression();
        if (preg_match('/^(\d+) (\d+) \* \* \*$/', $expression, $m) !== 1) {
            $this->fail("expected fixed-time daily cron slot, got [{$expression}]");
        }

        return (int) $m[2] * 60 + (int) $m[1];
    }

    /**
     * True for step-interval expressions (e.g. every-fifteen-minutes, every-six-
     * hours) that inherently repeat on a cadence and are exempt from the "one
     * command per slot" rule. A plain fixed-time daily/weekly expression (M H * * *)
     * also contains asterisks but is NOT an interval — only a slash-star step
     * makes it one.
     */
    private function isInterval(string $expression): bool
    {
        return str_contains($expression, '*/');
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
