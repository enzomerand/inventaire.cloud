<?php

declare(strict_types=1);

namespace App\Installer\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email carrying the verification code of the wizard's mail step.
 *
 * Receiving it proves the SMTP settings work end to end, which is why the step
 * is only validated once the code is typed back.
 */
final class SetupCodeMail extends Mailable
{
    /**
     * @param  string  $code  The 6-digit code.
     * @param  int  $lifetimeMinutes  How long the code stays valid, for the message.
     * @param  string  $fromAddress  Sender address being tested.
     * @param  string  $fromName  Sender name being tested.
     */
    public function __construct(
        public readonly string $code,
        public readonly int $lifetimeMinutes,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    /**
     * Subject and sender: the sender is the one the instance will use, so a
     * rejected sender address shows up now rather than on the first real email.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: __('Your :app verification code: :code', ['app' => config('app.name'), 'code' => $this->code]),
        );
    }

    /**
     * A plain-text body: nothing to render but a code.
     */
    public function content(): Content
    {
        return new Content(text: 'mail.setup-code');
    }
}
