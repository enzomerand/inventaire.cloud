/**
 * Alpine component of the subscription page of a workspace: the plan it is on, how much of its limits it uses and, for its owner, the
 * way to buy Pro (Stripe Checkout) and to manage the subscription (the Stripe customer portal).
 *
 * What is shown is what the server holds: the overview is read from the API, which also says whether the person is an owner. The buttons
 * ask the API for the address of a page Stripe hosts and send the browser there; nothing about the payment is decided here. The endpoints
 * come from the markup (`data-*` on the root), because the CSP build of Alpine does not allow Blade to inject values into expressions.
 *
 * Stripe brings the person back to this page with `?checkout=done`. That address proves nothing, so an owner's page asks the API to read
 * the subscription (the plan changes only when it is read from Stripe), then reads the overview twice more, a few seconds apart, until the
 * plan is Pro. Nothing is waited for when the instance sells no plan, when the person is not an owner, or when the overview could not be
 * read. The parameter is taken off the address once the overview was read, whatever came out of the wait, so that a reload does not start
 * it again; while the overview could not be read it stays, so that a reload (the person's way to try again) still knows the person came
 * back from a payment.
 *
 * A workspace that changed hands while the owner before it still pays is not sold a second subscription, nor handed the customer of the
 * old owner: the API refuses the new owner (`previous_owner_subscription`, with the date the subscription ends when it does). The page
 * then says so and offers neither button; it learns it from that refusal, as the overview does not carry it.
 *
 * The two things the terms of sale promise are here too. "Cancel your contract" asks the API for a portal page opened on the cancellation
 * of the subscription (Stripe brings the person back with `?cancellation=done`, which has the subscription read so that the page and the
 * receipt do not wait for the webhook). "Withdraw from the contract here" opens a declaration, offered during the fourteen days after
 * the subscription began (the server says until when and judges again): the name and the address of the person, prefilled and editable,
 * and the contract it is about, read-only. The overview says whether a plan can be bought at all (`selling`): an instance that has Stripe
 * but not yet a complete seller shows a neutral "Coming soon" in place of the button that buys Pro.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; a refusal the API explains with a code
 * is told in words of our own, because the helper trusts the message of a response only for a validation error.
 */
import { dateFormat, numberFormat } from '../fields/locale.js';
import { formatSize } from '../fields/files.js';
import { HttpError, http, leaveStalePage } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';

/**
 * @typedef {object} Overview What the billing endpoint of the API answers (`data`).
 * @property {boolean} enabled Whether the instance has Stripe set up (a plan is shown).
 * @property {boolean} selling Whether Pro can be bought now (the seller is complete as well).
 * @property {'free' | 'pro'} plan
 * @property {string | null} status Stripe's word for the subscription (`active`, `past_due`, `canceled`...).
 * @property {'month' | 'year' | null} interval
 * @property {string | null} current_period_end ISO 8601, in UTC.
 * @property {boolean} cancel_at_period_end
 * @property {string | null} started_at ISO 8601, in UTC: when the subscription began.
 * @property {string | null} withdrawal_until ISO 8601, in UTC: the last moment of the right of withdrawal, while it can be exercised.
 * @property {boolean} withdrawn Whether a withdrawal was recorded for the subscription.
 * @property {boolean} can_manage Whether the person is an owner.
 * @property {{ currency: string, month: number, year: number | null }} prices Amounts in cents of the currency.
 * @property {{ inventories: number, items: number, storage_bytes: number, members: number, share_links: number, photo_entry: boolean }} limits
 * @property {{ inventories: number, storage_bytes: number, members: number }} usage
 */

/** The statuses of a subscription the page has a word for. */
const STATUSES = ['active', 'trialing', 'past_due'];

/** The statuses of a subscription that is in play without giving Pro: it is settled in the portal, not bought again. */
const UNSETTLED_STATUSES = ['incomplete', 'unpaid', 'paused'];

/** How many times the overview is read after the synchronisation when the plan is not Pro yet. */
const POLL_ATTEMPTS = 2;

