import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import workspaceBilling from '../../../resources/js/components/workspace-billing.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const URL = '/api/v1/workspaces/shop/billing';
const CHECKOUT_URL = `${URL}/checkout`;
const PORTAL_URL = `${URL}/portal`;
const REFRESH_URL = `${URL}/refresh`;
const PAGE = '/app/w/shop/billing';

/** The overview of a Free workspace, as the API describes it, with what is given over it. */
function overview(overrides = {}) {
    return {
        enabled: true,
        plan: 'free',
        status: null,
        interval: null,
        current_period_end: null,
        cancel_at_period_end: false,
        can_manage: true,
        prices: { currency: 'eur', month: 600, year: 6000 },
        limits: {
            inventories: 3,
            items: 500,
            storage_bytes: 209715200,
            members: 3,
            share_links: 2,
            photo_entry: false,
        },
        usage: { inventories: 1, storage_bytes: 0, members: 2 },
        ...overrides,
    };
}

/** The overview of a workspace on Pro. */
function pro(overrides = {}) {
    return overview({
        plan: 'pro',
        status: 'active',
        interval: 'month',
        current_period_end: '2026-12-15T12:00:00+00:00',
        limits: {
            inventories: 100,
            items: 10000,
            storage_bytes: 10737418240,
            members: 50,
            share_links: 20,
            photo_entry: true,
        },
        ...overrides,
    });
}

