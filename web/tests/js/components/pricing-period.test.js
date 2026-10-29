import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import pricingPeriod from '../../../resources/js/components/pricing-period.js';

const MONTH = '/app/billing?interval=month';
const YEAR = '/app/billing?interval=year';

describe('the switch between the monthly and the yearly price', () => {
    let component;

    /** Mounts the component on a root that carries the two addresses of the button of Pro. */
    function mount() {
        component = pricingPeriod();
        // Alpine injects $root: the element carrying x-data and the data-* attributes.
        component.$root = { dataset: { hrefMonth: MONTH, hrefYear: YEAR } };
    }

    beforeEach(() => {
        window.history.replaceState(null, '', '/pricing');
        mount();
    });

    afterEach(() => {
        window.history.replaceState(null, '', '/');
    });

    it('is not ready, and shows the monthly price, until it starts', () => {
        expect(component.ready).toBe(false);
        expect(component.period).toBe('month');
    });

    it('starts on the monthly price and shows the switch', () => {
        component.init();

        expect(component.ready).toBe(true);
        expect(component.period).toBe('month');
        expect(component.ctaHref).toBe(MONTH);
    });

    it('sends the button of Pro to the plan of the period that is shown', () => {
        component.init();
        component.period = 'year';

        expect(component.ctaHref).toBe(YEAR);

        component.period = 'month';

        expect(component.ctaHref).toBe(MONTH);
    });

    it('opens on the yearly price when the address asks for it', () => {
        window.history.replaceState(null, '', '/pricing?interval=year');
        component.init();

        expect(component.period).toBe('year');
        expect(component.ctaHref).toBe(YEAR);
    });

    it('keeps the monthly price for any other period in the address', () => {
        for (const search of ['?interval=month', '?interval=decade', '?interval=', '?interval[]=year', '?other=year']) {
            window.history.replaceState(null, '', `/pricing${search}`);
            mount();
            component.init();

            expect(component.period, search).toBe('month');
        }
    });
});