/** The refusals the API explains with a code: code to message key. */
const MESSAGE_KEYS = {
    billing_disabled: 'billing.error.disabled',
    already_subscribed: 'billing.error.already_subscribed',
    subscription_pending: 'billing.error.subscription_pending',
    checkout_in_progress: 'billing.error.checkout_in_progress',
    no_billing_account: 'billing.error.no_account',
    interval_not_offered: 'billing.error.interval',
    billing_unavailable: 'billing.error.unavailable',
    no_live_subscription: 'billing.error.no_live_subscription',
    cancellation_scheduled: 'billing.error.cancellation_scheduled',
    withdrawal_period_over: 'billing.error.withdrawal_period_over',
    already_withdrawn: 'billing.error.already_withdrawn',
};

/**
 * A date the API gave as the person reads it (the day, in the timezone of the browser).
 *
 * @param {unknown} value The moment in ISO 8601.
 * @returns {string} "" when there is none, or it is not a moment.
 */
function dateText(value) {
    const date = typeof value === 'string' ? new Date(value) : null;

    return date === null || Number.isNaN(date.getTime()) ? '' : dateFormat({ dateStyle: 'long' }).format(date);
}

/**
 * An amount of the smallest unit of a currency (cents) as the person reads it ("6 €", "5,50 €"), in the language of the page.
 *
 * @param {number} cents
 * @param {string} currency ISO 4217 code, in any case.
 * @returns {string}
 */
function moneyText(cents, currency) {
    const amount = cents / 100;

    try {
        return numberFormat({
            style: 'currency',
            currency: currency.toUpperCase(),
            minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
            maximumFractionDigits: 2,
        }).format(amount);
    } catch {
        // A code the browser does not know: the figure and the code, rather than nothing.
        return `${amount} ${currency.toUpperCase()}`;
    }
}

/**
 * The share of a limit that is used, from 0 to 100. A limit of zero is full as soon as anything is used.
 *
 * @param {number} used
 * @param {number} limit
 * @returns {number}
 */
function percentOf(used, limit) {
    if (limit <= 0) {
        return used > 0 ? 100 : 0;
    }

    return Math.min(100, Math.round((used / limit) * 100));
}

/**
 * @returns {Record<string, any>}
 */
