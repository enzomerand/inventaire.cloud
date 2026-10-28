<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Billing\Billing;
use App\Billing\BillingCustomers;
use App\Billing\BillingOverview;
use App\Billing\BillingSync;
use App\Billing\CheckoutParameters;
use App\Billing\Plan;
use App\Billing\StripeClient;
use App\Billing\StripeException;
use App\Billing\WorkspacePlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ManageBillingRequest;
use App\Http\Requests\Billing\StartCheckoutRequest;
use App\Http\Responses\ApiError;
use App\Models\Workspace;
use App\Support\PublicUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
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
 * `already_subscribed`, `no_billing_account` (`409`), `interval_not_offered` (`422`), and `billing_unavailable` (`503`: Stripe could
 * not be reached or refused the call).
 */
final class WorkspaceBillingController extends Controller
{
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
     * `409` `billing_disabled` when the instance sells no plan, and `already_subscribed` when the workspace is on Pro already; `422`
     * (`interval_not_offered`) for a yearly plan when no yearly Price is set; `503` (`billing_unavailable`) when Stripe fails.
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

        if (WorkspacePlan::for($workspace) === Plan::Pro) {
            return ApiError::conflict(__('This workspace already has a subscription.'), 'already_subscribed');
        }

        try {
            $account = $this->customers->ensure($workspace, $this->user($request), App::getLocale());
            $session = $this->stripe->createCheckoutSession(CheckoutParameters::make(
                $account->stripe_customer_id,
                $price,
                $workspace->ulid,
                PublicUrls::to($this->pagePath($workspace).'?checkout=done'),
                PublicUrls::to($this->pagePath($workspace)),
                App::getLocale(),
            ));
        } catch (StripeException $exception) {
            return $this->unavailable($exception, $workspace);
        }

        return new JsonResponse(['data' => ['url' => $session['url']]]);
    }

    /**
     * Opens a session of the Stripe customer portal for the workspace and answers `{data: {url}}` (the request checks that the person is an
     * owner). `409` `billing_disabled`, or `no_billing_account` when the workspace never went to Checkout; `503` when Stripe fails.
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

        try {
            $this->customers->follow($account, $this->user($request));
            $url = $this->stripe->createPortalSession($account->stripe_customer_id, PublicUrls::to($this->pagePath($workspace)), App::getLocale());
        } catch (StripeException $exception) {
            return $this->unavailable($exception, $workspace);
        }

        return new JsonResponse(['data' => ['url' => $url]]);
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
