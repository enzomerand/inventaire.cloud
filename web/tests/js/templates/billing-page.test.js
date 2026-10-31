/**
 * The subscription page of a workspace under the real Alpine CSP build.
 *
 * `alpine-csp.test.js` scans the templates for syntax the CSP build refuses; this file goes further and runs this one: the Blade
 * source of the page is turned into plain HTML (the few constructs it uses are supported, anything else fails loudly so this helper
 * is extended on purpose), mounted with the real component, and driven the way a person would. Only the network (`http`), the toasts
 * and the navigation are replaced.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';
import { MESSAGES } from '../support/messages.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn() }, scheduleNavigation: vi.fn() };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));

const VIEWS = join(import.meta.dirname, '../../../resources/views');

/** Where the routes the template mentions lead; `{workspace}` stands for the ULID the template gives. */
const ROUTES = {
    'app.workspace': '/app/w/{workspace}',
    'api.v1.workspaces.billing.show': '/api/v1/workspaces/{workspace}/billing',
    'api.v1.workspaces.billing.checkout': '/api/v1/workspaces/{workspace}/billing/checkout',
    'api.v1.workspaces.billing.portal': '/api/v1/workspaces/{workspace}/billing/portal',
    'api.v1.workspaces.billing.refresh': '/api/v1/workspaces/{workspace}/billing/refresh',
    'api.v1.workspaces.billing.cancel': '/api/v1/workspaces/{workspace}/billing/cancel',
    'api.v1.workspaces.billing.withdraw': '/api/v1/workspaces/{workspace}/billing/withdraw',
};

/** Escapes text for an HTML attribute, like Blade's `{{ }}`. */
function escape(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;');
}

/** Fails the rendering with a message that says what to teach the helper. */
function fail(message) {
    throw new Error(`billing-page.test.js cannot render the template: ${message}`);
}

