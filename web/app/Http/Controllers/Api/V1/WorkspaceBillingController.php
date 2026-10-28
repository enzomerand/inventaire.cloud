<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Billing\Billing;
use App\Billing\BillingCustomers;
use App\Billing\BillingOverview;
use App\Billing\BillingSync;
use App\Billing\CheckoutInProgressException;
use App\Billing\CheckoutParameters;
use App\Billing\PreviousOwnerSubscriptionException;
use App\Billing\StripeClient;
use App\Billing\StripeException;
use App\Billing\SubscriptionStatus;
use App\Billing\WorkspacePlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ManageBillingRequest;
use App\Http\Requests\Billing\StartCheckoutRequest;
use App\Http\Responses\ApiError;
use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DisplayTime;
use App\Support\PublicUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The subscription of a workspace: any member reads what plan it is on and how much of it is used; its owners go to Stripe Checkout to buy
 * Pro, to the customer portal to manage the subscription, and ask for the state of the subscription to be read again at Stripe.
 *
 * A person at their browser does all of it, never a script: the routes refuse an API token (see the routes), and the endpoints are not in
 * the public description of the API. Nothing is believed from the browser coming back from Stripe: the plan changes when the
 * synchronisation reads the subscription ({@see BillingSync}), and `refresh` is how the page asks for that to be done now.
 *
 * Every answer that is not a success carries a `code` the page switches on: `billing_disabled` (the instance sells no plan),
 * `already_subscribed`, `subscription_pending`, `previous_owner_subscription` (the workspace changed hands and the subscription of the
 * owner before is still running), `checkout_in_progress` (another request, or another owner, is opening a payment page for the workspace),
 * `no_billing_account` (`409`), `interval_not_offered` (`422`), and `billing_unavailable` (`503`: Stripe could not be reached or refused
 * the call).
 */
final class WorkspaceBillingController extends Controller
{
    /**
     * The seconds a checkout holds the lock of its workspace: longer than all the Stripe calls it makes one after the other, each of which
     * waits 20 seconds at the most (listing the open sessions, expiring one, reading the subscriptions, updating the customer or creating
     * one, creating the session: about five, so 100 seconds and more with a second page of results). A lock that ran out while the request
     * still worked would let a second checkout in. The lock is released as soon as the request ends, so a long TTL costs nothing but when
     * the process dies (then the workspace waits for it to run out).
     */
    private const CHECKOUT_LOCK_SECONDS = 180;

    /**
     * Gets what the endpoints are made of.
     */
    public function __construct(
        private readonly BillingOverview $overview,
        private readonly BillingCustomers $customers,
        private readonly StripeClient $stripe,
        private readonly BillingSync $sync,
    ) {}

    /**
     * The plan of the workspace, its subscription, the prices, the limits and the usage, for any member: `{data: {enabled, plan, status,
     * interval, current_period_end, cancel_at_period_end, can_manage, prices, limits, usage}}` (see {@see BillingOverview::payload()}).
     * While the instance sells no plan, `enabled` is false and the limits are the ones of the instance.
     */
    public function show(Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return $this->payload($workspace);
    }

    /**
     * Opens a Stripe Checkout session that sells Pro to the workspace for the interval asked for, and answers `{data: {url}}`, the page of
     * Stripe to send the person to (the request checks that they are an owner).
     *
     * **One checkout at a time for a workspace**: an atomic lock of the cache (not a transaction, which would be held while Stripe
     * answers) serialises the requests for the same workspace; one that finds it taken is refused with `409` `checkout_in_progress`
     * (nothing was done: the page says to try again). Inside it, in this order:
     *
     * 1. the Checkout sessions of the customer that are still open are **expired**, so that two tabs cannot both end in a subscription,
     *    and nothing can be paid from here on. A session that cannot be expired because it is not open any more (`400`: it was paid, or
     *    ended by itself) is not a failure;
     * 2. the subscriptions of the customer are **read at Stripe** (not taken from the row, which a payment whose webhook is on its way has
     *    not changed yet), which also sees what a session paid meanwhile started: `409` `already_subscribed` when one gives Pro,
     *    `subscription_pending` when one is still in play without giving it (incomplete, unpaid, paused: it is settled in the portal, not
     *    bought again), `previous_owner_subscription` when the owner is not the payer and the subscription of the payer is in play;
     * 3. the customer is made ready ({@see BillingCustomers::ensure()}) and the session is created.
     *
     * The route paces the calls per person. `409` `billing_disabled` when the instance sells no plan; `422` (`interval_not_offered`) for a
     * yearly plan when no yearly Price is set; `503` (`billing_unavailable`) when Stripe fails.
     */
    public function checkout(StartCheckoutRequest $request, Workspace $workspace): JsonResponse
    {
        if (! Billing::enabled()) {
            return $this->disabled();
        }

        $interval = $request->chosenInterval();
        $price = Billing::priceFor($interval);

        if ($price === null) {
            return ApiError::invalid(__('This billing period is not offered.'), 'interval', 'interval_not_offered');
        }

        $lock = Cache::lock('billing-checkout:'.$workspace->ulid, self::CHECKOUT_LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->checkoutInProgress();
        }

        try {
            return $this->openCheckout($workspace, $this->user($request), $price);
        } finally {
            $lock->release();
        }
    }

