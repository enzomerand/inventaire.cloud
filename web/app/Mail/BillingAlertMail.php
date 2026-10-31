<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Currencies;
use App\Billing\Withdrawals;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The notice the administrators of the instance receive when something about the money needs a person in the dashboard of Stripe. Three
 * families:
 *
 * - **a risk Stripe reports on a payment**: a customer disputed a charge (`charge.dispute.created`), or Stripe received an early fraud
 *   warning about one (`radar.early_fraud_warning.created`). Both ask for an answer within a few days, and nothing in the application can
 *   give it. The mail says which event it is, the reference of the dispute or of the warning, the amount when Stripe gave one, and where
 *   to look at it in the dashboard (a link that opens the test mode of the dashboard for an event of the test mode);
 * - **a problem the application found with a customer** ({@see self::PROBLEMS}, built by {@see self::problem()}): a payment for a
 *   workspace that no longer exists, a subscription that could not be cancelled, a customer Stripe does not know any more, a customer
 *   with two subscriptions, a mail about a contract that could not be delivered. The mail says what happened and what to do about it,
 *   the customer (`cus_…`, the only thing it says about them: no name, no address) and the workspace when it is known, and links to
 *   the customer in the dashboard;
 * - **a task for a person** ({@see self::TASKS}, built by {@see self::withdrawal()}): a customer withdrew from their contract. The
 *   subscription is being cancelled, and the refund is not made by the application: the mail gives what was paid, what is to be
 *   refunded (or says the amount is to be checked when the invoice could not be read), the deadline and whether the acknowledgement
 *   reached the person.
 *
 * It carries no code: it is queued like any mail that holds no secret, in the language of the administrator who receives it. Plain text
 * plus a simple HTML version with no external image, font or tracking.
 *
 * The caller hands it what it checked: a reference of the shape of an id, an amount in whole minor units, a currency code of three letters.
 * The amount is shown with the decimals of its currency: none for the yen or the won, three for the Kuwaiti dinar.
 */
final class BillingAlertMail extends Mailable
{
    /** How many times the queue sends the mail: the alert of a withdrawal carries a legal deadline for a refund. */
    public int $tries = 5;

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
     *   told once a day for the whole instance, with the first customer found;
     * - `double_subscription`: a customer has two subscriptions in play (a double checkout); the older one is to be cancelled and refunded;
     * - `contract_mail_failed`: a mail the customer must receive about their contract (confirmation with the terms, receipt of a
     *   cancellation, notice of renewal, acknowledgement of a withdrawal) could not be delivered after every try.
     */
    public const PROBLEMS = [
        'checkout_orphaned' => 'customers',
        'checkout_unmarked' => 'customers',
        'cancellation_failed' => 'customers',
        'customer_lost' => 'customers',
        'double_subscription' => 'customers',
        'contract_mail_failed' => 'customers',
    ];

    /**
     * The tasks the administrators are given, and the page of the dashboard of Stripe where to do them.
     *
     * - `withdrawal`: a customer withdrew from their contract; the subscription is cancelled by the application, the refund is up to a person.
     */
    public const TASKS = [
        'withdrawal' => 'customers',
    ];

    /**
     * @param  string  $eventType  A key of {@see self::PAGES}, or of {@see self::PROBLEMS}.
     * @param  string|null  $objectId  The id of the dispute or of the warning (`du_…`, `issfr_…`), or of the customer (`cus_…`) for a problem; null when the event carried none.
     * @param  int|null  $amount  What the dispute is about, in the smallest unit of its currency, null when Stripe gave none.
     * @param  string|null  $currency  The lowercase ISO code of the currency of the amount.
     * @param  bool  $livemode  Whether the event comes from the live mode of Stripe, and not from its test mode.
     * @param  string|null  $workspaceUlid  The workspace a problem is about, when it is known.
     * @param  array{subscription: string, paid: int|null, refund: int|null, submitted_at: CarbonInterface, deadline: CarbonInterface, acknowledged: bool}|null  $withdrawal  What a withdrawal gives to do (see {@see self::withdrawal()}).
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $objectId,
        public readonly ?int $amount,
        public readonly ?string $currency,
        public readonly bool $livemode,
        public readonly ?string $workspaceUlid = null,
        public readonly ?array $withdrawal = null,
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
     * A withdrawal: a customer used their right of withdrawal, the subscription is being cancelled and the refund is to be made by hand.
     *
     * @param  string  $customerId  The customer at Stripe (`cus_…`).
     * @param  string  $subscriptionId  The subscription that was withdrawn from (`sub_…`).
     * @param  string|null  $workspaceUlid  The workspace, while it exists.
     * @param  int|null  $paid  What the latest paid invoice was, in the smallest unit of `$currency`; null when it could not be read.
     * @param  int|null  $refund  What is to be refunded (what was paid less the share of the service provided); null when not known.
     * @param  string|null  $currency  The lowercase ISO code of the two amounts.
     * @param  CarbonInterface  $submittedAt  When the declaration was received.
     * @param  bool  $acknowledged  Whether the acknowledgement to the person was queued.
     * @param  bool  $livemode  Whether the customer is in the live mode of Stripe.
     */
    public static function withdrawal(string $customerId, string $subscriptionId, ?string $workspaceUlid, ?int $paid, ?int $refund, ?string $currency, CarbonInterface $submittedAt, bool $acknowledged, bool $livemode): self
    {
        return new self('withdrawal', $customerId, $refund, $currency, $livemode, $workspaceUlid, [
            'subscription' => $subscriptionId,
            'paid' => $paid,
            'refund' => $refund,
            'submitted_at' => $submittedAt,
            'deadline' => CarbonImmutable::instance($submittedAt)->addDays(Withdrawals::REFUND_DAYS),
            'acknowledged' => $acknowledged,
        ]);
    }

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
     * Writes in the log what the mail would have said, once the last try failed, so that nothing the administrators were to do is lost with
     * it (for a withdrawal: the customer, the subscription, what to refund and by when). Nothing about the person but the Stripe references.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('A mail to an administrator about a payment could not be delivered after every try.', [
            'event' => $this->eventType,
            'object' => $this->objectId,
            'workspace' => $this->workspaceUlid,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'withdrawal' => $this->withdrawal === null ? null : [
                'subscription' => $this->withdrawal['subscription'],
                'paid' => $this->withdrawal['paid'],
                'refund' => $this->withdrawal['refund'],
                'refund_before' => $this->withdrawal['deadline']->toIso8601String(),
            ],
            'exception' => $exception,
        ]);
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
     * Whether the mail is a task for a person (a withdrawal to refund), and not an event of Stripe nor a problem.
     */
    public function isTask(): bool
    {
        return isset(self::TASKS[$this->eventType]);
    }

