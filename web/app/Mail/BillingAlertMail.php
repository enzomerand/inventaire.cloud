<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The notice the administrators of the instance receive when something about the money needs a person in the dashboard of Stripe. Two
 * families:
 *
 * - **a risk Stripe reports on a payment**: a customer disputed a charge (`charge.dispute.created`), or Stripe received an early fraud
 *   warning about one (`radar.early_fraud_warning.created`). Both ask for an answer within a few days, and nothing in the application can
 *   give it. The mail says which event it is, the reference of the dispute or of the warning, the amount when Stripe gave one, and where
 *   to look at it in the dashboard (a link that opens the test mode of the dashboard for an event of the test mode);
 * - **a problem the application found with a customer** ({@see self::PROBLEMS}, built by {@see self::problem()}): a payment for a
 *   workspace that no longer exists, a subscription that could not be cancelled, a customer Stripe does not know any more, a customer
 *   with two subscriptions. The mail says what happened and what to do about it, the customer (`cus_…`, the only thing it says about
 *   them: no name, no address) and the workspace when it is known, and links to the customer in the dashboard.
 *
 * It carries no code: it is queued like any mail that holds no secret, in the language of the administrator who receives it. Plain text
 * plus a simple HTML version with no external image, font or tracking.
 *
 * The caller hands it what it checked: a reference of the shape of an id, an amount in whole minor units, a currency code of three letters.
 * The amount is shown with the decimals of its currency: none for the yen or the won, three for the Kuwaiti dinar.
 */
final class BillingAlertMail extends Mailable
{
    /** The events that are told to the administrators, and the page of the dashboard of Stripe where their object is. */
    public const PAGES = [
        'charge.dispute.created' => 'disputes',
        'radar.early_fraud_warning.created' => 'radar/early-fraud-warnings',
    ];

    /**
     * The problems the application finds itself, and the page of the dashboard of Stripe where to look at the customer.
     *
     * - `checkout_orphaned`: a customer paid for a workspace that was deleted meanwhile; its subscription is being cancelled, the payment
     *   is to be refunded;
     * - `checkout_unmarked`: a customer paid for a workspace this instance does not know, but the payment does not carry the marker of
     *   this instance (another site sharing the Stripe account, or a payment opened before the marker existed); nothing was cancelled;
     * - `cancellation_failed`: the subscriptions of a customer could not be cancelled (the workspace is gone, or changed hands): cancel
     *   them there;
     * - `customer_lost`: a workspace has a subscription but Stripe does not know its customer (the keys of the instance were changed?);
     * - `double_subscription`: a customer has two subscriptions in play (a double checkout); the older one is to be cancelled and refunded.
     */
    public const PROBLEMS = [
        'checkout_orphaned' => 'customers',
        'checkout_unmarked' => 'customers',
        'cancellation_failed' => 'customers',
        'customer_lost' => 'customers',
        'double_subscription' => 'customers',
    ];

    /** The currencies whose amount is not counted in hundredths but in whole units (Stripe gives it as such). */
    private const WHOLE_UNIT_CURRENCIES = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** The currencies whose amount is counted in thousandths. */
    private const THOUSANDTH_CURRENCIES = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

    /**
     * @param  string  $eventType  A key of {@see self::PAGES}, or of {@see self::PROBLEMS}.
     * @param  string|null  $objectId  The id of the dispute or of the warning (`du_…`, `issfr_…`), or of the customer (`cus_…`) for a problem; null when the event carried none.
     * @param  int|null  $amount  What the dispute is about, in the smallest unit of its currency, null when Stripe gave none.
     * @param  string|null  $currency  The lowercase ISO code of the currency of the amount.
     * @param  bool  $livemode  Whether the event comes from the live mode of Stripe, and not from its test mode.
     * @param  string|null  $workspaceUlid  The workspace a problem is about, when it is known.
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $objectId,
        public readonly ?int $amount,
        public readonly ?string $currency,
        public readonly bool $livemode,
        public readonly ?string $workspaceUlid = null,
    ) {}

    /**
     * A problem the application found with a customer.
     *
     * @param  string  $kind  A key of {@see self::PROBLEMS}.
     * @param  string  $customerId  The customer at Stripe (`cus_…`).
     * @param  string|null  $workspaceUlid  The workspace it belonged to, when it is known.
     * @param  bool  $livemode  Whether the customer is in the live mode of Stripe, and not in its test mode.
     */
    public static function problem(string $kind, string $customerId, ?string $workspaceUlid, bool $livemode): self
    {
        return new self($kind, $customerId, null, null, $livemode, $workspaceUlid);
    }

