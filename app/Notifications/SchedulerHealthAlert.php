<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by `scheduler:health` when any scheduled command has a problem (never
 * fired, failed, hung, ran off-schedule, stopped firing, or over-fired) so an
 * operator is alerted without running the report by hand.
 */
class SchedulerHealthAlert extends Notification
{
    use Queueable;

    /**
     * One-sentence, plain-language explanation per problem category, shown
     * alongside its command list so the alert is readable without needing to
     * already know what each detector category means.
     */
    private const DESCRIPTIONS = [
        'never_fired' => 'had a scheduled slot in the window but never started',
        'failed' => 'exited with an error',
        'unfinished_runs' => "started but hasn't completed within its own runtime budget",
        'off_schedule' => 'last run started well outside its cron slot',
        'off_schedule_fires' => 'one or more runs in the window landed outside their cron slot',
        'stopped_firing' => "hasn't fired in over 1.5x its normal cadence",
        'over_fired' => 'ran more times than its schedule allows (possible duplicate trigger)',
    ];

    /**
     * @param  array<string, list<string>>  $problems  problem category => command names
     * @param  int  $days  telemetry window analyzed
     */
    public function __construct(
        public readonly array $problems,
        public readonly int $days,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Scheduler health alert: '.$this->flaggedCount().' command(s) have a problem')
            ->line('The scheduler health check found '.$this->flaggedCount().' command(s) with a problem in the last '.$this->days.' day(s):')
            ->line(' ');

        foreach ($this->problems as $category => $commands) {
            $description = self::DESCRIPTIONS[$category] ?? null;
            $label = $description !== null ? "**{$category}** — {$description}" : "**{$category}**";
            $mail->line('• '.$label.': '.implode(', ', $commands));
        }

        return $mail;
    }

    /**
     * Human-readable, newline-separated list of the flagged commands grouped by
     * problem category, e.g. "stopped_firing: seo:sitemap, restaurants:score".
     */
    public function summary(): string
    {
        $lines = [];
        foreach ($this->problems as $category => $commands) {
            $lines[] = $category.': '.implode(', ', $commands);
        }

        return implode("\n", $lines);
    }

    /**
     * Distinct commands flagged across every problem category.
     */
    private function flaggedCount(): int
    {
        return count(array_unique(array_merge(...array_values($this->problems))));
    }
}
