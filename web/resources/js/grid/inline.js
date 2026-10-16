/**
 * Editing a value in place, in the cell of the table.
 *
 * The cell of a person who may edit items is a button; pressing it turns the cell into the control of the field (a box, a
 * select) with the cursor in it. Enter or leaving the control saves just that value, Escape gives up and puts the cursor
 * back on the cell, Tab moves on as it always does (the control loses the cursor, which saves). A value the server refuses
 * is shown under the control, which stays until the person fixes it, gives up (Escape) or edits another cell, which gives
 * it up; an item that is gone is reported and the list is read again. A refusal that comes after the control was closed
 * (the page, the search or the view changed meanwhile) is told by a toast that names the field, so that no edit is lost
 * unseen. The row is updated from the answer where it stands: the list is not sorted again under the person's hands. When
 * the field adds up, or when a search or filters narrow the list down (an edit can change what matches), the totals and
 * the count are read again, without replacing the rows. A reading of the list that was under way when the value was saved
 * may carry the value it had before: its answer is dropped and the list is read again.
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
import { totalsKeyOf } from './query.js';
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

        /** The value of `aria-describedby` for the control of the cell: the message that says why its value was refused. */
        get inlineDescribedBy() {
            return this.inlineError === '' ? null : 'item-inline-error';
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
         * field instead. A cell that is being edited is left alone; another one takes its place once the value on its way
         * has been answered, giving up a value the server refused.
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

            // A value still on its way: the person is not asked to repeat the click.
            const sending = this.inlinePending !== null;

            await this.inlinePending;

            // The list may have been read again while waiting (the saving does, when it raced a reading): the cell opens on the
            // item as it is now, and not at all when it is not on the page any more.
            const current = this.items.find((candidate) => candidate.id === row.id);

            if (current === undefined) {
                return;
            }

            if (this.inlineRow !== null) {
                if (this.inlineSaving) {
                    return;
                }

                // A refusal the person never saw (it came after the click that left the control) is not lost silently.
                if (sending && this.inlineError !== '') {
                    this.tellNotSaved(this.inlineDefinition?.name ?? '', this.inlineError);
                }

                // A value that was refused is given up: the cell shows the value the item holds again.
                this.closeInline(false);
            }

            this.inlineRow = row.id;
            this.inlineFieldId = field.id;
            this.inlineOriginal = toInput(field, current.values?.[field.id]);
            this.inlineInput = this.inlineOriginal;
            this.inlineError = '';

            const root = this.rootElement;

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
                // A reading of the list that began before the value was committed can answer after it, with the old one.
                const raced = this.loadingItems;
                const index = this.items.findIndex((candidate) => candidate.id === itemId);

                if (index !== -1) {
                    this.items.splice(index, 1, answer.data);
                }

                this.rebuild();
                this.closeInline(source === 'enter');

                if (raced) {
                    // Reading the list again takes over from the one in flight, whose answer is dropped. What it asks for is
                    // what that reading was for (the page the person is moving to), and the totals with it.
                    this.totalsFor = null;
                    await this.loadItems(this.wantedPage);
                } else if (isSummable(field) || this.hasCriteria) {
                    // The edit can change what a column adds up to, and what the search or the filters match: until they are
                    // read again, any reading of the list that is asked for meanwhile carries the totals too.
                    this.totalsFor = null;
                    await this.refreshTotals();
                }
            } catch (error) {
                this.failInline(error, source, field, itemId);
            } finally {
                this.inlineSaving = false;
            }
        },

        /**
         * Tells the person why a value was not saved: under the control for a refusal (which stays, to be fixed), by the
         * toast the HTTP helper already showed for an item that is gone (and the list is read again, since what is shown is
         * not what the server holds), or by a toast. When the control is gone by the time the answer comes (the page, the
         * search or the view changed meanwhile), the value would be lost unseen: a toast then names the field and says why.
         *
         * @param {unknown} error
         * @param {'enter' | 'blur'} source
         * @param {Field} field The field whose value was sent.
         * @param {string} itemId The item it was sent for.
         * @returns {void}
         */
        failInline(error, source, field, itemId) {
            const editing = this.inlineRow === itemId && this.inlineFieldId === field.id;

            if (error instanceof HttpError && error.status === 404) {
                if (!error.reported) {
                    notify('warning', error.message);
                }

                if (editing) {
                    this.closeInline(false);
                }

                // If it is the inventory that is gone, this reading finds out and takes the person away.
                this.loadItems(this.page);

                return;
            }

            const reason = this.refusalOf(error, field);

            if (!editing) {
                // A request that was cancelled has nothing to tell, and one the HTTP helper reported has been told already.
                if (reason !== null && !(error instanceof HttpError && error.reported)) {
                    this.tellNotSaved(field.name, reason);
                }

                return;
            }

            if (error instanceof HttpError && error.status === 422) {
                this.inlineError = reason ?? '';
            } else {
                this.fail(error);
            }

            if (source === 'enter') {
                const root = this.rootElement;

                this.$nextTick(() => focusEditor(root));
            }
        },

        /**
         * Why a request that changes a value failed, in words: the message the server gave for the value (or for the item),
         * else its general message.
         *
         * @param {unknown} error
         * @param {Field} field The field whose value was sent.
         * @returns {string | null} Null for anything that is not an answer of the server (a request that was cancelled).
         */
        refusalOf(error, field) {
            if (!(error instanceof HttpError)) {
                return null;
            }

            const messages = error.status === 422 ? firstMessages(error.errors) : {};

            return messages[VALUES_PREFIX + field.id] ?? Object.values(messages)[0] ?? error.message;
        },

        /**
         * Says by a toast that the value of a field was not saved, and why.
         *
         * @param {string} name The name of the field.
         * @param {string} reason
         * @returns {void}
         */
        tellNotSaved(name, reason) {
            notify('warning', t('grid.notice.not_saved', { field: name, reason }));
        },

        /**
         * Reads the page again for its totals and its count alone: the rows stay as they are, so that an edit never moves a
         * row under the person's hands. A newer reading of the list, or of the totals, whenever it was asked for, takes over.
         *
         * @returns {Promise<void>}
         */
        async refreshTotals() {
            const ticket = this.ticket;
            const mine = ++this.totalsTicket;
            const totalsKey = totalsKeyOf(this.query);

            try {
                const answer = await http.get(this.itemsUrl, { params: this.queryParams(this.page, { totals: true }) });

                if (ticket !== this.ticket || mine !== this.totalsTicket) {
                    return;
                }

                this.totals = answer.meta.totals ?? {};
                this.totalsFor = totalsKey;
                this.total = answer.meta.total;
                this.lastPage = answer.meta.last_page;
                this.from = answer.meta.from ?? 0;
                this.to = answer.meta.to ?? 0;
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
            const root = this.rootElement;

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
