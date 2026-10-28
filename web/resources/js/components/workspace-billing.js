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
 * read; the parameter is taken off the address whatever came out of it, so that a reload does not start it again.
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
 * @property {boolean} enabled Whether the instance sells plans.
 * @property {'free' | 'pro'} plan
 * @property {string | null} status Stripe's word for the subscription (`active`, `past_due`, `canceled`...).
 * @property {'month' | 'year' | null} interval
 * @property {string | null} current_period_end ISO 8601, in UTC.
 * @property {boolean} cancel_at_period_end
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
    no_billing_account: 'billing.error.no_account',
    interval_not_offered: 'billing.error.interval',
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

        /** How often the person pays, as chosen in the form: `month` or `year`. */
        interval: 'month',

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

        /**
         * Whether the form that buys Pro is offered: an owner, a workspace that is not Pro, no subscription waiting to be settled, and no
         * payment being confirmed or still waited for (the one the person just made would be paid twice).
         */
        get canUpgrade() {
            return this.canManage && !this.isPro && !this.confirming && !this.stillPending && !this.unsettled;
        },

        /** Whether the button that buys Pro is on the page: not for a workspace that is on Pro or that has a subscription to settle. */
        get upgradeShown() {
            return !this.isPro && !this.unsettled;
        },

        /** Whether the customer portal is offered: the workspace is on Pro, or has a subscription to settle there. */
        get canOpenPortal() {
            return this.isPro || this.unsettled;
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
            return this.isPro ? 'bg-primary' : 'bg-secondary-lt';
        },

        /** What the subscription is doing, in a word ("" for a status the page has no word for). */
        get statusLabel() {
            const status = this.billing?.status ?? '';

            return STATUSES.includes(status) ? t(`billing.status.${status}`) : '';
        },

        /** The classes of the badge of the status: a payment that failed is a warning. */
        get statusClass() {
            return this.billing?.status === 'past_due' ? 'bg-warning' : 'bg-success';
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

                // Only an owner of a workspace on an instance that sells plans has a payment to wait for.
                if (this.offered && this.canManage && this.returnedFromCheckout()) {
                    await this.confirmPayment();
                }
            } finally {
                // Whatever came of it (an overview that could not be read, a person who is not an owner, an instance that sells no plan,
                // a failure): a reload must not start it again.
                this.dropCheckoutParameter();
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
         * Asks the API for the address of a page Stripe hosts and goes there.
         *
         * @param {string} url The endpoint.
         * @param {Record<string, unknown>} body
         * @returns {Promise<void>}
         */
        async leaveFor(url, body) {
            this.busy = true;

            try {
                const answer = await http.post(url, body);

                redirectTo(answer.data.url);
            } catch (error) {
                this.busy = false;
                this.fail(error);
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
         * Takes `checkout` off the address of the page, so that a reload does not wait for a payment again.
         *
         * @returns {void}
         */
        dropCheckoutParameter() {
            const address = new URL(window.location.href);

            if (!address.searchParams.has('checkout')) {
                return;
            }

            address.searchParams.delete('checkout');
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

            if (!error.reported) {
                notify(
                    'danger',
                    known ? t(MESSAGE_KEYS[/** @type {keyof typeof MESSAGE_KEYS} */ (error.code)]) : error.message,
                );
            }
        },
    };
}