    /**
     * Opens the session, with the checkout of the workspace held by this request (see {@see self::checkout()}).
     */
    private function openCheckout(Workspace $workspace, User $owner, string $price): JsonResponse
    {
        try {
            $account = $this->customers->find($workspace);

            if ($account !== null) {
                // Outside any transaction, as every call to Stripe.
                $this->expireOpenSessionsOf($account);

                $refusal = $this->refusalFor($this->sync->sync($account), $owner);

                if ($refusal !== null) {
                    return $refusal;
                }
            }

            $account = $this->customers->ensure($workspace, $owner, App::getLocale());

            $session = $this->stripe->createCheckoutSession(CheckoutParameters::make(
                $account->stripe_customer_id,
                $price,
                $workspace->ulid,
                PublicUrls::to($this->pagePath($workspace).'?checkout=done'),
                PublicUrls::to($this->pagePath($workspace)),
                App::getLocale(),
            ));
        } catch (PreviousOwnerSubscriptionException $previous) {
            return $this->previousOwnerSubscription($previous->account);
        } catch (CheckoutInProgressException) {
            return $this->checkoutInProgress();
        } catch (StripeException $exception) {
            return $this->unavailable($exception, $workspace);
        }

        return new JsonResponse(['data' => ['url' => $session['url']]]);
    }

    /**
     * Opens a session of the Stripe customer portal for the workspace and answers `{data: {url}}` (the request checks that the person is an
     * owner). `409` `billing_disabled`, or `no_billing_account` when the workspace never went to Checkout; `409`
     * `previous_owner_subscription` for an owner who is not the payer while the subscription of the payer is in play (they are not handed
     * the customer of somebody else: once that subscription is over they get a customer of their own, see {@see BillingCustomers}); `409`
     * `checkout_in_progress` when another owner stored their customer first; `503` when Stripe fails (also, and told to the administrators
     * as the synchronisation tells it, when the payer's customer is not at Stripe while the row says the workspace has a subscription).
     */
    public function portal(ManageBillingRequest $request, Workspace $workspace): JsonResponse
    {
        if (! Billing::enabled()) {
            return $this->disabled();
        }

        $account = $this->customers->find($workspace);

        if ($account === null) {
            return $this->noAccount();
        }

        $owner = $this->user($request);

        try {
            if ($this->customers->isPayer($account, $owner)) {
                $this->follow($account, $owner);
            } else {
                // Read now: the row may be hours old, and a subscription in play must not be forgotten (`startFresh()` refuses it).
                $account = $this->sync->sync($account);
                $account = $this->customers->startFresh($workspace, $account, $owner, App::getLocale());
            }

            $url = $this->stripe->createPortalSession($account->stripe_customer_id, PublicUrls::to($this->pagePath($workspace)), App::getLocale());
        } catch (PreviousOwnerSubscriptionException $previous) {
            return $this->previousOwnerSubscription($previous->account);
        } catch (CheckoutInProgressException) {
            return $this->checkoutInProgress();
        } catch (StripeException $exception) {
            return $this->unavailable($exception, $workspace);
        }

        return new JsonResponse(['data' => ['url' => $url]]);
    }

    /**
     * Gives the customer of the payer their current email before the portal is opened. A customer Stripe does not know while the row says
     * the workspace has a subscription in play is told like the synchronisation tells it ({@see BillingSync::reportLostCustomer()}: the log
     * and the administrators), and the error of Stripe goes up as it would.
     *
     * @throws StripeException
     */
    private function follow(BillingAccount $account, User $owner): void
    {
        try {
            $this->customers->follow($account, $owner);
        } catch (StripeException $exception) {
            if ($exception->missing() && in_array($account->status, SubscriptionStatus::CURRENT, true)) {
                $this->sync->reportLostCustomer($account);
            }

            throw $exception;
        }
    }

    /**
     * Reads the subscription of the workspace at Stripe now, and answers like {@see self::show()}: how the page learns that a payment went
     * through without waiting for the webhook or the schedule (the request checks that the person is an owner; the route paces it).
     * `409` `billing_disabled`, or `no_billing_account`; `503` when Stripe fails.
     */
    public function refresh(ManageBillingRequest $request, Workspace $workspace): JsonResponse
    {
        if (! Billing::enabled()) {
            return $this->disabled();
        }

        $account = $this->customers->find($workspace);

        if ($account === null) {
            return $this->noAccount();
        }

        try {
            $this->sync->sync($account);
        } catch (StripeException $exception) {
            return $this->unavailable($exception, $workspace);
        }

        return $this->payload($workspace);
    }

