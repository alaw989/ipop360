<?php

namespace Tests\Feature;

use Tests\TestCase;

class SchedulerSingleDriverTest extends TestCase
{
    public function test_cron_schedule_run_is_the_single_scheduler_driver(): void
    {
        $deploy = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertNotFalse($deploy, 'deploy.yml must be readable');

        $this->assertStringContainsString(
            'schedule:run',
            $deploy,
            'cron must drive the scheduler via `schedule:run`'
        );

        $this->assertStringNotContainsString(
            'artisan schedule:work',
            $deploy,
            'deploy.yml must not invoke `artisan schedule:work` — cron is the only scheduler driver'
        );
    }

    public function test_no_supervisor_scheduler_program_is_shipped(): void
    {
        $this->assertFileDoesNotExist(
            base_path('deploy/supervisor-ipop360-scheduler.conf'),
            'the schedule:work supervisor program must be removed — cron is the single scheduler driver'
        );
    }
}
