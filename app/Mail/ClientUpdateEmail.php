<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reusable branded "here's what shipped" client update — one-off status
 * emails to clients/stakeholders, distinct from the app's transactional
 * notifications (NewUserRegistered, SchedulerHealthAlert). Data-driven so
 * new updates don't need new Mailable/view code, only new content.
 */
class ClientUpdateEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $bullets
     */
    public function __construct(
        public string $subjectLine,
        public string $greeting,
        public string $intro,
        public array $bullets,
        public ?string $outro = null,
        public string $signOff = 'Austin',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.client-update');
    }
}