    /**
     * Whether an event type is one of Stripe's that the administrators are told about.
     */
    public static function handles(string $eventType): bool
    {
        return isset(self::PAGES[$eventType]);
    }

    /**
     * Whether the mail is about a problem the application found, and not about an event Stripe sent.
     */
    public function isProblem(): bool
    {
        return isset(self::PROBLEMS[$this->eventType]);
    }

    /**
     * Where the dispute, the warning or the customer is in the dashboard of Stripe (the page of its kind when there is no reference).
     */
    public function dashboardUrl(): string
    {
        $page = self::PAGES[$this->eventType] ?? self::PROBLEMS[$this->eventType] ?? 'payments';

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
                'problem' => $this->isProblem(),
                'explanation' => $this->explanation(),
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
        return match ($this->eventType) {
            'charge.dispute.created' => __('A customer disputed a payment'),
            'checkout_orphaned' => __('A payment was made for a workspace that no longer exists'),
            'checkout_unmarked' => __('A payment was made for a workspace this instance does not know'),
            'cancellation_failed' => __('A subscription could not be cancelled'),
            'customer_lost' => __('Stripe does not know the customer of a workspace'),
            'double_subscription' => __('A customer has two subscriptions'),
            default => __('Stripe received an early fraud warning'),
        };
    }

    /**
     * What to do about a problem, in a sentence or two; nothing for an event of Stripe.
     */
    private function explanation(): string
    {
        return match ($this->eventType) {
            'checkout_orphaned' => __('A customer paid on Stripe for a workspace that was deleted meanwhile. The subscription is being cancelled automatically: check in the dashboard that it ended, and refund the payment.'),
            'checkout_unmarked' => __('A customer paid on Stripe for a workspace that does not exist on this instance, and the payment is not marked as made through it. Nothing was cancelled. If another site or instance uses the same Stripe account, ignore this alert; otherwise cancel the subscription and refund the payment in the dashboard.'),
            'cancellation_failed' => __('The subscriptions of this customer could not be cancelled automatically, and the customer may go on paying for a workspace that is gone or that they no longer own. Cancel them in the dashboard.'),
            'customer_lost' => __('A workspace has a subscription on this instance, but Stripe does not know its customer (were the Stripe keys changed, or switched between the test and the live mode?). Nothing was changed: check STRIPE_SECRET before anyone pays again. If you checked in the dashboard that the customer really was deleted, forget it with: php artisan inventaire:billing-forget WORKSPACE (the workspace reference is given below).'),
            'double_subscription' => __('This customer has more than one subscription in play (a double checkout). The newest one is followed: cancel the other in the dashboard and refund it.'),
            default => '',
        };
    }

    /**
     * The amount as a person reads it (`12.50 EUR`); null when there is none.
     */
    private function amountLabel(): ?string
    {
        if ($this->amount === null || $this->currency === null) {
            return null;
        }

        $decimals = match (true) {
            in_array($this->currency, self::WHOLE_UNIT_CURRENCIES, true) => 0,
            in_array($this->currency, self::THOUSANDTH_CURRENCIES, true) => 3,
            default => 2,
        };

        return number_format($this->amount / 10 ** $decimals, $decimals, '.', ' ').' '.strtoupper($this->currency);
    }
}