    /**
     * Expires the Checkout sessions of the customer of an account that are still open. One that is not open any more (Stripe answers
     * `400`: it was paid, or it ended by itself) is left: the subscriptions are read right after, and see what a payment started.
     *
     * A customer Stripe does not know (`resource_missing`) has no session to expire: the list is empty, and the rest of the checkout decides
     * what to do with such a customer (a workspace with no subscription in play gets a new one, see {@see BillingCustomers::ensure()}; one
     * that has a subscription in play is refused when it is synchronised).
     *
     * @throws StripeException When the sessions cannot be listed for another reason, or one cannot be expired for another reason.
     */
    private function expireOpenSessionsOf(BillingAccount $account): void
    {
        try {
            $sessions = $this->stripe->openCheckoutSessionsOf($account->stripe_customer_id);
        } catch (StripeException $exception) {
            if (! $exception->missing()) {
                throw $exception;
            }

            $sessions = [];
        }

        foreach ($sessions as $open) {
            try {
                $this->stripe->expireCheckoutSession($open);
            } catch (StripeException $exception) {
                if ($exception->status() !== 400) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Why the person may not buy a subscription for the workspace, from the account as the synchronisation just wrote it (the subscription
     * it keeps is the one that ranks first: Pro, else one still in play, else the newest that is over); null when they may.
     */
    private function refusalFor(BillingAccount $account, User $owner): ?JsonResponse
    {
        $status = $account->status;

        // Before the other refusals: a subscription somebody else pays for is not one this person can settle or manage.
        if (in_array($status, SubscriptionStatus::CURRENT, true) && ! $this->customers->isPayer($account, $owner)) {
            return $this->previousOwnerSubscription($account);
        }

        if (in_array($status, WorkspacePlan::PRO_STATUSES, true)) {
            return ApiError::conflict(__('This workspace already has a subscription.'), 'already_subscribed');
        }

        if (in_array($status, SubscriptionStatus::CURRENT, true)) {
            return ApiError::conflict(__('This workspace has a subscription that is not settled yet. Wait for its payment to be confirmed, or settle it from the subscription management.'), 'subscription_pending');
        }

        return null;
    }

    /**
     * The answer when the workspace changed hands and the subscription of the owner before is still in play: `409`
     * `previous_owner_subscription`, with `ends_at` (the end of the period that is paid for, in UTC) when the subscription is set to end
     * there, null otherwise. The message says the date when there is one.
     */
    private function previousOwnerSubscription(BillingAccount $account): JsonResponse
    {
        $end = $account->cancel_at_period_end ? $account->current_period_end : null;

        $message = $end === null
            ? __('The subscription of the previous owner of this workspace is still running. You can subscribe once it has ended.')
            : __('The subscription of the previous owner of this workspace runs until :date. You can subscribe once it has ended.', [
                'date' => DisplayTime::of($end)->locale(App::getLocale())->isoFormat('LL'),
            ]);

        return ApiError::conflict($message, 'previous_owner_subscription', ['ends_at' => $end?->toIso8601String()]);
    }

    /**
     * The overview of the workspace as the person who asks may see it.
     */
    private function payload(Workspace $workspace): JsonResponse
    {
        return new JsonResponse(['data' => $this->overview->payload($workspace, Gate::allows('manage', $workspace))]);
    }

    /**
     * The path of the subscription page of the workspace.
     */
    private function pagePath(Workspace $workspace): string
    {
        return route('app.workspace.billing', ['workspace' => $workspace->ulid], false);
    }

    /**
     * The answer when the instance sells no plan.
     */
    private function disabled(): JsonResponse
    {
        return ApiError::conflict(__('This service is not offered on this instance.'), 'billing_disabled');
    }

    /**
     * The answer when another request is opening a payment page for the workspace: the person tries again in a moment.
     */
    private function checkoutInProgress(): JsonResponse
    {
        return ApiError::conflict(__('A payment page is already being opened for this workspace. Try again in a moment.'), 'checkout_in_progress');
    }

    /**
     * The answer when the workspace has no customer at Stripe yet.
     */
    private function noAccount(): JsonResponse
    {
        return ApiError::conflict(__('This workspace has no subscription to manage yet.'), 'no_billing_account');
    }

    /**
     * The answer when Stripe failed. What went wrong is written in the log (the exception never holds the key nor anything about the
     * customer), and the person is told to try again.
     */
    private function unavailable(StripeException $exception, Workspace $workspace): JsonResponse
    {
        Log::warning('Stripe could not do what a workspace asked for.', [
            'workspace' => $workspace->ulid,
            'error' => $exception->getMessage(),
            'code' => $exception->code(),
            'status' => $exception->status(),
            'request' => $exception->requestId(),
        ]);

        return ApiError::unavailable(__('The payment service is unavailable right now. Please try again in a moment.'), 'billing_unavailable');
    }
}