export default function workspaceBilling() {
    return {
        /** The overview the API gave, or null until it did. @type {Overview | null} */
        billing: null,

        /** True while the overview is being read (and until it was, so that nothing flashes). */
        loading: true,

        /** True when the overview could not be read: the page then offers to try again. */
        loadFailed: false,

        /** True while a request that leaves for Stripe is in flight; blocks double clicks. It stays true once the browser is on its way (a page brought back from the back/forward cache is reloaded: see lib/restore.js). */
        busy: false,

        /** True while the page waits for the payment it was brought back from to be confirmed. */
        confirming: false,

        /** True when the plan did not change in the time the page waits: the person is told to come back. */
        stillPending: false,

        /**
         * Set once the API refused the owner because the owner before still has a subscription: the date it ends at (ISO 8601, in UTC) or ''
         * when it is not known. Null while nothing said so.
         *
         * @type {string | null}
         */
        previousOwnerEndsAt: null,

        /** How often the person pays, as chosen in the form: `month` or `year`. */
        interval: 'month',

        /** Whether the declaration of a withdrawal is open. */
        withdrawalOpen: false,

        /** True while the declaration is being sent; blocks double submits. */
        withdrawing: false,

        /** The name in the declaration, prefilled from the account. */
        withdrawalName: '',

        /** The address the acknowledgement is sent to, prefilled from the account. */
        withdrawalEmail: '',

        /** Milliseconds between two readings of the overview while the payment is confirmed. */
        pollDelayMs: 3000,

        /** Root element of the component, kept from `init()` because `$root` is gone once an element has left the page. */
        rootElement: null,

        /** Endpoint of the overview (GET), from `data-url` on the component root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /** Endpoint that opens Checkout (POST), from `data-checkout-url` on the component root. */
        get checkoutUrl() {
            return this.rootElement.dataset.checkoutUrl;
        },

        /** Endpoint that opens the customer portal (POST), from `data-portal-url` on the component root. */
        get portalUrl() {
            return this.rootElement.dataset.portalUrl;
        },

        /** Endpoint that has the subscription read at Stripe now (POST), from `data-refresh-url` on the component root. */
        get refreshUrl() {
            return this.rootElement.dataset.refreshUrl;
        },

        /** Endpoint that opens the portal on the cancellation of the subscription (POST), from `data-cancel-url` on the component root. */
        get cancelUrl() {
            return this.rootElement.dataset.cancelUrl;
        },

        /** Endpoint that records a withdrawal (POST), from `data-withdraw-url` on the component root. */
        get withdrawUrl() {
            return this.rootElement.dataset.withdrawUrl;
        },

        /** Whether Pro can be bought now: Stripe is set up and so is the identity of the seller (known once the overview was read). */
        get selling() {
            return this.billing?.selling === true;
        },

        /**
         * Whether the page says "Coming soon" in place of the button that buys Pro: an owner of a workspace that could buy it, on an
         * instance that has Stripe but cannot sell yet (the identity of the seller is not complete).
         */
        get comingSoon() {
            return this.offered && !this.selling && this.canManage && this.upgradeCandidate;
        },

        /** Whether the instance does not sell plans (known once the overview was read). */
        get notOffered() {
            return this.billing !== null && !this.billing.enabled;
        },

        /** Whether the instance sells plans (known once the overview was read). */
        get offered() {
            return this.billing !== null && this.billing.enabled;
        },

        /** Whether the workspace is on the Pro plan. */
        get isPro() {
            return this.billing?.plan === 'pro';
        },

        /** Whether the person is an owner of the workspace, who alone buys and manages the subscription. */
        get canManage() {
            return this.billing?.can_manage === true;
        },

        /**
         * Whether a subscription is in play without giving Pro (incomplete, unpaid, paused): the workspace is not sold a second one, the
         * first is settled in the portal.
         */
        get unsettled() {
            return !this.isPro && UNSETTLED_STATUSES.includes(this.billing?.status ?? '');
        },

        /** Whether the subscription of the owner before is still running, which the API said by refusing this owner. */
        get blockedByPreviousOwner() {
            return this.previousOwnerEndsAt !== null;
        },

        /**
         * Whether the form that buys Pro is offered: an owner, a workspace that is not Pro, no subscription waiting to be settled, no
         * subscription of the owner before, and no payment being confirmed or still waited for (the one the person just made would be paid
         * twice).
         */
        get canUpgrade() {
            return (
                this.canManage &&
                this.selling &&
                !this.isPro &&
                !this.confirming &&
                !this.stillPending &&
                !this.unsettled &&
                !this.blockedByPreviousOwner
            );
        },

        /**
         * Whether the button that buys Pro is on the page: not for a workspace that is on Pro, that has a subscription to settle, or whose
         * owner before still pays.
         */
        get upgradeShown() {
            return this.selling && this.upgradeCandidate;
        },

        /** Whether the workspace is one that could buy Pro: not on it, nothing to settle, no subscription of the owner before. */
        get upgradeCandidate() {
            return !this.isPro && !this.unsettled && !this.blockedByPreviousOwner;
        },

        /**
         * Whether the contract can be cancelled from here: an owner, a workspace on Pro whose subscription does not already end with the
         * period, that was not withdrawn from, and no subscription of the owner before.
         */
        get canCancel() {
            return (
                this.offered &&
                this.canManage &&
                this.isPro &&
                this.billing?.cancel_at_period_end === false &&
                !this.billing?.withdrawn &&
                !this.blockedByPreviousOwner
            );
        },

        /**
         * Whether the declaration of a withdrawal is offered: an owner, while the server says the right can be exercised (a live
         * subscription inside its fourteen days, not withdrawn from yet) and that moment has not passed on this page's clock either.
         */
        get canWithdraw() {
            const until = this.billing?.withdrawal_until;

            return (
                this.offered &&
                this.canManage &&
                typeof until === 'string' &&
                Date.parse(until) >= Date.now() &&
                !this.blockedByPreviousOwner
            );
        },

        /** The last moment of the right of withdrawal as a sentence, with the time ("" when there is none). */
        get withdrawalUntilText() {
            const until = this.billing?.withdrawal_until;
            const date = typeof until === 'string' ? new Date(until) : null;

            if (date === null || Number.isNaN(date.getTime())) {
                return '';
            }

            return t('billing.withdrawal.until', {
                moment: dateFormat({ dateStyle: 'long', timeStyle: 'short' }).format(date),
            });
        },

        /** The contract the declaration is about, in a line: the plan, how often it is paid and when it was ordered. */
        get withdrawalContractText() {
            const date = dateText(this.billing?.started_at);

            return t('billing.withdrawal.contract', {
                plan: t('billing.plan.pro'),
                interval: this.intervalLabel,
                date: date === '' ? '-' : date,
            });
        },

        /** Whether the declaration can be sent: something is in both fields and it is not being sent already. */
        get canSubmitWithdrawal() {
            return !this.withdrawing && this.withdrawalName.trim() !== '' && this.withdrawalEmail.trim() !== '';
        },

        /**
         * Whether the customer portal is offered: the workspace is on Pro, or has a subscription to settle there (not to an owner who is
         * refused it because the owner before still pays).
         */
        get canOpenPortal() {
            return (this.isPro || this.unsettled) && !this.blockedByPreviousOwner;
        },

        /** Whether the card has a button to show: an owner who is offered at least one of the two. */
        get actionsShown() {
            return (
                this.canManage &&
                (this.upgradeShown || this.comingSoon || this.canOpenPortal || this.canCancel || this.canWithdraw)
            );
        },

        /** What the page says of the subscription of the owner before, with the date it ends when it is known ("" when it was not refused). */
        get previousOwnerText() {
            if (this.previousOwnerEndsAt === null) {
                return '';
            }

            const date = dateText(this.previousOwnerEndsAt);

            return date === '' ? t('billing.previous_owner') : t('billing.previous_owner_until', { date });
        },

        /** Whether a yearly Price is offered. */
        get hasYearly() {
            return typeof this.billing?.prices.year === 'number';
        },

        /** Whether the last payment failed while the workspace keeps Pro meanwhile. */
        get pastDue() {
            return this.isPro && this.billing?.status === 'past_due';
        },

        /** The name of the plan. */
        get planLabel() {
            return this.billing === null ? '' : t(`billing.plan.${this.billing.plan}`);
        },

        /** The classes of the badge of the plan. */
        get planClass() {
            return this.isPro ? 'bg-primary-lt' : 'bg-secondary-lt';
        },

        /** What the subscription is doing, in a word ("" for a status the page has no word for). */
        get statusLabel() {
            const status = this.billing?.status ?? '';

            return STATUSES.includes(status) ? t(`billing.status.${status}`) : '';
        },

        /** The classes of the badge of the status: a payment that failed is a warning. */
        get statusClass() {
            return this.billing?.status === 'past_due' ? 'bg-yellow-lt' : 'bg-green-lt';
        },

        /** How often the subscription is paid ("" when unknown). */
        get intervalLabel() {
            const interval = this.billing?.interval ?? '';

            return interval === 'month' || interval === 'year' ? t(`billing.interval.${interval}`) : '';
        },

        /** When the period that is paid for ends, as a sentence: the subscription renews, or ends if it was canceled ("" when unknown). */
        get periodText() {
            const date = dateText(this.billing?.current_period_end);

            if (date === '') {
                return '';
            }

            return t(this.billing?.cancel_at_period_end ? 'billing.ends' : 'billing.renews', { date });
        },

        /** What the page says of a Pro subscription that is over, for a workspace that is back on Free ("" otherwise). */
        get endedText() {
            const date = dateText(this.billing?.current_period_end);

            return !this.isPro && this.billing?.status === 'canceled' && date !== ''
                ? t('billing.ended', { date })
                : '';
        },

        /** What the monthly plan costs, as the radio shows it. */
        get monthPrice() {
            return this.priceText('month');
        },

        /** What the yearly plan costs, as the radio shows it. */
        get yearPrice() {
            return this.priceText('year');
        },

        /** The message that is shown, and read out, while the payment is confirmed ("" when there is none). */
        get notice() {
            if (this.confirming) {
                return t('billing.confirming');
            }

            if (this.stillPending) {
                return t('billing.still_pending');
            }

            if (this.canManage && this.blockedByPreviousOwner) {
                return this.previousOwnerText;
            }

            if (this.canManage && this.billing?.withdrawn === true) {
                return t('billing.withdrawn');
            }

            return this.canManage && this.unsettled ? t('billing.unsettled') : '';
        },

        /** Whether there is a message to show. */
        get hasNotice() {
            return this.notice !== '';
        },

        /** The rows of the usage: what the workspace holds against what its plan allows, with a bar. */
        get usageRows() {
            if (this.billing === null) {
                return [];
            }

            const { usage, limits } = this.billing;
            const count = (/** @type {number} */ value) => numberFormat({}).format(value);

            return [
                this.usageRow(
                    'inventories',
                    t('billing.usage.inventories'),
                    usage.inventories,
                    limits.inventories,
                    count,
                ),
                this.usageRow(
                    'storage',
                    t('billing.usage.storage'),
                    usage.storage_bytes,
                    limits.storage_bytes,
                    formatSize,
                ),
                this.usageRow('members', t('billing.usage.members'), usage.members, limits.members, count),
            ];
        },

        /** The rows of the limits that are not counted for the whole workspace: per inventory, and the features. */
        get limitRows() {
            if (this.billing === null) {
                return [];
            }

            const { limits } = this.billing;
            const count = (/** @type {number} */ value) => numberFormat({}).format(value);

            return [
                { key: 'items', label: t('billing.limit.items'), text: count(limits.items) },
                { key: 'share_links', label: t('billing.limit.share_links'), text: count(limits.share_links) },
                {
                    key: 'photo_entry',
                    label: t('billing.limit.photo_entry'),
                    text: t(limits.photo_entry ? 'billing.limit.included' : 'billing.limit.not_included'),
                },
            ];
        },

        /**
         * Reads the overview when the page opens, and, when Stripe has just brought the person back, waits for the payment to be confirmed.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.rootElement = this.$root;

            try {
                await this.load();
                this.selectRequestedInterval();

                // Only an owner of a workspace on an instance that sells plans has a payment to wait for.
                if (this.offered && this.canManage && this.returnedFromCheckout()) {
                    await this.confirmPayment();
                } else if (this.offered && this.canManage && this.returnedFromCancellation()) {
                    // The portal brought the person back from the cancellation: read the subscription now, it is what shows "ends on".
                    await this.pull(this.refreshUrl, 'post');
                }
            } finally {
                // Once the overview was read, whatever came of the wait (a person who is not an owner, an instance that sells no plan,
                // a failure): a reload must not start it again. Not while it could not be read: the reload is how the person tries
                // again, and it must still know the person came back from a payment.
                if (this.billing !== null) {
                    this.dropCheckoutParameter();
                }
            }
        },

        /**
         * Selects the yearly plan when the address asks for it (`?interval=year`, which the pricing page links to) and the instance has a
         * yearly Price. The monthly plan is the default, and anything else in the address is ignored.
         *
         * @returns {void}
         */
        selectRequestedInterval() {
            if (this.hasYearly && new URLSearchParams(window.location.search).get('interval') === 'year') {
                this.interval = 'year';
            }
        },

        /**
         * Reads the overview from the API.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;
            this.loadFailed = false;

            try {
                const answer = await http.get(this.url);

                this.billing = answer.data;
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (!(error instanceof HttpError)) {
                    return;
                }

                // An overview that was read before stays on screen: only a page that has nothing says it could not load.
                this.loadFailed = this.billing === null;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Sends the owner to Stripe Checkout to buy Pro for the interval chosen. The page stays busy once the address is known: the
         * browser is leaving.
         *
         * @returns {Promise<void>}
         */
        async upgrade() {
            if (this.busy || !this.canUpgrade) {
                return;
            }

            await this.leaveFor(this.checkoutUrl, { interval: this.interval });
        },

        /**
         * Sends the owner to the Stripe customer portal, where the subscription is managed (card, invoices, cancellation).
         *
         * @returns {Promise<void>}
         */
        async manage() {
            if (this.busy || !this.canManage) {
                return;
            }

            await this.leaveFor(this.portalUrl, {});
        },

        /**
         * Sends the owner to the page of the Stripe customer portal that cancels the contract (the subscription ends with the period).
         *
         * @returns {Promise<void>}
         */
        async cancel() {
            if (this.busy || !this.canCancel) {
                return;
            }

            await this.leaveFor(this.cancelUrl, {});
        },

        /**
         * Opens the declaration of a withdrawal, with the name and the address of the account.
         *
         * @returns {void}
         */
        openWithdrawal() {
            if (this.busy || this.withdrawing || !this.canWithdraw) {
                return;
            }

            this.withdrawalName = this.rootElement.dataset.personName ?? '';
            this.withdrawalEmail = this.rootElement.dataset.personEmail ?? '';
            this.withdrawalOpen = true;
        },

        /**
         * Closes the declaration without sending it.
         *
         * @returns {void}
         */
        closeWithdrawal() {
            if (!this.withdrawing) {
                this.withdrawalOpen = false;
            }
        },

        /**
         * Sends the declaration of a withdrawal. The server stores it, ends the subscription and sends the acknowledgement; it answers with the
         * overview, which no longer offers the withdrawal.
         *
         * @returns {Promise<void>}
         */
        async submitWithdrawal() {
            if (!this.canSubmitWithdrawal) {
                return;
            }

            this.withdrawing = true;
            const email = this.withdrawalEmail.trim();

            try {
                // Silent: a failure of the server has words of its own, which the central "server error" toast would hide.
                const answer = await http.post(
                    this.withdrawUrl,
                    { name: this.withdrawalName.trim(), email },
                    { silent: true },
                );

                this.billing = answer.data;
                this.withdrawalOpen = false;
                notify('success', t('billing.notice.withdrawn', { email }));
            } catch (error) {
                this.fail(error);

                if (error instanceof HttpError) {
                    leaveStalePage(error);
                }
            } finally {
                this.withdrawing = false;
            }
        },

        /**
         * Asks the API for the address of a page Stripe hosts and goes there.
         *
         * @param {string} url The endpoint.
         * @param {Record<string, unknown>} body
         * @returns {Promise<void>}
         */
        async leaveFor(url, body) {
            this.busy = true;

            try {
                // Silent: a 503 from Stripe has words of its own, which the central "server error" toast would hide.
                const answer = await http.post(url, body, { silent: true });

                redirectTo(answer.data.url);
            } catch (error) {
                this.busy = false;
                this.fail(error);

                if (error instanceof HttpError) {
                    leaveStalePage(error);
                }
            }
        },

        /**
         * Whether Stripe brought the person back from a payment (`?checkout=done`).
         *
         * @returns {boolean}
         */
        returnedFromCheckout() {
            return new URLSearchParams(window.location.search).get('checkout') === 'done';
        },

        /**
         * Whether the customer portal brought the person back from the cancellation of the contract (`?cancellation=done`).
         *
         * @returns {boolean}
         */
        returnedFromCancellation() {
            return new URLSearchParams(window.location.search).get('cancellation') === 'done';
        },

        /**
         * Waits for the payment the person came back from to be confirmed: an owner has the subscription read now, then the overview is
         * read a couple of times more, a few seconds apart, until the plan is Pro. Told to come back later when it still is not.
         *
         * @returns {Promise<void>}
         */
        async confirmPayment() {
            this.confirming = true;

            try {
                if (!this.isPro && this.canManage) {
                    await this.pull(this.refreshUrl, 'post');
                }

                for (let attempt = 0; attempt < POLL_ATTEMPTS && !this.isPro; attempt += 1) {
                    await new Promise((resolve) => setTimeout(resolve, this.pollDelayMs));
                    await this.pull(this.url, 'get');
                }
            } finally {
                this.confirming = false;
            }

            if (this.isPro) {
                notify('success', t('billing.notice.pro_active'));
            } else {
                this.stillPending = true;
            }
        },

        /**
         * Reads the overview the quiet way, for the wait: a failure is not told (the next reading, or the page, will say), except that a
         * session that ended sends the person to sign in again.
         *
         * @param {string} url
         * @param {'get' | 'post'} verb
         * @returns {Promise<void>}
         */
        async pull(url, verb) {
            try {
                const answer =
                    verb === 'post'
                        ? await http.post(url, {}, { silent: true })
                        : await http.get(url, { silent: true });

                this.billing = answer.data;
            } catch (error) {
                if (error instanceof HttpError) {
                    leaveStalePage(error);
                }
            }
        },

        /**
         * Takes `checkout` and `cancellation` off the address of the page, so that a reload does not wait for a payment or read the
         * subscription again.
         *
         * @returns {void}
         */
        dropCheckoutParameter() {
            const address = new URL(window.location.href);

            if (!address.searchParams.has('checkout') && !address.searchParams.has('cancellation')) {
                return;
            }

            address.searchParams.delete('checkout');
            address.searchParams.delete('cancellation');
            window.history.replaceState(window.history.state, '', address.pathname + address.search + address.hash);
        },

        /**
         * What a plan costs for an interval, as a sentence ("" when there is no such Price).
         *
         * @param {'month' | 'year'} interval
         * @returns {string}
         */
        priceText(interval) {
            const cents = this.billing?.prices[interval];

            if (this.billing === null || cents === null || cents === undefined) {
                return '';
            }

            return t(`billing.price.${interval}`, { amount: moneyText(cents, this.billing.prices.currency) });
        },

        /**
         * One row of the usage.
         *
         * @param {string} key
         * @param {string} label
         * @param {number} used
         * @param {number} limit
         * @param {(value: number) => string} format
         * @returns {{ key: string, label: string, text: string, percent: number, barClass: string, style: { width: string } }}
         */
        usageRow(key, label, used, limit, format) {
            const percent = percentOf(used, limit);
            let barClass = '';

            if (percent >= 100) {
                barClass = 'bg-danger';
            } else if (percent >= 80) {
                barClass = 'bg-warning';
            }

            return {
                key,
                label,
                text: t('billing.usage.of', { used: format(used), limit: format(limit) }),
                percent,
                barClass,
                style: { width: `${percent}%` },
            };
        },

        /**
         * Tells the person why a request was refused, and reads the overview again when the page was out of date.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError)) {
                return;
            }

            const known = typeof error.code === 'string' && Object.hasOwn(MESSAGE_KEYS, error.code);

            if (known && (error.status === 409 || error.status === 422)) {
                void this.load();
            }

            // The owner before still pays: the new owner is told, and offered neither button (the overview does not say it).
            if (error.status === 409 && error.code === 'previous_owner_subscription') {
                const body = /** @type {{ ends_at?: unknown } | null} */ (error.body);

                this.previousOwnerEndsAt = typeof body?.ends_at === 'string' ? body.ends_at : '';

                if (!error.reported) {
                    notify('danger', this.previousOwnerText);
                }

                return;
            }

            if (!error.reported) {
                notify(
                    'danger',
                    known ? t(MESSAGE_KEYS[/** @type {keyof typeof MESSAGE_KEYS} */ (error.code)]) : error.message,
                );
            }
        },
    };
}
