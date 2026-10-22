/**
 * The part of the page of an inventory that scans barcodes: the scan button of a barcode field in the dialog of an item,
 * the scan button of the search box, and the offer to add an item with a code that no item has.
 *
 * It is a piece of the `inventoryItems` component (see `../lib/compose.js`), so `this` is the whole component: it sets the
 * value of a field (`set`), runs the search (`submitSearch`) and opens the dialog that adds an item (`startCreate`). The
 * camera and the reader are the scanner panels (`../components/barcode-scanner.js`), set in the item dialog and in a
 * dialog of their own for the search; they are started and heard through the events of `./events.js`, and the answer
 * names the panel it comes from (`slot`).
 *
 * A code read for a field goes through `set` like a typed one, and the server stays the only judge of it. A code read for
 * the search is looked for as it is (the searchable text of an item holds the codes of its barcode fields), except that a
 * UPC-A code is looked for as its 12 digits and the EAN-13 of a book as the 9 digits it shares with its ISBN-10 (see
 * `searchTermFor`). `scannedCode`, kept in the criteria, is the code as read.
 *
 * While the panel of the item dialog scans, it takes the place of the form and of the buttons of the dialog (Delete, Cancel,
 * Save), and Escape, a click outside and the cross of the dialog end the scan and not the dialog (see `cancelFieldScan`): one
 * more press closes the dialog, as it always did.
 */
import { isScannable, scanFormatOf } from '../fields/index.js';
import { hideModal, showModal } from '../lib/modal.js';
import { requestScan, stopScan } from './events.js';
import { normalizeScan, searchTermFor } from './formats.js';

/** The slot of the panel in the dialog of an item. */
const FIELD_SLOT = 'field';

/** The slot of the panel in the dialog of the search. */
const SEARCH_SLOT = 'search';

/** The longest search the box takes (its `maxlength`). */
const SEARCH_MAX_LENGTH = 200;

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function inventoryScan() {
    return {
        /** The id of the barcode field the scanner is filling in, null while the dialog of the item does not scan. */
        scanningField: null,

        /** Whether the dialog of the item is scanning: its form gives way to the picture of the camera. */
        get scanning() {
            return this.scanningField !== null;
        },

        /** The first barcode field of the inventory (in the order of the fields), null when it has none. */
        get firstBarcodeField() {
            return this.fields.find(isScannable) ?? null;
        },

        /**
         * Whether the page offers to add an item with the code that was scanned: the search for that very code (as the search
         * looks for it), and nothing else, matches no item of an inventory that holds some, the person may add items, and there is a barcode field to
         * put the code in.
         */
        get canAddScanned() {
            return (
                this.noMatch &&
                !this.loading &&
                this.canAdd &&
                this.scannedCode !== '' &&
                this.query.q === searchTermFor(this.scannedCode) &&
                this.filterCount === 0 &&
                this.firstBarcodeField !== null
            );
        },

        // The field types know which of them are read from a barcode (see `../fields`): the template asks through the component.
        isScannable,

        /**
         * Starts reading the value of a barcode field with the camera, in the dialog of the item.
         *
         * @param {import('../fields/types.js').Field} field
         * @returns {void}
         */
        scanField(field) {
            this.scanningField = field.id;
            requestScan(FIELD_SLOT, scanFormatOf(field));
        },

        /**
         * Stops the scan of the dialog of the item and shows its form again (the dialog is reset or closed).
         *
         * @returns {void}
         */
        stopFieldScan() {
            if (this.scanningField !== null) {
                this.scanningField = null;
                stopScan(FIELD_SLOT);
            }
        },

        /**
         * The person asked to close the dialog of the item (Escape, a click outside, the cross) while it scans: the scan ends and
         * the form comes back, with the cursor in the box of the field, and the dialog stays.
         *
         * @returns {void}
         */
        cancelFieldScan() {
            stopScan(FIELD_SLOT);
            this.returnToField();
        },

        /**
         * Opens the dialog that scans a code for the search box.
         *
         * @returns {void}
         */
        scanSearch() {
            showModal(this.$refs.scanModal);
            requestScan(SEARCH_SLOT, null);
        },

        /**
         * A scanner panel read a code: the field being filled in gets it, or the search looks for it.
         *
         * @param {CustomEvent} event `detail`: `{ slot, value }`.
         * @returns {void}
         */
        onBarcodeScanned(event) {
            const { slot, value } = event.detail;

            if (slot === FIELD_SLOT) {
                this.fillScanned(value);
            } else if (slot === SEARCH_SLOT) {
                this.searchScanned(value);
            }
        },

        /**
         * The person gave up scanning: the form comes back, or the dialog of the search closes.
         *
         * @param {CustomEvent} event `detail`: `{ slot }`.
         * @returns {void}
         */
        onBarcodeCancelled(event) {
            const { slot } = event.detail;

            if (slot === FIELD_SLOT) {
                this.returnToField();
            } else if (slot === SEARCH_SLOT) {
                hideModal(this.$refs.scanModal);
            }
        },

        /**
         * Puts a scanned code in the field being filled in, and the cursor in its box. A code that comes while the form is being
         * saved is ignored: the request is on its way with the values the person validated, and the form must not change under it.
         *
         * @param {string} value
         * @returns {void}
         */
        fillScanned(value) {
            const field = this.fields.find((one) => one.id === this.scanningField);

            if (field !== undefined && !this.busy) {
                this.set(field, value);
            }

            this.returnToField();
        },

        /**
         * Shows the form again, with the cursor in the box of the field that was being scanned.
         *
         * @returns {void}
         */
        returnToField() {
            const field = this.fields.find((one) => one.id === this.scanningField);

            this.scanningField = null;

            if (field !== undefined) {
                this.$nextTick(() => document.getElementById(this.controlId(field))?.focus());
            }
        },

        /**
         * Closes the dialog of the search and looks for the code it read.
         *
         * @param {string} value
         * @returns {void}
         */
        searchScanned(value) {
            const code = value.slice(0, SEARCH_MAX_LENGTH);

            hideModal(this.$refs.scanModal);
            this.searchText = searchTermFor(code);
            this.submitSearch();
            this.scannedCode = code;
        },

        /**
         * Opens the dialog that adds an item with the code that was scanned in the first barcode field. The code is turned into
         * what that field takes (UPC-A, ISBN-10) like a code scanned for the field itself, as an EAN-13: the conversions only
         * apply to a code of 13 digits.
         *
         * @returns {void}
         */
        addScanned() {
            const field = this.firstBarcodeField;
            const code = this.scannedCode;

            if (field === null || code === '') {
                return;
            }

            this.startCreate();
            this.set(field, normalizeScan(code, 'ean_13', scanFormatOf(field)));
        },
    };
}
