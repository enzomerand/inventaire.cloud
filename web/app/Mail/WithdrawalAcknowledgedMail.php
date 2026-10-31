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
 * The acknowledgement of a withdrawal (article D221-5 of the consumer code): sent on a durable medium to the address the person gave when
 * they declared it, it repeats the declaration (their name, the contract it is about), gives the date and time it was received, and tells
 * the effects: the contract ends, and what is refunded and by when.
 *
 * The amount is told only when the invoice could be read; the time carries its zone, and the refund deadline is fourteen days from the
 * declaration, as the terms say.
 */
final class WithdrawalAcknowledgedMail extends Mailable
{
    use FormatsContractMail;

    /**
     * @param  string  $name  The name the person declared.
     * @param  string  $email  The address the person declared, where this goes.
     * @param  string  $workspaceName  The workspace the contract was for.
     * @param  string  $interval  `month` or `year`.
     * @param  CarbonInterface|null  $orderedAt  The start of the contract (the order).
     * @param  CarbonInterface  $declaredAt  When the declaration was received.
     * @param  CarbonInterface  $refundBefore  The last day the refund is made: fourteen days after the declaration.
     * @param  int|null  $amountPaid  What was paid, in the smallest unit of `currency`; null when it is not known.
     * @param  int|null  $refund  What is refunded, in the same unit; null when it is not known.
     * @param  string|null  $currency  Lowercase ISO code.
     * @param  int|null  $daysUsed  The days of service provided until the declaration, rounded up.
     * @param  int|null  $daysTotal  The days of the period that was paid for.
     * @param  int  $withdrawalId  The withdrawal this acknowledges.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $workspaceName,
        public readonly string $interval,
        public readonly ?CarbonInterface $orderedAt,
        public readonly CarbonInterface $declaredAt,
        public readonly CarbonInterface $refundBefore,
        public readonly ?int $amountPaid,
        public readonly ?int $refund,
        public readonly ?string $currency,
        public readonly ?int $daysUsed,
        public readonly ?int $daysTotal,
        public readonly int $withdrawalId,
    ) {}

    /**
     * The subject names the application and what the mail is.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Acknowledgement of your withdrawal from the :app contract', ['app' => config('app.name')]));
    }

    /**
     * Both bodies. The data is made here, while the language of the person is in force.
     */
    public function content(): Content
    {
        $known = $this->amountPaid !== null && $this->refund !== null && $this->currency !== null;

        return new Content(
            view: 'mail.withdrawal-acknowledged-html',
            text: 'mail.withdrawal-acknowledged',
            with: [
                'declaredName' => PlainText::oneLine($this->name),
                'declaredEmail' => PlainText::oneLine($this->email),
                'workspaceLabel' => PlainText::oneLine($this->workspaceName),
                'periodLabel' => self::periodLabel($this->interval),
                'orderedLabel' => $this->orderedAt === null ? null : self::day($this->orderedAt),
                'declaredLabel' => self::moment($this->declaredAt),
                'refundBeforeLabel' => self::day($this->refundBefore),
                'paidLabel' => $known ? self::money((int) $this->amountPaid, (string) $this->currency) : null,
                'refundLabel' => $known ? self::money((int) $this->refund, (string) $this->currency) : null,
                'daysUsed' => $this->daysUsed,
                'daysTotal' => $this->daysTotal,
            ],
        );
    }

    /**
     * Empties `acknowledged_at` and tells the administrators when the acknowledgement could not be sent after every try: they write to
     * the person.
     */
    public function failed(Throwable $exception): void
    {
        app(ContractNotices::class)->deliveryFailed(null, null, null, $this->withdrawalId, $exception);
    }
}