/** The path of a route, with the parameters the template gives it. */
function route(name, parameters) {
    let path = ROUTES[name] ?? fail(`unknown route ${name}`);

    for (const [, key, value] of (parameters ?? '').matchAll(/'(\w+)'\s*=>\s*('[^']*'|\$[\w>-]+)/g)) {
        path = path.replace(`{${key}}`, value.startsWith("'") ? value.slice(1, -1) : 'shop');
    }

    return path.includes('{') ? fail(`route ${name} lacks a parameter: ${path}`) : path;
}

/** Turns the Blade source of the page into plain HTML. Only the constructs this template uses are supported. */
function render() {
    let html = readFileSync(join(VIEWS, 'app/workspace-billing.blade.php'), 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@extends\([^)]*\)/g, '')
        .replace(/@section\('(?:title|heading)'[^\n]*\)\n/g, '');

    // The content section is the page; the layout around it is not under test.
    html = html.replace(/@section\('content'\)([\s\S]*)@endsection/, '$1');

    // Icons.
    html = html.replace(/<x-tabler-[\w-]+([^>]*?)\s*\/>/g, '<svg$1></svg>');

    // Echoes.
    html = html.replace(/\{\{\s*([\s\S]+?)\s*\}\}/g, (_match, expression) => {
        let found;

        if ((found = expression.match(/^__\('((?:[^'\\]|\\.)*)'(?:,\s*\[([^\]]*)\])?\)$/))) {
            return escape(found[1].replace(':name', 'Shop'));
        }

        if ((found = expression.match(/^route\('([\w.-]+)'(?:,\s*\[([^\]]*)\])?(?:,\s*absolute:\s*false)?\)$/))) {
            return route(found[1], found[2]);
        }

        const values = {
            '$workspace->name': 'Shop',
            '$workspace->ulid': 'shop',
            "$person['name']": 'Ada Lovelace',
            "$person['email']": 'ada@example.test',
        };

        return values[expression] ?? fail(`unsupported echo {{ ${expression} }}`);
    });

    expect(html, 'unsupported Blade syntax left in the template').not.toMatch(/@\w+\(|\{\{|\{!!|@end\w+|<x-/);

    return html;
}

/** The overview of a Free workspace, as the API describes it. */
function overview(overrides = {}) {
    return {
        enabled: true,
        selling: true,
        plan: 'free',
        status: null,
        interval: null,
        current_period_end: null,
        cancel_at_period_end: false,
        started_at: null,
        withdrawal_until: null,
        withdrawn: false,
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
        usage: { inventories: 1, storage_bytes: 104857600, members: 3 },
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

describe('the subscription page under the real Alpine CSP build', () => {
    let Alpine;

    beforeAll(async () => {
        ({ default: Alpine } = await import('@alpinejs/csp'));
        Alpine.data(
            'workspaceBilling',
            (await import('../../../resources/js/components/workspace-billing.js')).default,
        );

        window.Alpine = Alpine;
        Alpine.start();
    });

    afterAll(() => {
        Alpine.stopObservingMutations();
    });

    /** Waits for the next animation frame: Alpine applies what a click or an input changed within one or two of them. */
    const frame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()));

    /** Waits for Alpine to flush its DOM updates and for pending promises. */
    async function flush() {
        await Alpine.nextTick();
        await frame();
        await frame();
    }

    /** Mounts the page the way a person gets it, with the overview the API gives, and waits for Alpine to start it. */
    async function open(data) {
        document.documentElement.lang = 'en';
        document.body.innerHTML = `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>${render()}`;
        http.get.mockResolvedValue({ data });
        await flush();
    }

    /** Whether nothing between an element and the page hides it (`x-show` sets a style, `:hidden` the attribute). */
    function visible(element) {
        for (let node = element; node && node !== document.body; node = node.parentElement) {
            if (node.style.display === 'none' || node.hidden) {
                return false;
            }
        }

        return true;
    }

    /** The visible buttons whose text is the given one. */
    const buttons = (label) =>
        [...document.querySelectorAll('button')].filter(
            (button) => visible(button) && button.textContent.trim() === label,
        );

    /** Whether the page shows a text somewhere a person sees it. */
    const shows = (text) =>
        [...document.querySelectorAll('p, span, div, h2, legend, label')].some(
            (element) => visible(element) && element.children.length === 0 && element.textContent.includes(text),
        );

    /** The visible text of the card of the plan. */
    const planCard = () => document.querySelector('.row.row-cards .card');

    beforeEach(() => {
        vi.resetAllMocks();
        window.history.replaceState(null, '', '/app/w/shop/billing');
    });

    it('reads the overview from the address of its markup', async () => {
        await open(overview());

        expect(http.get).toHaveBeenCalledWith('/api/v1/workspaces/shop/billing');
    });

    it('shows nothing but the spinner while the overview is read', async () => {
        http.get.mockReturnValue(new Promise(() => {}));
        document.body.innerHTML = `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>${render()}`;
        await flush();

        expect(visible(document.querySelector('.spinner-border').parentElement)).toBe(true);
        expect(visible(document.querySelector('.row.row-cards'))).toBe(false);
        expect(visible(document.querySelector('.alert-danger'))).toBe(false);
    });

    it('offers to try again when the overview could not be read', async () => {
        http.get.mockRejectedValue(new HttpError(500, 'Server error'));
        document.body.innerHTML = `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>${render()}`;
        await flush();

        expect(visible(document.querySelector('.alert-danger'))).toBe(true);
        expect(shows('The subscription could not be loaded.')).toBe(true);

        http.get.mockResolvedValue({ data: overview() });
        buttons('Try again')[0].click();
        await flush();

        expect(visible(document.querySelector('.alert-danger'))).toBe(false);
        expect(visible(document.querySelector('.row.row-cards'))).toBe(true);
    });

    it('says that no plan is offered, and shows nothing else, on an instance without Stripe', async () => {
        await open(overview({ enabled: false }));

        expect(shows('This service is not offered on this instance.')).toBe(true);
        expect(visible(document.querySelector('.row.row-cards'))).toBe(false);
    });

    describe('for the owner of a Free workspace', () => {
        beforeEach(async () => {
            await open(overview());
        });

        it('shows the plan, the choice of how often to pay with what each costs, and the way to upgrade', () => {
            const card = planCard();
            const radios = [...card.querySelectorAll('input[type="radio"]')];

            expect(card.querySelector('.badge').textContent).toBe('Free');
            expect(radios.map((radio) => [radio.value, radio.checked, visible(radio)])).toEqual([
                ['month', true, true],
                ['year', false, true],
            ]);
            expect(card.textContent).toContain('€6 incl. VAT per month');
            expect(card.textContent).toContain('€60 incl. VAT per year');
            expect(buttons('Upgrade to Pro')).toHaveLength(1);
            expect(buttons('Manage the subscription')).toHaveLength(0);
            expect(shows('Only the owner manages the subscription.')).toBe(false);
        });

        it('sends the browser to Checkout for the interval that is chosen', async () => {
            http.post.mockResolvedValue({ data: { url: 'https://checkout.stripe.com/c/pay/cs_1' } });
            const year = planCard().querySelector('input[value="year"]');

            year.checked = true;
            year.dispatchEvent(new Event('change', { bubbles: true }));
            await flush();
            buttons('Upgrade to Pro')[0].click();
            await flush();

            expect(http.post).toHaveBeenCalledWith(
                '/api/v1/workspaces/shop/billing/checkout',
                { interval: 'year' },
                { silent: true },
            );
            expect(redirectTo).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_1');
            expect(buttons('Upgrade to Pro')[0].disabled).toBe(true);
        });

        it('tells what the API refused', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'already_subscribed' }));
            http.get.mockResolvedValue({ data: pro() });
            buttons('Upgrade to Pro')[0].click();
            await flush();

            expect(notify).toHaveBeenCalledWith('danger', 'This workspace already has a subscription.');
            // The page learnt that the workspace is on Pro.
            expect(planCard().querySelector('.badge').textContent).toBe('Pro');
            expect(buttons('Upgrade to Pro')).toHaveLength(0);
            expect(buttons('Manage the subscription')).toHaveLength(1);
        });

        it('draws the usage with bars that say how full they are', () => {
            const bars = [...document.querySelectorAll('.progress-bar')];

            expect(bars.map((bar) => bar.style.width)).toEqual(['33%', '50%', '100%']);
            expect(bars.map((bar) => bar.getAttribute('aria-valuenow'))).toEqual(['33', '50', '100']);
            expect(bars.map((bar) => bar.getAttribute('aria-label'))).toEqual([
                'Inventories',
                'Storage',
                'Members and invitations',
            ]);
            expect(bars[2].classList.contains('bg-danger')).toBe(true);
            expect(document.body.textContent).toContain('1 of 3');
            expect(document.body.textContent).toContain('100 MB of 200 MB');
        });

        it('lists the limits that are not counted for the whole workspace', () => {
            const items = [...document.querySelectorAll('.list-group-item')].map((item) =>
                item.textContent.replace(/\s+/g, ' ').trim(),
            );

            expect(items).toEqual([
                'Items per inventory 500',
                'Share links per inventory 2',
                'Photo entry (AI) Not included',
            ]);
        });
    });

    it('offers no yearly plan when the instance has none', async () => {
        await open(overview({ prices: { currency: 'eur', month: 600, year: null } }));

        const radios = [...planCard().querySelectorAll('input[type="radio"]')];

        expect(radios.filter(visible).map((radio) => radio.value)).toEqual(['month']);
    });

    it('lets a member read the plan and nothing else', async () => {
        await open(overview({ can_manage: false }));

        expect(shows('Only the owner manages the subscription.')).toBe(true);
        expect(buttons('Upgrade to Pro')).toHaveLength(0);
        expect(buttons('Manage the subscription')).toHaveLength(0);
        expect([...planCard().querySelectorAll('input[type="radio"]')].filter(visible)).toHaveLength(0);
        // The usage is there for them.
        expect(document.querySelectorAll('.progress-bar')).toHaveLength(3);
    });

    describe('for the owner of a workspace on Pro', () => {
        beforeEach(async () => {
            await open(pro());
        });

        it('shows the plan, the status, how often it is paid and when it renews', () => {
            const card = planCard();

            expect(card.querySelector('.badge').textContent).toBe('Pro');
            expect(card.textContent).toContain('Active');
            expect(card.textContent).toContain('Monthly');
            expect(card.textContent).toContain('Renews on December 15, 2026.');
            expect([...card.querySelectorAll('input[type="radio"]')].filter(visible)).toHaveLength(0);
            expect(buttons('Upgrade to Pro')).toHaveLength(0);
            expect(
                card.querySelector('.alert-warning') === null || !visible(card.querySelector('.alert-warning')),
            ).toBe(true);
        });

        it('sends the browser to the portal of Stripe to manage the subscription', async () => {
            http.post.mockResolvedValue({ data: { url: 'https://billing.stripe.com/session/bps_1' } });

            buttons('Manage the subscription')[0].click();
            await flush();

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/billing/portal', {}, { silent: true });
            expect(redirectTo).toHaveBeenCalledWith('https://billing.stripe.com/session/bps_1');
        });

        it('shows the limits of Pro', () => {
            expect(document.body.textContent).toContain('1 of 100');
            expect(document.body.textContent).toContain('10,000');
            expect(document.body.textContent).toContain('Included');
        });
    });

    it('warns about a payment that failed', async () => {
        await open(pro({ status: 'past_due' }));

        expect(shows('The last payment failed. Update your payment method to keep Pro.')).toBe(true);
        expect(planCard().textContent).toContain('Payment overdue');
        expect(buttons('Manage the subscription')).toHaveLength(1);
    });

    it('says when the subscription ends', async () => {
        await open(pro({ cancel_at_period_end: true }));

        expect(planCard().textContent).toContain(
            'The subscription ends on December 15, 2026. Pro stays available until then.',
        );
    });

    describe('an instance that has Stripe but cannot sell yet', () => {
        it('says "Coming soon" in place of the button that buys Pro', async () => {
            await open(overview({ selling: false }));

            expect(buttons('Upgrade to Pro')).toHaveLength(0);
            const soon = buttons('Coming soon');

            expect(soon).toHaveLength(1);
            expect(soon[0].disabled).toBe(true);
        });

        it('has no "Coming soon" where Pro can be bought', async () => {
            await open(overview());

            expect(buttons('Coming soon')).toHaveLength(0);
            expect(buttons('Upgrade to Pro')).toHaveLength(1);
        });
    });

    describe('cancelling the contract', () => {
        it('sends the browser to the portal of Stripe, opened on the cancellation', async () => {
            await open(pro());
            http.post.mockResolvedValue({ data: { url: 'https://billing.stripe.com/session/bps_cancel' } });

            expect(buttons('Cancel your contract')).toHaveLength(1);

            buttons('Cancel your contract')[0].click();
            await flush();

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/billing/cancel', {}, { silent: true });
            expect(redirectTo).toHaveBeenCalledWith('https://billing.stripe.com/session/bps_cancel');
        });

        it('is not there when the subscription already ends with the period, nor for a member, nor for a Free workspace', async () => {
            for (const data of [pro({ cancel_at_period_end: true }), pro({ can_manage: false }), overview()]) {
                await open(data);

                expect(buttons('Cancel your contract')).toHaveLength(0);
            }
        });

        it('has the subscription read when the portal brings the person back', async () => {
            window.history.replaceState(null, '', '/app/w/shop/billing?cancellation=done');
            http.post.mockResolvedValue({ data: pro({ cancel_at_period_end: true }) });

            await open(pro());

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/billing/refresh', {}, { silent: true });
            expect(shows('The subscription ends on December 15, 2026. Pro stays available until then.')).toBe(true);
            expect(buttons('Cancel your contract')).toHaveLength(0);
        });
    });

    describe('withdrawing from the contract', () => {
        const fresh = (overrides = {}) =>
            pro({
                started_at: '2026-11-07T14:30:00+00:00',
                withdrawal_until: new Date(Date.now() + 10 * 86400000).toISOString(),
                ...overrides,
            });

        /** The declaration, once it is on the page. */
        const declaration = () => document.querySelector('form[aria-labelledby="withdrawal-title"]');

        it('offers the button only while the right can be exercised, and the declaration is closed', async () => {
            await open(fresh());

            expect(buttons('Withdraw from the contract here')).toHaveLength(1);
            expect(visible(declaration())).toBe(false);

            await open(pro());

            expect(buttons('Withdraw from the contract here')).toHaveLength(0);
        });

        it('opens the declaration prefilled with the account, and the contract read-only', async () => {
            await open(fresh());

            buttons('Withdraw from the contract here')[0].click();
            await flush();

            expect(visible(declaration())).toBe(true);
            expect(declaration().querySelector('#withdrawal-name').value).toBe('Ada Lovelace');
            expect(declaration().querySelector('#withdrawal-email').value).toBe('ada@example.test');
            expect(declaration().textContent).toContain('Pro subscription, Monthly, ordered on November 7, 2026');
            expect(declaration().textContent).toContain('You can withdraw until');
            expect(buttons('Confirm the withdrawal')).toHaveLength(1);
            expect(buttons('Confirm the withdrawal')[0].disabled).toBe(false);
        });

        it('sends what was typed, and ends on the notice that the withdrawal is recorded', async () => {
            await open(fresh());
            buttons('Withdraw from the contract here')[0].click();
            await flush();
            const name = declaration().querySelector('#withdrawal-name');
            const email = declaration().querySelector('#withdrawal-email');
            name.value = 'Ada Byron';
            name.dispatchEvent(new Event('input', { bubbles: true }));
            email.value = 'receipts@example.test';
            email.dispatchEvent(new Event('input', { bubbles: true }));
            http.post.mockResolvedValue({
                data: fresh({ withdrawn: true, withdrawal_until: null }),
                meta: { acknowledged: true },
            });

            buttons('Confirm the withdrawal')[0].click();
            await flush();

            expect(http.post).toHaveBeenCalledWith(
                '/api/v1/workspaces/shop/billing/withdraw',
                { name: 'Ada Byron', email: 'receipts@example.test' },
                { silent: true },
            );
            expect(visible(declaration())).toBe(false);
            expect(buttons('Withdraw from the contract here')).toHaveLength(0);
            expect(buttons('Cancel your contract')).toHaveLength(0);
            expect(notify).toHaveBeenCalledWith(
                'success',
                'Your withdrawal is recorded. An acknowledgement is on its way to receipts@example.test.',
            );
        });

        it('cannot be confirmed with an empty name, and can be given up', async () => {
            await open(fresh());
            buttons('Withdraw from the contract here')[0].click();
            await flush();
            const name = declaration().querySelector('#withdrawal-name');
            name.value = '';
            name.dispatchEvent(new Event('input', { bubbles: true }));
            await flush();

            expect(buttons('Confirm the withdrawal')[0].disabled).toBe(true);

            buttons('Cancel')
                .find((button) => declaration().contains(button))
                .click();
            await flush();

            expect(visible(declaration())).toBe(false);
            expect(http.post).not.toHaveBeenCalled();
        });

        it('tells why the right is gone when the server refuses, and closes the declaration with its button', async () => {
            await open(fresh());
            buttons('Withdraw from the contract here')[0].click();
            await flush();
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'already_withdrawn' }));

            buttons('Confirm the withdrawal')[0].click();
            await flush();

            expect(notify).toHaveBeenCalledWith('danger', 'You already withdrew from this contract.');
            expect(visible(declaration())).toBe(false);
            expect(buttons('Confirm the withdrawal')).toHaveLength(0);
        });

        it('tells the owner, once it is made, that the subscription is ending', async () => {
            await open(fresh({ withdrawn: true, withdrawal_until: null }));

            expect(shows('Your withdrawal is recorded and your subscription is ending.')).toBe(true);
        });
    });

    describe('when Stripe brings the person back', () => {
        it('says that the payment is being confirmed, then that Pro is active', async () => {
            window.history.replaceState(null, '', '/app/w/shop/billing?checkout=done');
            const states = [];
            http.post.mockImplementation(async () => {
                await flush();
                states.push([
                    shows('Payment being confirmed…'),
                    document.querySelector('.alert-info').hidden,
                    document.querySelector('[role="status"]').textContent,
                    buttons('Upgrade to Pro')[0].disabled,
                ]);

                return { data: pro() };
            });

            await open(overview());
            await vi.waitFor(() => expect(http.post).toHaveBeenCalled());
            await flush();

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/billing/refresh', {}, { silent: true });
            expect(states).toEqual([[true, false, 'Payment being confirmed…', true]]);
            expect(document.querySelector('.alert-info').hidden).toBe(true);
            expect(planCard().querySelector('.badge').textContent).toBe('Pro');
            expect(notify).toHaveBeenCalledWith('success', 'The Pro plan is active.');
            expect(window.location.search).toBe('');
        });
    });
});
