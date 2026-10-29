/**
 * The interactive parts of the sales pages under the real Alpine CSP build: the use case picker and the switch between the monthly and
 * the yearly price. The markup is the one of the Blade views written out (jsdom has no Blade), and the views are read to check that the
 * directives written here are the ones they carry, so the two cannot drift apart without a failure.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

const VIEWS = join(import.meta.dirname, '../../../resources/views');
const read = (path) => readFileSync(join(VIEWS, path), 'utf8');

/** The directives of the picker, as the views write them. */
const PICKER_DIRECTIVES = [
    ':aria-pressed="isCurrent($el)"',
    '@click="select"',
    ':hidden="!isCurrent($el)"',
    'x-data="useCases"',
];

/** The directives of the switch, as the view writes them. */
const SWITCH_DIRECTIVES = [
    'x-data="pricingPeriod"',
    ':data-period="period"',
    ':data-ready="ready"',
    ':hidden="!ready"',
    'x-model="period"',
    ':href="ctaHref"',
];

describe('the views carry the directives these tests mount', () => {
    it.each(PICKER_DIRECTIVES)('landing/use-cases.blade.php has %s', (directive) => {
        expect(read('landing/use-cases.blade.php')).toContain(directive);
    });

    it.each(SWITCH_DIRECTIVES)('partials/pricing-plans.blade.php has %s', (directive) => {
        expect(read('partials/pricing-plans.blade.php')).toContain(directive);
    });
});

describe('the sales pages under the real Alpine CSP build', () => {
    let Alpine;

    beforeAll(async () => {
        ({ default: Alpine } = await import('@alpinejs/csp'));
        const { default: useCases } = await import('../../../resources/js/components/use-cases.js');
        const { default: pricingPeriod } = await import('../../../resources/js/components/pricing-period.js');

        window.Alpine = Alpine;
        Alpine.data('useCases', useCases);
        Alpine.data('pricingPeriod', pricingPeriod);
        Alpine.start();
    });

    afterAll(() => {
        Alpine.stopObservingMutations();
    });

    /** Waits for Alpine to flush its DOM updates. */
    async function flush() {
        await Alpine.nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    describe('the use case picker', () => {
        beforeEach(async () => {
            document.body.innerHTML = `
                <div x-data="useCases">
                    ${[0, 1, 2]
                        .map(
                            (index) => `<button type="button" id="use-case-${index}" data-index="${index}"
                                aria-pressed="${index === 0}" :aria-pressed="isCurrent($el)" @click="select"></button>`,
                        )
                        .join('')}
                    ${[0, 1, 2]
                        .map(
                            (index) => `<div role="region" data-index="${index}" ${index === 0 ? '' : 'hidden'}
                                :hidden="!isCurrent($el)"></div>`,
                        )
                        .join('')}
                </div>`;
            await flush();
        });

        const pressed = () =>
            [...document.querySelectorAll('button')].map((button) => button.getAttribute('aria-pressed'));
        const shown = () => [...document.querySelectorAll('[role="region"]')].map((panel) => !panel.hidden);

        it('shows the first use case and presses its button', () => {
            expect(pressed()).toEqual(['true', 'false', 'false']);
            expect(shown()).toEqual([true, false, false]);
        });

        it('shows the use case of the button that is clicked, and presses it alone', async () => {
            document.querySelector('#use-case-2').click();
            await flush();

            expect(pressed()).toEqual(['false', 'false', 'true']);
            expect(shown()).toEqual([false, false, true]);

            document.querySelector('#use-case-0').click();
            await flush();

            expect(pressed()).toEqual(['true', 'false', 'false']);
            expect(shown()).toEqual([true, false, false]);
        });
    });

    describe('the switch between the monthly and the yearly price', () => {
        /** Mounts the plans with the address the page was opened at. */
        async function open(search = '') {
            window.history.replaceState(null, '', `/pricing${search}`);
            document.body.innerHTML = `
                <div x-data="pricingPeriod" data-period="month" :data-period="period" :data-ready="ready"
                    data-href-month="/app/billing?interval=month" data-href-year="/app/billing?interval=year">
                    <fieldset id="toggle" hidden :hidden="!ready">
                        <input type="radio" name="pricing-period" id="month" value="month" x-model="period" checked>
                        <input type="radio" name="pricing-period" id="year" value="year" x-model="period">
                    </fieldset>
                    <a id="pro" href="/app/billing?interval=month" :href="ctaHref">Choose Pro</a>
                </div>`;
            await flush();
        }

        const root = () => document.querySelector('[data-period]');

        it('shows the switch once it started, on the monthly price, and says it is ready', async () => {
            await open();

            expect(document.querySelector('#toggle').hidden).toBe(false);
            expect(root().dataset.period).toBe('month');
            expect(root().hasAttribute('data-ready')).toBe(true);
            expect(document.querySelector('#pro').getAttribute('href')).toBe('/app/billing?interval=month');
        });

        it('follows the radio that is chosen, the button of Pro with it', async () => {
            await open();

            document.querySelector('#year').click();
            await flush();

            expect(root().dataset.period).toBe('year');
            expect(document.querySelector('#pro').getAttribute('href')).toBe('/app/billing?interval=year');

            document.querySelector('#month').click();
            await flush();

            expect(root().dataset.period).toBe('month');
            expect(document.querySelector('#pro').getAttribute('href')).toBe('/app/billing?interval=month');
        });

        it('opens on the yearly price when the address asks for it', async () => {
            await open('?interval=year');

            expect(root().dataset.period).toBe('year');
            expect(document.querySelector('#year').checked).toBe(true);
            expect(document.querySelector('#pro').getAttribute('href')).toBe('/app/billing?interval=year');
        });
    });
});
