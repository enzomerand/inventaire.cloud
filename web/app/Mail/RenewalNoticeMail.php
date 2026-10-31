<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\ContractNotices;
use App\Mail\Concerns\FormatsContractMail;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

/**
 * The notice of tacit renewal of a yearly subscription (article L215-1 of the consumer code): sent by a mail of its own, between three
 * months and a month before the end of the period, it tells that the contract renews, and says in a boxed line the last moment to refuse
 * the renewal: the end of the period, **with its time and zone**, because Stripe bills at that very instant and a customer who cancels the
 * afternoon of that day is charged a year. Queued once for each period by `inventaire:send-renewal-notices` ({@see ContractNotices}).
 */
final class RenewalNoticeMail extends Mailable
{
    use FormatsContractMail;

    /**
     * @param  string  $name  The name of the person, for the greeting.
     * @param  string  $workspaceName  The workspace the contract is for.
     * @param  CarbonImmutable  $renewsAt  The end of the period: the date the contract renews, and the last date to refuse it.
     * @param  string  $billingUrl  The absolute address of the subscription page of the workspace, where the contract is cancelled.
     * @param  int  $billingAccountId  The account whose claim is given back when the mail cannot be delivered.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $workspaceName,
        public readonly CarbonImmutable $renewsAt,
        public readonly string $billingUrl,
        public readonly int $billingAccountId,
    ) {}

    /**
     * The subject names the date: it is the reason for the mail.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your yearly :app Pro subscription renews on :date', ['app' => config('app.name'), 'date' => self::day($this->renewsAt)]));
    }

    /**
     * Both bodies. The data is made here, while the language of the person is in force.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.renewal-notice-html',
            text: 'mail.renewal-notice',
            with: [
                'workspaceLabel' => PlainText::oneLine($this->workspaceName),
                // The subscription is billed at that very instant: a cancellation that afternoon is too late, so the time and its zone are told.
                'deadlineLabel' => self::moment($this->renewsAt),
                'renewsLabel' => self::day($this->renewsAt),
                'priceLabel' => self::priceLabel('year'),
            ],
        );
    }

    /**
     * Gives the claim back so that the notice is queued again, and tells the administrators, when the mail could not be sent.
     */
    public function failed(Throwable $exception): void
    {
        app(ContractNotices::class)->deliveryFailed($this->billingAccountId, 'renewal_notice_sent_for', $this->renewsAt, null, $exception);
    }
}
