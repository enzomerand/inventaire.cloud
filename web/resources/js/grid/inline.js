/**
 * Editing a value in place, in the cell of the table.
 *
 * The cell of a person who may edit items is a button; pressing it turns the cell into the control of the field (a box, a
 * select) with the cursor in it. Enter or leaving the control saves just that value, Escape gives up and puts the cursor
 * back on the cell, Tab moves on as it always does (the control loses the cursor, which saves). A value the server refuses
 * is shown under the control, which stays; an item that is gone is reported and the list is read again. The row is updated
 * from the answer where it stands: the list is not sorted again under the person's hands. When the field adds up, the
 * totals (and the count) are read again, without replacing the rows.
 *
 * The controls that do not fit a cell (a long text, several choices, tags, a rating, a color) open the dialog of the item
 * with the cursor on that field instead.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`): one cell is edited at a time, and what is edited is
 * the text a control holds (see `fromInput` / `toInput` of the field types) until it is saved.
 */
import { controlOf, fromInput, inputModeOf, inputTypeOf, isSummable, maxLengthOf, toInput } from '../fields/index.js';
import { firstMessages, fillUrl } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { totalCells } from './totals.js';
import { cellKey, cellValueText } from './rows.js';

/** The controls that are edited in the cell itself; the others open the dialog. */
const INLINE_CONTROLS = ['input', 'select'];

/** The prefix of the names the API gives the errors of the values of an item (`values.<field ULID>`). */
const VALUES_PREFIX = 'values.';

/**
 * @typedef {import('../fields/types.js').Field} Field
 */

/**
 * Puts the cursor in the control of the cell being edited, with its text selected.
 *
 * @param {Element} root The root of the component.
 * @returns {void}
 */
