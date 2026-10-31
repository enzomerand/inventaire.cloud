<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\ContractNotices;
use App\Legal\TermsDocument;
use App\Mail\Concerns\FormatsContractMail;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

/**
 * The confirmation of a contract on a durable medium (article L221-13 of the consumer code): what the person bought, for how much and
 * from when, the express request for the immediate start of the service they made at checkout, how to withdraw or cancel, and the terms
 * of sale with the form of withdrawal, as a PDF (always in French, the version that prevails).
 *
 * The PDF is made when the queue builds the mail ({@see TermsDocument::pdf()}): **if it cannot be made the mail is not sent** and the job
 * fails and is tried again, with a pause that grows; after the last try {@see self::failed()} gives the claim of the account back, so the
 * next synchronisation queues it again, and tells the administrators. It is queued once for each subscription ({@see ContractNotices}).
 */
final class SubscriptionConfirmedMail extends Mailable
{
    use FormatsContractMail;

    /** The seconds the queue lets the mail take: laying the PDF out takes well under that. */
    public int $timeout = 120;

    /**
     * @param  string  $name  The name of the person, for the greeting.
     * @param  string  $workspaceName  The workspace the contract is for.
     * @param  string  $interval  `month` or `year`.
     * @param  CarbonInterface|null  $startedAt  The start of the subscription.
     * @param  CarbonInterface|null  $periodEnd  The end of the period that is paid for.
     * @param  CarbonInterface|null  $withdrawalUntil  The last moment of the right of withdrawal.
     * @param  string  $billingUrl  The absolute address of the subscription page of the workspace.
     * @param  string  $termsUrl  The absolute address of the terms of sale.
     * @param  string  $termsVersion  The date of the version of the terms attached (`YYYY-MM-DD`).
     * @param  int  $billingAccountId  The account whose claim is given back when the mail cannot be delivered.
     * @param  string  $subscriptionId  The subscription that was claimed.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $workspaceName,
        public readonly string $interval,
        public readonly ?CarbonInterface $startedAt,
        public readonly ?CarbonInterface $periodEnd,
        public readonly ?CarbonInterface $withdrawalUntil,
        public readonly string $billingUrl,
        public readonly string $termsUrl,
        public readonly string $termsVersion,
        public readonly int $billingAccountId,
        public readonly string $subscriptionId,
    ) {}

    /**
     * The subject names the application and what the mail is.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Confirmation of your :app Pro subscription', ['app' => config('app.name')]));
    }

    /**
     * Both bodies. The data is made here, while the language of the person is in force.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.subscription-confirmed-html',
            text: 'mail.subscription-confirmed',
            with: [
                'workspaceLabel' => PlainText::oneLine($this->workspaceName),
                'periodLabel' => self::periodLabel($this->interval),
                'priceLabel' => self::priceLabel($this->interval),
                'startedLabel' => $this->startedAt === null ? null : self::day($this->startedAt),
                'periodEndLabel' => $this->periodEnd === null ? null : self::day($this->periodEnd),
                // A confirmation that is late (the queue was down) does not offer a right that has run out.
                'withdrawalUntilLabel' => $this->withdrawalUntil === null || $this->withdrawalUntil->isPast() ? null : self::moment($this->withdrawalUntil),
                'termsDateLabel' => CarbonImmutable::parse($this->termsVersion)->settings(['locale' => app()->getLocale()])->isoFormat('LL'),
            ],
        );
    }

    /**
     * The terms of sale as a PDF. Made when the mail is sent: an exception here stops the mail, which the queue tries again.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => app(TermsDocument::class)->pdf(), TermsDocument::FILENAME)->withMime('application/pdf'),
        ];
    }

    /**
     * What to do when the mail could not be sent after every try: give the claim back so that it is queued again, and tell the
     * administrators.
     */
    public function failed(Throwable $exception): void
    {
        app(ContractNotices::class)->deliveryFailed($this->billingAccountId, 'confirmation_sent_for', $this->subscriptionId, null, $exception);
    }
}
