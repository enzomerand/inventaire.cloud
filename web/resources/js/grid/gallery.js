/**
 * The way the items are laid out: a table, or a gallery of cards.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`). The view comes from the address of the page, the
 * choice made last for this inventory, or the width of the screen (see `view.js`); a choice made with the toggle is kept in
 * the browser and written in the address. The gallery shows the same page of items, with the same toolbar, count, pagination
 * and totals (as a line above the cards, since cards have no columns to carry them); a card opens the item like a row does.
 */
import { cardOf } from './rows.js';
import { defaultView, rememberView } from './view.js';

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function gridView() {
    return {
        /** How the items are laid out: `table` or `gallery`. */
        view: 'table',

        /** The cards of the gallery: for each item, its name and a few of its values. */
        cards: [],

        /** Whether the items are shown as a table. */
        get isTable() {
            return this.view === 'table';
        },

        /** Whether the items are shown as cards. */
        get isGallery() {
            return this.view === 'gallery';
        },

        /** Whether the table is on screen: it is the view, and there is something to show. */
        get showTable() {
            return this.isTable && this.hasTable;
        },

        /** Whether the gallery is on screen: it is the view, and there is something to show. */
        get showGallery() {
            return this.isGallery && this.hasTable;
        },

        /** The value of `aria-pressed` for the button of the table view. */
        get tablePressed() {
            return this.isTable ? 'true' : 'false';
        },

        /** The value of `aria-pressed` for the button of the gallery view. */
        get galleryPressed() {
            return this.isGallery ? 'true' : 'false';
        },

        /** The classes of the button of the table view: pressed looks pressed. */
        get tableButtonClass() {
            return this.isTable ? 'active' : '';
        },

        /** The classes of the button of the gallery view. */
        get galleryButtonClass() {
            return this.isGallery ? 'active' : '';
        },

        /** The classes of the sort select and its direction: the table has the headers for it on a wide screen, the gallery has not. */
        get sortBarClass() {
            return this.isGallery ? '' : 'd-sm-none';
        },

        /** The totals of the columns that add up, for the line above the cards. */
        get galleryTotals() {
            return this.totalRow
                .filter((cell) => cell.text !== '')
                .map((cell) => ({
                    key: cell.key,
                    name: this.fields.find((field) => field.id === cell.key)?.name,
                    text: cell.text,
                }));
        },

        /** Whether the line of totals is shown above the cards. */
        get showGalleryTotals() {
            return this.showGallery && this.galleryTotals.length > 0;
        },

        /**
         * Picks the view the page opens in: the one the address asks for, else the one chosen last for this inventory, else
         * by the width of the screen.
         *
         * @returns {void}
         */
        pickView() {
            this.view = this.urlView ?? defaultView(this.url);
        },

        /**
         * Works out the cards from the items.
         *
         * @returns {void}
         */
        buildCards() {
            this.cards = this.items.map((item) => cardOf(this.fields, item));
        },

        /**
         * Shows the items as a table.
         *
         * @returns {void}
         */
        showAsTable() {
            this.chooseView('table');
        },

        /**
         * Shows the items as cards.
         *
         * @returns {void}
         */
        showAsGallery() {
            this.chooseView('gallery');
        },

        /**
         * Switches to a view as the person asked: kept for the next visit and written in the address.
         *
         * @param {import('./view.js').View} view
         * @returns {void}
         */
        chooseView(view) {
            if (this.inlineRow !== null) {
                this.closeInline(false);
            }

            this.view = view;
            this.urlView = view;
            rememberView(this.url, view);
            this.syncAddress(this.wantedPage);
        },
    };
}
