/**
 * Alpine component behind the switch between the monthly and the yearly price of Pro (partials/pricing-plans.blade.php).
 *
 * Both prices are in the page and the stylesheet shows the one of the root's `data-period`, which follows `period` here. The switch
 * itself is hidden until the component has started (`ready`), and so is the line that stands for it without a script: a page without
 * JavaScript shows the monthly price and the yearly one under it, and its button leads to the monthly plan. The two addresses of the
 * button come from the root (`data-href-month`, `data-href-year`), because the CSP build of Alpine does not allow Blade to write values
 * into expressions. `?interval=year` in the address opens the page on the yearly price.
 */

/**
 * @returns {{
 *   period: 'month' | 'year',
 *   ready: boolean,
 *   rootElement: HTMLElement | null,
 *   readonly ctaHref: string,
 *   init: () => void,
 * }}
 */
export default function pricingPeriod() {
    return {
        /** The period the prices are shown for: `month` or `year`. */
        period: 'month',

        /** Whether the component has started, which is when the switch is shown. */
        ready: false,

        /** Root element of the component, kept from `init()` because `$root` is gone once an element has left the page. */
        rootElement: null,

        /** Where the button of Pro leads for the period that is shown, from `data-href-month` and `data-href-year` on the root. */
        get ctaHref() {
            const { hrefMonth, hrefYear } = this.rootElement.dataset;

            return this.period === 'year' ? hrefYear : hrefMonth;
        },

        /**
         * Starts on the period the address asks for (`?interval=year`), shows the switch and records that the script runs.
         *
         * @returns {void}
         */
        init() {
            this.rootElement = this.$root;

            if (new URLSearchParams(window.location.search).get('interval') === 'year') {
                this.period = 'year';
            }

            this.ready = true;
        },
    };
}
