<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email an administrator's new account receives: it exists, and how to sign in (there is no password: a code is
 * mailed to the address on the sign-in page).
 *
 * Sent after the response ({@see AfterResponse}), in the default language of the instance, as plain text plus a simple
 * HTML version with no external image, font or tracking. It carries no secret: nothing in it opens the account.
 */
final class WelcomeMail extends Mailable
{
    /**
     * @param  string  $name  The name given to the account.
     * @param  string  $loginUrl  The address of the sign-in page.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $loginUrl,
    ) {}

    /**
     * The subject names the application.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your :app account is ready', ['app' => config('app.name')]),
        );
    }

    /**
     * Both bodies: a mail client shows the HTML one, the plain text serves the others.
     */
    public function content(): Content
    {
        return new Content(view: 'mail.welcome-html', text: 'mail.welcome');
    }
}