    /**
     * Where the dispute, the warning or the customer is in the dashboard of Stripe (the page of its kind when there is no reference).
     */
    public function dashboardUrl(): string
    {
        $page = self::PAGES[$this->eventType] ?? self::PROBLEMS[$this->eventType] ?? self::TASKS[$this->eventType] ?? 'payments';

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
                'task' => $this->isTask(),
                'explanation' => $this->explanation(),
                'taskLines' => $this->taskLines(),
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
            'contract_mail_failed' => __('A mail about a contract could not be delivered'),
            'withdrawal' => __('A customer withdrew from their contract'),
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
            'customer_lost' => __('A workspace has a subscription on this instance, but Stripe does not know its customer (were the Stripe keys changed, or switched between the test and the live mode?). Nothing was changed: check STRIPE_SECRET before anyone pays again. If you checked in the dashboard that the customer really was deleted, forget it with: php artisan inventaire:billing-forget WORKSPACE (the workspace reference is given below). This alert is sent once a day for the whole instance and names the first customer found: others may follow, the log lists each of them.'),
            'double_subscription' => __('This customer has more than one subscription in play (a double checkout). The newest one is followed: cancel the other in the dashboard and refund it.'),
            'contract_mail_failed' => __('A mail this customer must receive about their contract (the confirmation with the terms, the receipt of a cancellation, the notice of renewal or the acknowledgement of a withdrawal) could not be delivered after every try. Check the mail settings of the instance and its log: a confirmation, a receipt and a notice are queued again by the next synchronisation; an acknowledgement is not, write to the customer.'),
            'withdrawal' => __('This customer used their right of withdrawal. Their subscription is being cancelled by the application; nothing is refunded automatically: refund the amount below in the Stripe dashboard before the deadline.'),
            default => '',
        };
    }

    /**
     * The lines of a withdrawal: the subscription, when it was received, what was paid and what is to be refunded (or that the amount is to
     * be checked), the deadline and whether the acknowledgement was sent. Empty for anything else.
     *
     * @return list<string>
     */
    private function taskLines(): array
    {
        if ($this->withdrawal === null) {
            return [];
        }

        $currency = $this->currency;
        $paid = $this->withdrawal['paid'] !== null && $currency !== null ? $this->moneyLabel($this->withdrawal['paid'], $currency) : null;
        $refund = $this->withdrawal['refund'] !== null && $currency !== null ? $this->moneyLabel($this->withdrawal['refund'], $currency) : null;
        $received = DisplayTime::of($this->withdrawal['submitted_at'])->locale(app()->getLocale());

        return [
            __('Subscription: :value', ['value' => $this->withdrawal['subscription']]),
            __('Declaration received on: :value', ['value' => $received->isoFormat('LLL').' ('.DisplayTime::offset($received).')']),
            $paid === null ? __('Amount paid: amount to be checked') : __('Amount paid: :value', ['value' => $paid]),
            $refund === null ? __('Refund to make: amount to be checked') : __('Refund to make: :value', ['value' => $refund]),
            __('Refund before: :value', ['value' => DisplayTime::of($this->withdrawal['deadline'])->locale(app()->getLocale())->isoFormat('LL')]),
            $this->withdrawal['acknowledged']
                ? __('The acknowledgement was sent to the customer.')
                : __('The acknowledgement could NOT be sent to the customer: write to them.'),
            // The amount comes from the latest paid invoice alone: a change of plan in the portal, or a refund already made, is not in it.
            __('Check the amount against Stripe: it is computed from the latest paid invoice only, so a plan change in the portal or an earlier partial refund would make it wrong.'),
        ];
    }

    /**
     * The amount as a person reads it (`12.50 EUR`); null when there is none.
     */
    private function amountLabel(): ?string
    {
        return $this->amount === null || $this->currency === null ? null : $this->moneyLabel($this->amount, $this->currency);
    }

    /**
     * An amount in the smallest unit of a currency, with the decimals of that currency (`12.50 EUR`).
     */
    private function moneyLabel(int $amount, string $currency): string
    {
        $decimals = Currencies::decimals($currency);

        return number_format(Currencies::major($amount, $currency), $decimals, '.', ' ').' '.strtoupper($currency);
    }
}
