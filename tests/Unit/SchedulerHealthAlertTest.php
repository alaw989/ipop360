<?php

namespace Tests\Unit;

use App\Notifications\SchedulerHealthAlert;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic test for SchedulerHealthAlert's mail rendering: each present
 * problem category must render with its plain-language description and every
 * listed command name, not just the bare category key.
 */
class SchedulerHealthAlertTest extends TestCase
{
    public function test_mail_body_includes_category_descriptions_and_commands(): void
    {
        $problems = [
            'failed' => ['restaurants:score', 'apicache:gc'],
            'over_fired' => ['restaurants:ai-enrich'],
        ];

        $alert = new SchedulerHealthAlert($problems, 1);
        $mail = $alert->toMail(new \stdClass);

        $body = implode("\n", $mail->introLines);

        $this->assertStringContainsString('exited with an error', $body);
        $this->assertStringContainsString('restaurants:score', $body);
        $this->assertStringContainsString('apicache:gc', $body);
        $this->assertStringContainsString('ran more times than its schedule allows', $body);
        $this->assertStringContainsString('restaurants:ai-enrich', $body);
        $this->assertStringContainsString('3 command(s) with a problem', $body);
    }
}
