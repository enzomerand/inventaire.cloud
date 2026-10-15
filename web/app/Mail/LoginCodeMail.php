<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email carrying a sign-in code.
 *
 * It is sent once the response has gone ({@see AfterResponse}), not queued: an
 * instance without a queue worker must still deliver it, and a queued job keeps
 * its content readable in the database. The body is plain text plus a simple
 * HTML version with no external image, font or tracking.
 */
final class LoginCodeMail extends Mailable
{
    /**
     * @param  string  $code  The 6-digit code.
     * @param  int  $lifetimeMinutes  How long the code stays valid, for the message.
     */
    public function __construct(
        public readonly string $code,
        public readonly int $lifetimeMinutes,
    ) {}

    /**
     * The subject carries the code, so it can be read from a notification.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your :app sign-in code: :code', ['app' => config('app.name'), 'code' => $this->code]),
        );
    }

    /**
     * Both bodies: a mail client shows the HTML one, the plain text serves the others.
     */
    public function content(): Content
    {
        return new Content(view: 'mail.login-code-html', text: 'mail.login-code');
    }
}