function focusEditor(root) {
    const control = /** @type {HTMLInputElement | null} */ (root.querySelector?.('#item-inline-editor') ?? null);

    control?.focus();
    control?.select?.();
}

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function gridInline() {
    return {
        /** The id of the item whose cell is being edited, null when none is. */
        inlineRow: null,

        /** The id of the field whose cell is being edited. */
        inlineFieldId: null,

        /** What the control holds. */
        inlineInput: '',

        /** What the control held when the cell was opened, to save only a change. */
        inlineOriginal: '',

        /** Why the value was refused ("" when it was not). */
        inlineError: '',

        /** True while the value is being sent: the control is disabled. */
        inlineSaving: false,

        /** The saving under way, which a click on another cell waits for. */
        inlinePending: null,

        /** The field of the cell being edited. */
        get inlineDefinition() {
            return this.fields.find((field) => field.id === this.inlineFieldId) ?? null;
        },

        /** Which control the cell shows: `input` or `select`. */
        get inlineControl() {
            return this.inlineDefinition === null ? '' : controlOf(this.inlineDefinition);
        },

        /** The `type` of the box of the cell. */
        get inlineType() {
            return this.inlineDefinition === null ? 'text' : inputTypeOf(this.inlineDefinition);
        },

        /** The `inputmode` of the box of the cell. */
        get inlineMode() {
            return this.inlineDefinition === null ? null : inputModeOf(this.inlineDefinition);
        },

        /** The longest text the box of the cell takes. */
        get inlineMax() {
            return this.inlineDefinition === null ? null : maxLengthOf(this.inlineDefinition);
        },

        /** The accessible name of the control of the cell: the name of the field. */
        get inlineLabel() {
            return this.inlineDefinition?.name ?? '';
        },

        /** The options of the select of the cell. */
        get inlineOptions() {
            return this.inlineDefinition === null ? [] : this.selectOptions(this.inlineDefinition);
        },

        /** The classes of the box of the cell: marked when its value was refused. */
        get inlineClass() {
            return this.inlineError === '' ? 'form-control form-control-sm' : 'form-control form-control-sm is-invalid';
        },

        /** The classes of the select of the cell. */
        get inlineSelectClass() {
            return this.inlineError === '' ? 'form-select form-select-sm' : 'form-select form-select-sm is-invalid';
        },

        /** The value of `aria-invalid` for the control of the cell. */
        get inlineInvalid() {
            return this.inlineError === '' ? null : 'true';
        },

        /**
         * Whether a cell is the one being edited.
         *
         * @param {{ id: string }} row
         * @param {{ key: string }} cell
         * @returns {boolean}
         */
        isEditing(row, cell) {
            return this.inlineRow === row.id && this.inlineFieldId === cell.key;
        },

        /**
         * What identifies a cell in the markup, to find it again.
         *
         * @param {{ id: string }} row
         * @param {{ key: string }} cell
         * @returns {string}
         */
        cellKey(row, cell) {
            return cellKey(row.id, cell.key);
        },

        /**
         * The accessible name of the button of a cell: what it edits, and what it holds.
         *
         * @param {{ name: string } & import('../fields/cell.js').Cell} cell
         * @returns {string}
         */
        editCellLabel(cell) {
            return t('grid.cell.edit', { field: cell.name, value: cellValueText(cell) });
        },

        /**
         * The accessible name of the link next to the button of a cell.
         *
         * @param {{ name: string }} cell
         * @returns {string}
         */
        openLinkLabel(cell) {
            return t('grid.cell.open_link', { field: cell.name });
        },

        /**
         * Turns a cell into its control, with the cursor in it; the controls that do not fit a cell open the dialog on the
         * field instead. A cell that is being edited, or another one while a value is being sent, is left alone.
         *
         * @param {{ id: string }} row
         * @param {{ key: string }} cell
         * @returns {Promise<void>}
         */
        async startInline(row, cell) {
            const field = this.fields.find((candidate) => candidate.id === cell.key);
            const item = this.items.find((candidate) => candidate.id === row.id);

            if (!this.canEdit || field === undefined || item === undefined || this.isEditing(row, cell)) {
                return;
            }

            if (!INLINE_CONTROLS.includes(controlOf(field))) {
                this.begin('edit', item, field.id);

                return;
            }

            // A value still on its way: the person is not asked to repeat the click, unless it was refused and is still being fixed.
            await this.inlinePending;

            if (this.inlineRow !== null) {
                if (this.inlineSaving || this.inlineError !== '') {
                    return;
                }

                this.closeInline(false);
            }

            this.inlineRow = row.id;
            this.inlineFieldId = field.id;
            this.inlineOriginal = toInput(field, item.values?.[field.id]);
            this.inlineInput = this.inlineOriginal;
            this.inlineError = '';

            // The root is taken now: once the button is gone, Alpine no longer answers `$root` to a call made from its scope.
            const root = this.$root;

            this.$nextTick(() => focusEditor(root));
        },

        /**
         * Takes what was typed or picked in the control of the cell, and forgets the refusal that was about it.
         *
         * @param {Event} event
         * @returns {void}
         */
        onInlineInput(event) {
            this.inlineInput = /** @type {HTMLInputElement} */ (event.target).value;
            this.inlineError = '';
        },

        /**
         * Gives up: the cell shows its value again, and the cursor goes back on its button.
         *
         * @returns {void}
         */
        cancelInline() {
            if (!this.inlineSaving) {
                this.closeInline(true);
            }
        },

        /**
         * Saves the value of the control, if it changed (Enter, or the cursor leaving the control).
         *
         * @param {'enter' | 'blur'} source What asked: after Enter the cursor goes back on the button of the cell, after the
         *   cursor left it stays where the person put it.
         * @returns {Promise<void>}
         */
        async commitInline(source) {
            const field = this.inlineDefinition;

            if (this.inlineRow === null || field === null || this.inlineSaving) {
                return;
            }

            if (JSON.stringify(this.inlineInput) === JSON.stringify(this.inlineOriginal)) {
                this.closeInline(source === 'enter');

                return;
            }

            this.inlineSaving = true;
            this.inlineError = '';
            this.inlinePending = this.saveInline(field, this.inlineRow, fromInput(field, this.inlineInput), source);

            try {
                await this.inlinePending;
            } finally {
                this.inlinePending = null;
            }
        },

        /**
         * Sends one value of an item, and shows the item as the answer describes it.
         *
         * @param {Field} field
         * @param {string} itemId
         * @param {any} value What to send: null clears the field, as it does in the dialog.
         * @param {'enter' | 'blur'} source
         * @returns {Promise<void>}
         */
        async saveInline(field, itemId, value, source) {
            try {
                const answer = await http.patch(fillUrl(this.itemUrl, itemId), { values: { [field.id]: value } });
                const index = this.items.findIndex((candidate) => candidate.id === itemId);

                if (index !== -1) {
                    this.items.splice(index, 1, answer.data);
                }

                this.rebuild();
                this.closeInline(source === 'enter');

                if (isSummable(field)) {
                    await this.refreshTotals();
                }
            } catch (error) {
                this.failInline(error, source);
            } finally {
                this.inlineSaving = false;
            }
        },

        /**
         * Tells the person why a value was not saved: under the control for a refusal (which stays, to be fixed), by the
         * toast the HTTP helper already showed for an item that is gone (and the list is read again, since what is shown is
         * not what the server holds), or by a toast.
         *
         * @param {unknown} error
         * @param {'enter' | 'blur'} source
         * @returns {void}
         */
        failInline(error, source) {
            if (error instanceof HttpError && error.status === 404) {
                if (!error.reported) {
                    notify('warning', error.message);
                }

                this.closeInline(false);
                // If it is the inventory that is gone, this reading finds out and takes the person away.
                this.loadItems(this.page);

                return;
            }

            if (error instanceof HttpError && error.status === 422) {
                const messages = firstMessages(error.errors);

                this.inlineError =
                    messages[VALUES_PREFIX + this.inlineFieldId] ?? Object.values(messages)[0] ?? error.message;
            } else {
                this.fail(error);
            }

            if (source === 'enter') {
                const root = this.$root;

                this.$nextTick(() => focusEditor(root));
            }
        },

        /**
         * Reads the page again for its totals and its count alone: the rows stay as they are, so that an edit never moves a
         * row under the person's hands. A newer reading of the list, whenever it was asked for, takes over.
         *
         * @returns {Promise<void>}
         */
        async refreshTotals() {
            const ticket = this.ticket;

            try {
                const answer = await http.get(this.itemsUrl, { params: this.queryParams(this.page) });

                if (ticket !== this.ticket) {
                    return;
                }

                this.totals = answer.meta.totals ?? {};
                this.total = answer.meta.total;
                this.lastPage = answer.meta.last_page;
                this.totalRow = totalCells(this.fields, this.totals);

                if (!this.hasCriteria) {
                    this.itemsCount = answer.meta.total;
                }
            } catch {
                // The totals stay as they were until the next reading of the list: the value itself was saved.
            }
        },

        /**
         * Ends the editing of the cell, whatever the way, optionally putting the cursor back on its button.
         *
         * @param {boolean} refocus
         * @returns {void}
         */
        closeInline(refocus) {
            const key = this.inlineRow === null ? null : cellKey(this.inlineRow, this.inlineFieldId);
            const root = this.$root;

            this.inlineRow = null;
            this.inlineFieldId = null;
            this.inlineInput = '';
            this.inlineOriginal = '';
            this.inlineError = '';

            if (refocus && key !== null) {
                this.$nextTick(() => root.querySelector?.(`[data-cell="${key}"]`)?.focus());
            }
        },
    };
}