describe('the subscription page of a workspace', () => {
    let component;

    /** Mounts the component on a page that has the addresses of the API. */
    function mount() {
        component = workspaceBilling();
        // Alpine injects $root: the element carrying x-data and the data-* attributes.
        component.$root = {
            dataset: { url: URL, checkoutUrl: CHECKOUT_URL, portalUrl: PORTAL_URL, refreshUrl: REFRESH_URL },
        };
        component.pollDelayMs = 0;
    }

    /** Opens the page with the overview the API gives. */
    async function open(data) {
        http.get.mockResolvedValue({ data });
        await component.init();
    }

    beforeEach(() => {
        vi.resetAllMocks();
        document.documentElement.lang = 'en';
        window.history.replaceState(null, '', PAGE);
        mount();
    });

    afterEach(() => {
        window.history.replaceState(null, '', '/');
    });

    describe('the overview', () => {
        it('is read when the page opens, and nothing is shown before', async () => {
            expect(component.loading).toBe(true);
            expect(component.offered).toBe(false);
            expect(component.notOffered).toBe(false);

            await open(overview());

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.loading).toBe(false);
            expect(component.loadFailed).toBe(false);
            expect(component.offered).toBe(true);
        });

        it('says it could not be read, and reads it again on demand', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server error'));

            await component.init();

            expect(component.loadFailed).toBe(true);
            expect(component.loading).toBe(false);
            expect(component.offered).toBe(false);

            http.get.mockResolvedValue({ data: overview() });
            await component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.offered).toBe(true);
        });

        it('keeps what it showed when it cannot be read again', async () => {
            await open(overview());
            http.get.mockRejectedValue(new HttpError(500, 'Server error'));

            await component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.offered).toBe(true);
        });

        it('says that no plan is offered on an instance without Stripe', async () => {
            await open(overview({ enabled: false }));

            expect(component.notOffered).toBe(true);
            expect(component.offered).toBe(false);
        });
    });

    describe('a Free workspace', () => {
        it('shows its plan, and lets an owner buy Pro', async () => {
            await open(overview());

            expect(component.isPro).toBe(false);
            expect(component.planLabel).toBe('Free');
            expect(component.planClass).toBe('bg-secondary-lt');
            expect(component.canManage).toBe(true);
            expect(component.canUpgrade).toBe(true);
            expect(component.interval).toBe('month');
        });

        it('lets the other members read the plan only', async () => {
            await open(overview({ can_manage: false }));

            expect(component.canManage).toBe(false);
            expect(component.canUpgrade).toBe(false);
        });

        it('says what each interval costs, in the language of the page', async () => {
            await open(overview());

            expect(component.monthPrice).toBe('€6 per month');
            expect(component.yearPrice).toBe('€60 per year');
            expect(component.hasYearly).toBe(true);
        });

        it('shows the cents of a price that has some', async () => {
            await open(overview({ prices: { currency: 'eur', month: 550, year: null } }));

            expect(component.monthPrice).toBe('€5.50 per month');
        });

        it('offers no yearly plan when the instance has no yearly Price', async () => {
            await open(overview({ prices: { currency: 'eur', month: 600, year: null } }));

            expect(component.hasYearly).toBe(false);
            expect(component.yearPrice).toBe('');
        });

        it('shows the figure and the code of a currency the browser does not know', async () => {
            await open(overview({ prices: { currency: 'zzzz', month: 600, year: null } }));

            expect(component.monthPrice).toBe('6 ZZZZ per month');
        });

        it('says when a Pro subscription ended', async () => {
            await open(
                overview({ status: 'canceled', interval: 'month', current_period_end: '2026-11-03T12:00:00+00:00' }),
            );

            expect(component.endedText).toBe('The Pro subscription ended on November 3, 2026.');
        });

        it('says nothing of a subscription that never was', async () => {
            await open(overview());

            expect(component.endedText).toBe('');
            expect(component.periodText).toBe('');
            expect(component.statusLabel).toBe('');
        });
    });

    describe('a workspace whose subscription is waiting to be settled', () => {
        it.each(['incomplete', 'unpaid', 'paused'])(
            'offers the portal, not the purchase, for a subscription that is %s',
            async (status) => {
                await open(overview({ status }));

                expect(component.isPro).toBe(false);
                expect(component.unsettled).toBe(true);
                expect(component.canUpgrade).toBe(false);
                expect(component.upgradeShown).toBe(false);
                expect(component.canOpenPortal).toBe(true);
                expect(component.notice).toBe(
                    'A subscription of this workspace is waiting to be settled. Open the subscription management to settle it.',
                );

                http.post.mockResolvedValue({ data: { url: 'https://billing.stripe.com/session/bps_1' } });
                await component.upgrade();

                expect(http.post).not.toHaveBeenCalled();
            },
        );

        it('keeps the purchase for a Free workspace whose subscription is over, and says nothing', async () => {
            await open(overview({ status: 'canceled' }));

            expect(component.unsettled).toBe(false);
            expect(component.canUpgrade).toBe(true);
            expect(component.upgradeShown).toBe(true);
            expect(component.canOpenPortal).toBe(false);
            expect(component.hasNotice).toBe(false);
        });

        it('tells the owner only: a member has nothing to settle', async () => {
            await open(overview({ status: 'unpaid', can_manage: false }));

            expect(component.hasNotice).toBe(false);
        });
    });

    describe('a workspace on Pro', () => {
        it('tells the plan, the status, how often it is paid and when it renews', async () => {
            await open(pro());

            expect(component.isPro).toBe(true);
            expect(component.planLabel).toBe('Pro');
            expect(component.planClass).toBe('bg-primary');
            expect(component.statusLabel).toBe('Active');
            expect(component.statusClass).toBe('bg-success');
            expect(component.intervalLabel).toBe('Monthly');
            expect(component.periodText).toBe('Renews on December 15, 2026.');
            expect(component.pastDue).toBe(false);
            expect(component.canUpgrade).toBe(false);
            expect(component.endedText).toBe('');
        });

        it('tells that the subscription ends when the owner canceled it, and that Pro lasts until then', async () => {
            await open(pro({ interval: 'year', cancel_at_period_end: true }));

            expect(component.intervalLabel).toBe('Yearly');
            expect(component.periodText).toBe(
                'The subscription ends on December 15, 2026. Pro stays available until then.',
            );
        });

        it('warns about a payment that failed, while the workspace keeps Pro', async () => {
            await open(pro({ status: 'past_due' }));

            expect(component.isPro).toBe(true);
            expect(component.pastDue).toBe(true);
            expect(component.statusLabel).toBe('Payment overdue');
            expect(component.statusClass).toBe('bg-warning');
        });

        it('has a word for a trial', async () => {
            await open(pro({ status: 'trialing' }));

            expect(component.statusLabel).toBe('Trial period');
        });

        it('shows no date when the API gives none', async () => {
            await open(pro({ current_period_end: null }));

            expect(component.periodText).toBe('');
        });
    });

    describe('the usage and the limits', () => {
        it('shows what the workspace uses against what its plan allows', async () => {
            await open(
                overview({
                    usage: { inventories: 1, storage_bytes: 104857600, members: 3 },
                    limits: {
                        inventories: 3,
                        items: 1500,
                        storage_bytes: 209715200,
                        members: 3,
                        share_links: 2,
                        photo_entry: false,
                    },
                }),
            );
            const [inventories, storage, members] = component.usageRows;

            expect(inventories).toMatchObject({
                key: 'inventories',
                label: 'Inventories',
                text: '1 of 3',
                percent: 33,
                barClass: '',
                style: { width: '33%' },
            });
            expect(storage).toMatchObject({ key: 'storage', label: 'Storage', percent: 50, barClass: '' });
            expect(storage.text).toMatch(/^100 MB of 200 MB$/);
            expect(members).toMatchObject({
                key: 'members',
                label: 'Members and invitations',
                text: '3 of 3',
                percent: 100,
                barClass: 'bg-danger',
            });
        });

        it('warns when a limit is close, and when it is reached', async () => {
            await open(
                overview({
                    usage: { inventories: 4, storage_bytes: 0, members: 0 },
                    limits: { ...overview().limits, inventories: 5 },
                }),
            );

            expect(component.usageRows[0]).toMatchObject({ percent: 80, barClass: 'bg-warning' });
        });

        it('never fills a bar past its end, and counts a limit of zero as reached once anything is used', async () => {
            await open(
                overview({
                    usage: { inventories: 7, storage_bytes: 1, members: 0 },
                    limits: { ...overview().limits, inventories: 5, storage_bytes: 0 },
                }),
            );

            expect(component.usageRows[0].percent).toBe(100);
            expect(component.usageRows[1].percent).toBe(100);
            expect(component.usageRows[2].percent).toBe(0);
        });

        it('lists the limits that are not counted for the whole workspace, and the features', async () => {
            await open(
                overview({ limits: { ...overview().limits, items: 10000, share_links: 20, photo_entry: true } }),
            );

            expect(component.limitRows).toEqual([
                { key: 'items', label: 'Items per inventory', text: '10,000' },
                { key: 'share_links', label: 'Share links per inventory', text: '20' },
                { key: 'photo_entry', label: 'Photo entry (AI)', text: 'Included' },
            ]);
        });

        it('says that the photo entry is not part of the plan', async () => {
            await open(overview());

            expect(component.limitRows[2].text).toBe('Not included');
        });

        it('has no rows before the overview is read', () => {
            expect(component.usageRows).toEqual([]);
            expect(component.limitRows).toEqual([]);
        });
    });

    describe('buying Pro', () => {
        it('asks for a Checkout session for the interval chosen and goes to the page of Stripe', async () => {
            await open(overview());
            http.post.mockResolvedValue({ data: { url: 'https://checkout.stripe.com/c/pay/cs_1' } });

            await component.upgrade();

            expect(http.post).toHaveBeenCalledWith(CHECKOUT_URL, { interval: 'month' });
            expect(redirectTo).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_1');
            // The browser is leaving: the buttons stay off.
            expect(component.busy).toBe(true);
        });

        it('buys the yearly plan when it is chosen', async () => {
            await open(overview());
            http.post.mockResolvedValue({ data: { url: 'https://checkout.stripe.com/c/pay/cs_2' } });
            component.interval = 'year';

            await component.upgrade();

            expect(http.post).toHaveBeenCalledWith(CHECKOUT_URL, { interval: 'year' });
        });

        it('does nothing twice, nor for somebody who may not buy, nor for a workspace that is on Pro', async () => {
            http.post.mockResolvedValue({ data: { url: 'https://checkout.stripe.com/c/pay/cs_1' } });

            await open(overview({ can_manage: false }));
            await component.upgrade();

            await open(pro());
            await component.upgrade();

            await open(overview());
            await Promise.all([component.upgrade(), component.upgrade()]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('stays on the page, and tells why, when the workspace is on Pro already', async () => {
            await open(overview());
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'already_subscribed' }));
            http.get.mockResolvedValue({ data: pro() });

            await component.upgrade();
            await vi.waitFor(() => expect(component.isPro).toBe(true));

            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
            expect(notify).toHaveBeenCalledWith('danger', 'This workspace already has a subscription.');
        });

        it('tells, and reads the overview again, when a subscription of the workspace is waiting to be settled', async () => {
            await open(overview());
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'subscription_pending' }));
            http.get.mockResolvedValue({ data: overview({ status: 'unpaid' }) });

            await component.upgrade();
            await vi.waitFor(() => expect(component.unsettled).toBe(true));

            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
            expect(notify).toHaveBeenCalledWith(
                'danger',
                'This workspace has a subscription that is not settled yet. Wait for its payment to be confirmed, or settle it from the subscription management.',
            );
        });

        it.each([
            ['billing_disabled', 409, 'This service is not offered on this instance.'],
            ['interval_not_offered', 422, 'This billing period is not offered.'],
        ])('tells %s in words of its own', async (code, status, message) => {
            await open(overview());
            http.post.mockRejectedValue(new HttpError(status, 'Server words', { code }));

            await component.upgrade();

            expect(notify).toHaveBeenCalledWith('danger', message);
            expect(component.busy).toBe(false);
        });

        it('does not repeat a failure the HTTP helper already reported', async () => {
            await open(overview());
            const error = new HttpError(503, 'The server hit an error.', { code: 'billing_unavailable' });
            error.reported = true;
            http.post.mockRejectedValue(error);

            await component.upgrade();

            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
            expect(component.canUpgrade).toBe(true);
        });

        it('says what the HTTP helper says for a failure it did not report', async () => {
            await open(overview());
            http.post.mockRejectedValue(new HttpError(403, 'You are not allowed to do this.'));

            await component.upgrade();

            expect(notify).toHaveBeenCalledWith('danger', 'You are not allowed to do this.');
        });

        it('says nothing of a request that was cancelled', async () => {
            await open(overview());
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.upgrade();

            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });
    });

    describe('managing the subscription', () => {
        it('asks for a portal session and goes to the page of Stripe', async () => {
            await open(pro());
            http.post.mockResolvedValue({ data: { url: 'https://billing.stripe.com/session/bps_1' } });

            await component.manage();

            expect(http.post).toHaveBeenCalledWith(PORTAL_URL, {});
            expect(redirectTo).toHaveBeenCalledWith('https://billing.stripe.com/session/bps_1');
            expect(component.busy).toBe(true);
        });

        it('is for the owner only', async () => {
            await open(pro({ can_manage: false }));

            await component.manage();

            expect(http.post).not.toHaveBeenCalled();
        });

        it('tells that there is nothing to manage yet, and reads the overview again', async () => {
            await open(overview());
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'no_billing_account' }));

            await component.manage();

            expect(notify).toHaveBeenCalledWith('danger', 'This workspace has no subscription to manage yet.');
            expect(http.get).toHaveBeenCalledTimes(2);
            expect(component.busy).toBe(false);
        });
    });

    describe('a workspace whose previous owner still pays', () => {
        /** The API refusing the new owner, as it does when the owner before has a subscription in play. */
        function refusal(endsAt = '2026-12-15T12:00:00+00:00') {
            return new HttpError(409, 'Conflict', {
                code: 'previous_owner_subscription',
                body: { code: 'previous_owner_subscription', message: 'a message of the server', ends_at: endsAt },
            });
        }

        it('tells the owner when the subscription ends, and offers neither button, once Checkout is refused', async () => {
            await open(overview());
            http.post.mockRejectedValue(refusal());

            expect(component.upgradeShown).toBe(true);

            await component.upgrade();

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'The subscription of the previous owner of this workspace runs until December 15, 2026. You can subscribe once it has ended.',
            );
            expect(component.blockedByPreviousOwner).toBe(true);
            expect(component.canUpgrade).toBe(false);
            expect(component.upgradeShown).toBe(false);
            expect(component.canOpenPortal).toBe(false);
            expect(component.actionsShown).toBe(false);
            expect(component.hasNotice).toBe(true);
            expect(component.notice).toBe(
                'The subscription of the previous owner of this workspace runs until December 15, 2026. You can subscribe once it has ended.',
            );
            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('says it without a date when the subscription is not set to end', async () => {
            await open(overview());
            http.post.mockRejectedValue(refusal(null));

            await component.upgrade();

            const expected =
                'The subscription of the previous owner of this workspace is still running. You can subscribe once it has ended.';
            expect(notify).toHaveBeenCalledWith('danger', expected);
            expect(component.notice).toBe(expected);
            expect(component.previousOwnerEndsAt).toBe('');
        });

        it('takes the portal of a Pro workspace away too, once it is refused there', async () => {
            await open(pro({ cancel_at_period_end: true }));
            http.post.mockRejectedValue(refusal());

            expect(component.canOpenPortal).toBe(true);
            expect(component.actionsShown).toBe(true);

            await component.manage();

            expect(component.canOpenPortal).toBe(false);
            expect(component.upgradeShown).toBe(false);
            expect(component.actionsShown).toBe(false);
            expect(notify).toHaveBeenCalledTimes(1);
        });

        it('keeps what it knows when the overview is read again', async () => {
            await open(overview());
            http.post.mockRejectedValue(refusal());

            await component.upgrade();
            await component.load();

            expect(component.blockedByPreviousOwner).toBe(true);
            expect(component.canUpgrade).toBe(false);
        });

        it('is not there until the API says so', async () => {
            await open(overview());

            expect(component.blockedByPreviousOwner).toBe(false);
            expect(component.previousOwnerText).toBe('');
            expect(component.canUpgrade).toBe(true);
            expect(component.actionsShown).toBe(true);
        });

        it('does not repeat a message the helper already showed', async () => {
            await open(overview());
            const error = refusal();
            error.reported = true;
            http.post.mockRejectedValue(error);

            await component.upgrade();

            expect(notify).not.toHaveBeenCalled();
            expect(component.blockedByPreviousOwner).toBe(true);
        });
    });

    describe('another checkout in progress', () => {
        it('tells the owner to try again in a moment, and leaves the buttons', async () => {
            await open(overview());
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'checkout_in_progress' }));

            await component.upgrade();

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'A payment page is already being opened for this workspace. Try again in a moment.',
            );
            expect(component.busy).toBe(false);
            expect(component.canUpgrade).toBe(true);
            expect(redirectTo).not.toHaveBeenCalled();
        });
    });

    describe('coming back from Stripe', () => {
        /** Puts the address the way Stripe brings the person back. */
        function returnFromCheckout(suffix = '') {
            window.history.replaceState(null, '', `${PAGE}?checkout=done${suffix}`);
        }

        it('does nothing special when the address does not say so', async () => {
            await open(overview());

            expect(http.post).not.toHaveBeenCalled();
            expect(component.confirming).toBe(false);
            expect(component.hasNotice).toBe(false);
        });

        it('has the subscription read, and is done when the plan is Pro', async () => {
            returnFromCheckout();
            const states = [];
            http.get.mockResolvedValue({ data: overview() });
            http.post.mockImplementation(async () => {
                states.push([component.confirming, component.notice, component.canUpgrade]);

                return { data: pro() };
            });

            await component.init();

            expect(http.post).toHaveBeenCalledWith(REFRESH_URL, {}, { silent: true });
            expect(http.get).toHaveBeenCalledTimes(1);
            // While it waits, the page says so and offers nothing to buy.
            expect(states).toEqual([[true, 'Payment being confirmed…', false]]);
            expect(component.confirming).toBe(false);
            expect(component.isPro).toBe(true);
            expect(component.stillPending).toBe(false);
            expect(notify).toHaveBeenCalledWith('success', 'The Pro plan is active.');
            expect(window.location.search).toBe('');
        });

        it('reads the overview again, up to twice, until the plan is Pro', async () => {
            returnFromCheckout();
            http.get
                .mockResolvedValueOnce({ data: overview() })
                .mockResolvedValueOnce({ data: overview() })
                .mockResolvedValueOnce({ data: pro() });
            http.post.mockResolvedValue({ data: overview() });

            await component.init();

            expect(http.get).toHaveBeenCalledTimes(3);
            expect(http.get).toHaveBeenLastCalledWith(URL, { silent: true });
            expect(component.isPro).toBe(true);
            expect(component.stillPending).toBe(false);
            expect(window.location.search).toBe('');
        });

        it('waits between two readings', async () => {
            vi.useFakeTimers();

            try {
                returnFromCheckout();
                component.pollDelayMs = 3000;
                http.get.mockResolvedValue({ data: overview() });
                http.post.mockResolvedValue({ data: overview() });

                const done = component.init();

                await vi.advanceTimersByTimeAsync(0);
                expect(http.get).toHaveBeenCalledTimes(1);

                await vi.advanceTimersByTimeAsync(2999);
                expect(http.get).toHaveBeenCalledTimes(1);

                await vi.advanceTimersByTimeAsync(1);
                expect(http.get).toHaveBeenCalledTimes(2);

                await vi.advanceTimersByTimeAsync(3000);
                await done;
                expect(http.get).toHaveBeenCalledTimes(3);
            } finally {
                vi.useRealTimers();
            }
        });

        it('tells the person to come back when the plan has not changed after two readings', async () => {
            returnFromCheckout();
            http.get.mockResolvedValue({ data: overview() });
            http.post.mockResolvedValue({ data: overview() });

            await component.init();

            expect(http.get).toHaveBeenCalledTimes(3);
            expect(component.confirming).toBe(false);
            expect(component.stillPending).toBe(true);
            expect(component.notice).toBe(
                'Your payment is still being confirmed. The plan changes as soon as it is: reload this page in a moment.',
            );
            expect(component.hasNotice).toBe(true);
            // The payment the person just made is not paid again: nothing to buy while it is waited for.
            expect(component.canUpgrade).toBe(false);
            expect(notify).not.toHaveBeenCalled();
            expect(window.location.search).toBe('');
        });

        it('goes on with the readings when the subscription could not be read at Stripe', async () => {
            returnFromCheckout();
            http.get.mockResolvedValueOnce({ data: overview() }).mockResolvedValueOnce({ data: pro() });
            http.post.mockRejectedValue(new HttpError(503, 'Unavailable', { code: 'billing_unavailable' }));

            await component.init();

            expect(http.get).toHaveBeenCalledTimes(2);
            expect(component.isPro).toBe(true);
            expect(notify).not.toHaveBeenCalledWith('danger', expect.anything());
        });

        it('waits for nothing for a member who is not an owner (there is no payment of theirs to wait for), and takes the parameter off', async () => {
            returnFromCheckout();
            http.get.mockResolvedValue({ data: overview({ can_manage: false }) });

            await component.init();

            expect(http.post).not.toHaveBeenCalled();
            expect(http.get).toHaveBeenCalledTimes(1);
            expect(component.confirming).toBe(false);
            expect(component.stillPending).toBe(false);
            expect(component.hasNotice).toBe(false);
            expect(notify).not.toHaveBeenCalled();
            expect(window.location.search).toBe('');
        });

        it('waits for nothing on an instance that sells no plan, and takes the parameter off', async () => {
            returnFromCheckout();
            http.get.mockResolvedValue({ data: overview({ enabled: false }) });

            await component.init();

            expect(http.post).not.toHaveBeenCalled();
            expect(http.get).toHaveBeenCalledTimes(1);
            expect(component.stillPending).toBe(false);
            expect(window.location.search).toBe('');
        });

        it('takes the parameter off even when the reading goes wrong half-way', async () => {
            returnFromCheckout();
            http.get.mockResolvedValue({ data: overview() });
            http.post.mockResolvedValue({ data: pro() });
            notify.mockImplementation(() => {
                throw new Error('Unexpected');
            });

            await expect(component.init()).rejects.toThrow('Unexpected');

            expect(window.location.search).toBe('');
        });

        it('has nothing to wait for when the plan is Pro already, and takes the parameter off all the same', async () => {
            returnFromCheckout();
            http.get.mockResolvedValue({ data: pro() });

            await component.init();

            expect(http.post).not.toHaveBeenCalled();
            expect(http.get).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('success', 'The Pro plan is active.');
            expect(window.location.search).toBe('');
        });

        it('keeps the rest of the address when it takes the parameter off', async () => {
            returnFromCheckout('&from=mail#plan');
            http.get.mockResolvedValue({ data: pro() });

            await component.init();

            expect(window.location.pathname + window.location.search + window.location.hash).toBe(
                `${PAGE}?from=mail#plan`,
            );
        });

        it('waits for nothing when the overview could not be read, and keeps the parameter for the reload that tries again', async () => {
            returnFromCheckout();
            http.get.mockRejectedValue(new HttpError(500, 'Server error'));

            await component.init();

            expect(http.post).not.toHaveBeenCalled();
            expect(component.loadFailed).toBe(true);
            expect(component.billing).toBeNull();
            // Still there: the person is brought back from a payment, and the page they reload must know it.
            expect(window.location.search).toBe('?checkout=done');

            // The reload: the overview is read this time, the payment is waited for, and the parameter goes.
            mount();
            http.get.mockResolvedValue({ data: overview() });
            http.post.mockResolvedValue({ data: pro() });

            await component.init();

            expect(http.post).toHaveBeenCalledWith(REFRESH_URL, {}, { silent: true });
            expect(component.isPro).toBe(true);
            expect(window.location.search).toBe('');
        });

        it('takes the parameter off as soon as the overview was read, whatever came of the wait', async () => {
            returnFromCheckout();
            http.get.mockResolvedValueOnce({ data: overview() }).mockRejectedValue(new HttpError(500, 'Server error'));
            http.post.mockRejectedValue(new HttpError(500, 'Server error'));

            await component.init();

            expect(component.billing).not.toBeNull();
            expect(window.location.search).toBe('');
        });

        it('takes the parameter off for somebody who waits for nothing: not an owner, or no plan sold', async () => {
            returnFromCheckout();

            await open(overview({ can_manage: false }));

            expect(http.post).not.toHaveBeenCalled();
            expect(window.location.search).toBe('');
        });

        it('ignores any other value of the parameter', async () => {
            window.history.replaceState(null, '', `${PAGE}?checkout=cancelled`);
            http.get.mockResolvedValue({ data: overview() });

            await component.init();

            expect(http.post).not.toHaveBeenCalled();
            expect(component.confirming).toBe(false);
        });
    });
});
