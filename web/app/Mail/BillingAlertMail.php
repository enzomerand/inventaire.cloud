<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The notice the administrators of the instance receive when Stripe reports a risk on a payment: a customer disputed a charge
 * (`charge.dispute.created`), or Stripe received an early fraud warning about one (`radar.early_fraud_warning.created`). Both ask for an
 * answer in the dashboard of Stripe within a few days, and nothing in the application can give it.
 *
 * It says which event it is, the reference of the dispute or of the warning, the amount when Stripe gave one, and where to look at it in
 * the dashboard (a link that opens the test mode of the dashboard for an event of the test mode). It carries nothing about the customer
 * and no code: it is queued like any mail that holds no secret, in the language of the administrator who receives it. Plain text plus a
 * simple HTML version with no external image, font or tracking.
 *
 * The caller hands it what it checked: a reference of the shape of an id, an amount in whole minor units, a currency code of three letters.
 */
final class BillingAlertMail extends Mailable
{
    /** The events that are told to the administrators, and the page of the dashboard of Stripe where their object is. */
    public const PAGES = [
        'charge.dispute.created' => 'disputes',
        'radar.early_fraud_warning.created' => 'radar/early-fraud-warnings',
    ];

    /** The currencies whose amount is not counted in hundredths (Stripe gives it in whole units). */
    private const WHOLE_UNIT_CURRENCIES = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /**
     * @param  string  $eventType  A key of {@see self::PAGES}.
     * @param  string|null  $objectId  The id of the dispute or of the warning (`du_…`, `issfr_…`), null when the event carried none.
     * @param  int|null  $amount  What the dispute is about, in the smallest unit of its currency, null when Stripe gave none.
     * @param  string|null  $currency  The lowercase ISO code of the currency of the amount.
     * @param  bool  $livemode  Whether the event comes from the live mode of Stripe, and not from its test mode.
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $objectId,
        public readonly ?int $amount,
        public readonly ?string $currency,
        public readonly bool $livemode,
    ) {}

    /**
     * Whether an event type is one the administrators are told about.
     */
    public static function handles(string $eventType): bool
    {
        return isset(self::PAGES[$eventType]);
    }

    /**
     * Where the dispute or the warning is in the dashboard of Stripe (the page of its kind when the event carried no reference).
     */
    public function dashboardUrl(): string
    {
        $page = self::PAGES[$this->eventType] ?? 'payments';

        return 'https://dashboard.stripe.com/'.($this->livemode ? '' : 'test/').$page.($this->objectId === null ? '' : '/'.$this->objectId);
    }

    /**
     * The subject names the application and what happened.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Stripe alert on :app: :event', ['app' => config('app.name'), 'event' => $this->eventLabel()]),
        );
    }

    /**
     * Both bodies: a mail client shows the HTML one, the plain text serves the others.
     *
     * The data ready for the views is made here, while the language of the administrator is in force.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.billing-alert-html',
            text: 'mail.billing-alert',
            with: [
                'eventLabel' => $this->eventLabel(),
                'amountLabel' => $this->amountLabel(),
                'dashboardUrl' => $this->dashboardUrl(),
            ],
        );
    }

    /**
     * What happened, in a few words.
     */
    private function eventLabel(): string
    {
        return $this->eventType === 'charge.dispute.created'
            ? __('A customer disputed a payment')
            : __('Stripe received an early fraud warning');
    }

    /**
     * The amount as a person reads it (`12.50 EUR`); null when there is none.
     */
    private function amountLabel(): ?string
    {
        if ($this->amount === null || $this->currency === null) {
            return null;
        }

        $whole = in_array($this->currency, self::WHOLE_UNIT_CURRENCIES, true);

        return number_format($whole ? $this->amount : $this->amount / 100, $whole ? 0 : 2, '.', ' ').' '.strtoupper($this->currency);
    }
}
