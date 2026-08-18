<?php

namespace Tests\Feature;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SchedulerExpiryTest extends TestCase
{
    /**
     * Default mutex expiry baked into Laravel (1440 minutes = 24h). A hard-crashed
     * command that leaves the lock behind blocks the next scheduled run for a full
     * day unless every command sets an explicit, command-appropriate expiry.
     */
    private const DEFAULT_EXPIRY = 1440;

    /**
     * Explicit per-command mutex expiries (minutes), sized just above each
     * command's expected wall-clock runtime so a crashed run releases its lock
     * in time for the next scheduled tick without stalling it.
     *
     * @var array<string, int>
     */
    private const EXPECTED_EXPIRIES = [
        'restaurants:score' => 60,
        'apicache:gc' => 30,
        'uptime:canary' => 5,
        'restaurants:enrich --throttled' => 360,
        'seo:sitemap' => 30,
        'restaurants:backfill-websites' => 120,
        'restaurants:backfill-photos --apply --limit=200 --min-photos=2' => 180,
        'restaurants:backfill-photos --verify --apply --limit=200' => 180,
        'restaurants:scrape-social' => 60,
        'restaurants:scrape-social --force' => 240,
        'restaurants:refresh-awards' => 180,
        'restaurants:update-engagement' => 30,
        'restaurants:data-hygiene --apply --limit=200' => 180,
        'restaurants:ai-enrich' => 180,
        'restaurants:coverage' => 30,
        'restaurants:verify-websites --limit=200' => 120,
        'scheduler:health' => 30,
    ];

    public function test_every_scheduled_event_has_an_explicit_mutex_expiry(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $this->assertNotEmpty($schedule->events(), 'expected scheduled events to inspect');

        foreach ($schedule->events() as $event) {
            $command = $this->commandName($event->command);

            $this->assertArrayHasKey(
                $command,
                self::EXPECTED_EXPIRIES,
                "event [{$command}] is missing from the expected-expiry map"
            );

            $this->assertTrue(
                $event->withoutOverlapping,
                "event [{$command}] must keep withoutOverlapping() enabled"
            );

            $this->assertTrue(
                $event->onOneServer,
                "event [{$command}] must keep onOneServer() enabled — cron is the single scheduler "
                .'driver, so a command without onOneServer would double-fire if a second scheduler '
                .'instance (e.g. a stray schedule:work) ever starts'
            );

            $this->assertSame(
                self::EXPECTED_EXPIRIES[$command],
                $event->expiresAt,
                "event [{$command}] must set an explicit mutex expiry"
            );

            $this->assertNotSame(
                self::DEFAULT_EXPIRY,
                $event->expiresAt,
                "event [{$command}] still uses the 1440-minute default expiry (a crashed run blocks the next day)"
            );
        }
    }

    public function test_expected_expiry_map_covers_every_scheduled_command(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $scheduled = collect($schedule->events())
            ->map(fn ($event) => $this->commandName($event->command))
            ->all();

        $this->assertSame(
            array_keys(self::EXPECTED_EXPIRIES),
            $scheduled,
            'the expected-expiry map must exactly match the scheduled commands'
        );
    }

    public function test_every_mutex_expiry_is_shorter_than_the_commands_cadence(): void
    {
        // A mutex expiry >= the command's own cadence would let a hard-crashed run
        // hold the lock until (or past) the next scheduled tick, blocking it. Every
        // expiry must be strictly shorter than the gap between two consecutive runs
        // so a crashed run always releases in time for the next cycle.
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            $command = $this->commandName($event->command);
            $cadence = $this->cadenceMinutes($event->getExpression());

            $this->assertNotNull($cadence, "could not derive cadence for [{$command}]");

            $this->assertLessThan(
                $cadence,
                $event->expiresAt,
                "event [{$command}] mutex expiry ({$event->expiresAt}m) must be shorter than its "
                ."cadence ({$cadence}m), or a hard-crashed run blocks the next scheduled tick"
            );
        }
    }

    /**
     * Gap (minutes) between two consecutive runs of a cron expression.
     */
    private function cadenceMinutes(string $expression): ?float
    {
        try {
            $cron = CronExpression::factory($expression);
            $a = Carbon::instance($cron->getNextRunDate(now()));
            $b = Carbon::instance($cron->getNextRunDate($a));

            return abs($b->diffInMinutes($a));
        } catch (\Throwable) {
            return null;
        }
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
