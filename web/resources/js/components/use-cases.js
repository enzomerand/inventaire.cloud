/**
 * Alpine component behind the use cases of the home page: a list of buttons, one for each template, and a panel for each (the grid
 * its inventory would have). Exactly one is shown.
 *
 * Every panel is in the page, so that search engines read them all, and the server hides all but the first: without the script the
 * first one stays. The buttons and the panels carry their rank in `data-index` because the CSP build of Alpine does not allow Blade to
 * write values into expressions.
 */

/**
 * @returns {{
 *   index: number,
 *   isCurrent: (element: HTMLElement) => boolean,
 *   isOther: (element: HTMLElement) => boolean,
 *   select: (event: Event) => void,
 * }}
 */
export default function useCases() {
    return {
        /** Rank of the use case that is shown. */
        index: 0,

        /**
         * Whether an element (a button or a panel) is the one of the use case that is shown.
         *
         * @param {HTMLElement} element Carries its rank in `data-index`.
         * @returns {boolean}
         */
        isCurrent(element) {
            return Number(element.dataset.index) === this.index;
        },

        /**
         * Whether an element (a panel) belongs to a use case that is not shown, which is what its `hidden` attribute follows.
         *
         * @param {HTMLElement} element Carries its rank in `data-index`.
         * @returns {boolean}
         */
        isOther(element) {
            return !this.isCurrent(element);
        },

        /**
         * Shows the use case of the button that was clicked. A rank that is not a whole number (or is missing) is ignored.
         *
         * @param {Event} event Click on a button carrying `data-index`.
         * @returns {void}
         */
        select(event) {
            const rank = event.currentTarget.dataset.index ?? '';

            if (/^\d+$/.test(rank)) {
                this.index = Number(rank);
            }
        },
    };
}
