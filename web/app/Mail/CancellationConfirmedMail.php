<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\ContractNotices;
use App\Mail\Concerns\FormatsContractMail;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

/**
 * The receipt of a cancellation (article L215-1-1 of the consumer code): the application confirms it received the notification, and tells
 * on a durable medium the date the contract ends and the effects of the cancellation. Queued once for each cancellation, whoever made it
 * and wherever ({@see ContractNotices}).
 */
final class CancellationConfirmedMail extends Mailable
{
    use FormatsContractMail;

    /** How many times the queue sends the mail. */
    public int $tries = 5;

    /**
     * @param  string  $name  The name of the person, for the greeting.
     * @param  string  $workspaceName  The workspace the contract is for.
     * @param  string  $interval  `month` or `year`.
     * @param  CarbonInterface  $endsAt  The date the contract ends: the end of the period that is paid for.
     * @param  string  $billingUrl  The absolute address of the subscription page of the workspace.
     * @param  int  $billingAccountId  The account whose claim is given back when the mail cannot be delivered.
     * @param  string  $subscriptionId  The subscription that was claimed.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $workspaceName,
        public readonly string $interval,
        public readonly CarbonInterface $endsAt,
        public readonly string $billingUrl,
        public readonly int $billingAccountId,
        public readonly string $subscriptionId,
    ) {}

    /**
     * The seconds to wait before each new try.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    /**
     * The subject names the application and what the mail is.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your :app Pro subscription is cancelled', ['app' => config('app.name')]));
    }

    /**
     * Both bodies. The data is made here, while the language of the person is in force.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.cancellation-confirmed-html',
            text: 'mail.cancellation-confirmed',
            with: [
                'workspaceLabel' => PlainText::oneLine($this->workspaceName),
                'periodLabel' => self::periodLabel($this->interval),
                'endsLabel' => self::day($this->endsAt),
            ],
        );
    }

    /**
     * Gives the claim back so that the receipt is queued again, and tells the administrators, when the mail could not be sent.
     */
    public function failed(Throwable $exception): void
    {
        app(ContractNotices::class)->deliveryFailed($this->billingAccountId, 'cancel_notice_sent_for', $this->subscriptionId, null, $exception);
    }
}
