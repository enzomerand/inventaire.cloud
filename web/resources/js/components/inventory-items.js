/**
 * Alpine component of the page of an inventory: its items in a table with one column per field, and the dialog that
 * adds, changes, shows and deletes one.
 *
 * The inventory (its fields in order) and a page of its items (50, newest first) are read from the API. Each field is
 * a column; a value is shown as the {@link import('../fields/cell.js').Cell} its type describes (see `../fields`), so
 * the template only chooses the element and nothing typed by a person is ever interpreted as markup. The form of the
 * dialog is generated from the fields: one control per field, chosen by the type, holding the text a control holds
 * (see `fromInput` / `toInput` of the field types) until the form is sent. People who may not edit items (the page
 * says so in `data-can-edit`) get the same dialog as a read-only list of details.
 *
 * A change sends only what the person changed, so that saving a form never rewrites a value somebody else changed in
 * the meantime, nor drops the seconds of a moment the form cannot show. After every change the component reads the
 * page again, so what is shown is what the server holds. The endpoints and the abilities come from the markup
 * (`data-*` attributes on the root), because the CSP build of Alpine does not allow Blade to inject values into
 * expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * under the input they are about, in the dialog, or as a toast.
 *
 * What narrows and orders the list (the search, the filters, the sort, and the address of the page that remembers them)
 * is `../grid/criteria.js`, merged into this component: the page asked for is always read with the criteria in force, an
 * older answer never replaces a newer one, and the totals of the numbers and amounts come with each page.
 */
import {
    choicesOf,
    controlOf,
    describeValue,
    emptyInput,
    fromInput,
    inputModeOf,
    inputTypeOf,
    maxLengthOf,
    selectOptionsOf,
    toInput,
} from '../fields/index.js';
import { firstMessages, fillUrl } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';
import { compose } from '../lib/compose.js';
import gridCriteria from '../grid/criteria.js';
import { isEmptyQuery } from '../grid/query.js';
import { momentText } from '../grid/rows.js';
import { hasTotals, totalCells } from '../grid/totals.js';

/** The color a field gets when a person chooses to give it one: the picker then lets them move on from there. */
const FIRST_COLOR = '#000000';

/** The prefix of the names the API gives the errors of the values of an item (`values.<field ULID>`). */
const VALUES_PREFIX = 'values.';

/**
 * @typedef {import('../fields/types.js').Field} Field
 *
 * @typedef {object} Item An item, as `ItemResource` describes it.
 * @property {string} id
 * @property {Record<string, any>} values The values keyed by the ULID of their field.
 */

/**
 * @returns {Record<string, any>}
 */
export default function inventoryItems() {
    return compose(gridCriteria(), {
        /** The fields of the inventory, in order: the columns, and the controls of the form. */
        fields: [],

        /** The page of items that is shown. */
        items: [],

        /** The rows of the table: for each item, its id and the cell of each field. */
        rows: [],

        /** True until the inventory has been read. */
        loadingInventory: true,

        /** True while a page of items is being read (and until the first one was). */
        loadingItems: true,

        /** True when the last reading of the inventory failed. */
        inventoryFailed: false,

        /** True when the last reading of a page of items failed. */
        itemsFailed: false,

        /** The page the last reading was for, which a retry asks for again. */
        wantedPage: 1,

        /** Number of the page that is shown, from 1. */
        page: 1,

        /** Number of the last page. */
        lastPage: 1,

        /** Items that match the search and the filters, all pages together (all the items of the inventory when nothing narrows the list down). */
        total: 0,

        /** Items in the whole inventory: what the inventory said, kept up to date as items come and go (null while unknown). */
        itemsCount: null,

        /** The sums of the numbers and amounts of the items that match, by field id (`meta.totals`). */
        totals: {},

        /** The foot of each column of the table (see `../grid/totals.js`). */
        totalRow: [],

        /** True once a page of items has been read: from then on a new reading refreshes the list instead of replacing it by a spinner. */
        loadedOnce: false,

        /** Position of the first and of the last item of the page among all of them (0 when there are none). */
        from: 0,
        to: 0,

        /** Ticket of the last page asked for: an answer for an older one is dropped. */
        ticket: 0,

        /** True while a change is in flight; blocks double submits. */
        busy: false,

        /** What the dialog does: `create` an item, `edit` one, or `view` one (read only). */
        mode: 'create',

        /** Id of the item the dialog is about (null while it adds one). */
        itemId: null,

        /** What the controls of the form hold, by field id. */
        values: {},

        /** What the controls held when the form opened, to send only what changed. */
        original: {},

        /** What the dialog shows in `view` mode: one entry per field, with the cell of its value. */
        details: [],

        /** What was typed in the box of the tags of each field and not yet added, by field id. */
        drafts: {},

        /** The messages of a refused form, by field id. */
        errors: {},

        /** Why the form was refused when it is not about one field ("" when it was not). */
        formError: '',

        /** True while the dialog asks to confirm the deletion of the item. */
        confirming: false,

        /** Endpoint of the inventory (GET), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Endpoint of the items (GET, POST), from `data-items-url` on the component root. */
        get itemsUrl() {
            return this.$root.dataset.itemsUrl;
        },

        /** Endpoint of one item, with `__id__` where its ULID goes, from `data-item-url` on the component root. */
        get itemUrl() {
            return this.$root.dataset.itemUrl;
        },

        /** Page of the workspace the inventory belongs to, where the person goes if it was deleted, from `data-workspace-page`. */
        get workspacePage() {
            return this.$root.dataset.workspacePage;
        },

        /** Whether the person may add, change and delete items, from `data-can-edit` on the component root. */
        get canEdit() {
            return this.$root.dataset.canEdit === '1';
        },

        /** Whether the person is offered the link to the field editor, from `data-can-manage-fields` on the root. */
        get canManageFields() {
            return this.$root.dataset.canManageFields === '1';
        },

        /** Whether either the inventory or a page of items is being read. */
        get loading() {
            return this.loadingInventory || this.loadingItems;
        },

        /** Whether the last reading of the inventory or of a page of items failed: the page offers to retry. */
        get loadFailed() {
            return this.inventoryFailed || this.itemsFailed;
        },

        /** Whether the inventory is known to have no field: there is nothing to put in a table yet (not when it could not be read). */
        get noFields() {
            return !this.loadingInventory && !this.inventoryFailed && this.fields.length === 0;
        },

        /** Whether the first reading is under way, which the page shows as a spinner in place of the list. */
        get initialLoading() {
            return this.loadingInventory || (this.loadingItems && !this.loadedOnce);
        },

        /** Whether a page is being read again while the previous one is still shown (a search, a sort, a page). */
        get refreshing() {
            return this.loadedOnce && this.loadingItems;
        },

        /** The value of `aria-busy` for the list: "true" while it is being read again. */
        get busyAttribute() {
            return this.refreshing ? 'true' : 'false';
        },

        /** The classes the list gets while it is being read again: faded, not wiped. */
        get tableClass() {
            return this.refreshing ? 'opacity-75' : '';
        },

        /** Whether the inventory has fields and is known to hold no item (not when the items could not be read). */
        get noItems() {
            return (
                this.loadedOnce &&
                !this.loadFailed &&
                this.fields.length > 0 &&
                this.items.length === 0 &&
                (!this.hasCriteria || this.itemsCount === 0)
            );
        },

        /** Whether the search or the filters leave no item of an inventory that holds some. */
        get noMatch() {
            return (
                this.loadedOnce &&
                !this.loadFailed &&
                this.fields.length > 0 &&
                this.items.length === 0 &&
                this.hasCriteria &&
                this.itemsCount !== 0
            );
        },

        /** Whether the search, the filters and the sort are offered: once there is something to narrow down. */
        get showToolbar() {
            return (
                this.loadedOnce &&
                !this.loadFailed &&
                this.fields.length > 0 &&
                (this.hasCriteria || this.itemsCount !== 0)
            );
        },

        /** Whether the foot of the table shows totals: some column adds up, and there are rows. */
        get showTotals() {
            return this.hasTable && hasTotals(this.totalRow);
        },

        /** The word in front of the totals: they are those of the selection when the list is narrowed down. */
        get totalsLabel() {
            return t(this.hasCriteria ? 'grid.totals.selection' : 'grid.totals.all');
        },

        /** Whether there is a table to show. */
        get hasTable() {
            return this.fields.length > 0 && this.items.length > 0;
        },

        /** Whether the "Add item" button is offered: to the people who may edit, once there is a field to fill in. */
        get canAdd() {
            return this.canEdit && this.fields.length > 0;
        },

        /** Whether there is a page before this one. */
        get hasPrevious() {
            return this.page > 1;
        },

        /** Whether there is a page after this one. */
        get hasNext() {
            return this.page < this.lastPage;
        },

        /** Which items are shown, as a sentence ("1–50 of 120"), or "" when there are none. */
        get rangeLabel() {
            return this.total === 0
                ? ''
                : t('pagination.range', { first: this.from, last: this.to, total: this.total });
        },

        /** The title of the dialog. */
        get modalTitle() {
            return t('inventories.item.title_' + this.mode);
        },

        /** Whether the dialog offers to delete the item: while it changes one, before the deletion is asked for. */
        get canDeleteItem() {
            return this.mode === 'edit' && !this.confirming;
        },

        /** The accessible name of the button of a row: it opens the form of the item, or its details. */
        get openLabel() {
            return t(this.canEdit ? 'inventories.item.edit' : 'inventories.item.show');
        },

        /** Whether the dialog shows the form (it adds an item or changes one). */
        get isForm() {
            return this.mode !== 'view';
        },

        /**
         * Reads the inventory and the first page of items when the page opens (the one the address asks for, with the search,
         * the sort and the filters it holds), and forgets the form whenever the dialog is closed.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.$nextTick(() => onModalHidden(this.$refs.modal, () => this.resetDialog()));

            const page = this.restoreQuery();

            await Promise.all([this.loadInventory(), this.loadItems(page)]);
        },

        /**
         * Stops the pause that waits for the end of the typing, when the page is taken apart.
         *
         * @returns {void}
         */
        destroy() {
            this.stopSearchTimer();
        },

        /**
         * Reads the fields of the inventory from the API. A failure is remembered (`inventoryFailed`) until the next reading,
         * so that the page does not claim the inventory has no field.
         *
         * @returns {Promise<void>}
         */
        async loadInventory() {
            this.inventoryFailed = false;

            try {
                const inventory = (await http.get(this.url)).data;

                this.fields = inventory.fields ?? [];
                this.itemsCount = inventory.items_count ?? this.itemsCount;
                this.rebuild();
            } catch (error) {
                this.inventoryFailed = true;

                if (!this.leaveIfGone(error)) {
                    this.fail(error);
                }
            } finally {
                this.loadingInventory = false;
            }
        },

        /**
         * Reads a page of items from the API, narrowed and ordered as the search, the filters and the sort ask, and writes
         * them in the address of the page. A failure is remembered (`itemsFailed`) until the next reading, so that the page
         * does not claim the inventory holds no item. The server refusing the criteria (a field that was deleted since the
         * address was shared) starts again from everything, with a toast.
         *
         * Only the latest reading counts: the answer to an older one (a search typed over, a page clicked past) is dropped,
         * whenever it comes.
         *
         * @param {number} page Number of the page to read.
         * @returns {Promise<void>}
         */
        async loadItems(page) {
            const ticket = ++this.ticket;
            this.loadingItems = true;
            this.itemsFailed = false;
            this.wantedPage = page;
            this.syncAddress(page);

            try {
                const answer = await http.get(this.itemsUrl, { params: this.queryParams(page) });

                if (ticket !== this.ticket) {
                    return;
                }

                // Items were deleted since the page was shown and it is past the end now: the last one it is.
                if (answer.data.length === 0 && page > answer.meta.last_page) {
                    this.loadingItems = false;
                    await this.loadItems(answer.meta.last_page);

                    return;
                }

                this.items = answer.data;
                this.page = answer.meta.current_page;
                this.lastPage = answer.meta.last_page;
                this.total = answer.meta.total;
                this.from = answer.meta.from ?? 0;
                this.to = answer.meta.to ?? 0;
                this.totals = answer.meta.totals ?? {};
                this.loadedOnce = true;

                // Nothing narrows the list down: it holds the whole inventory.
                if (!this.hasCriteria) {
                    this.itemsCount = answer.meta.total;
                }

                this.rebuild();
            } catch (error) {
                if (
                    ticket === this.ticket &&
                    error instanceof HttpError &&
                    error.status === 422 &&
                    !isEmptyQuery(this.query)
                ) {
                    this.resetQuery();
                    notify('warning', t('grid.notice.reset'));
                    await this.loadItems(1);

                    return;
                }

                if (ticket === this.ticket) {
                    this.itemsFailed = true;

                    if (!this.leaveIfGone(error)) {
                        this.fail(error);
                    }
                }
            } finally {
                if (ticket === this.ticket) {
                    this.loadingItems = false;
                }
            }
        },

        /**
         * Reads again what could not be read: the inventory, and the page of items that was asked for.
         *
         * @returns {Promise<void>}
         */
        async retry() {
            await Promise.all([
                this.inventoryFailed ? this.loadInventory() : Promise.resolve(),
                this.loadItems(this.wantedPage),
            ]);
        },

        /**
         * Works out the cells of the table from the fields and the items, and the foot of its columns from the totals.
         *
         * @returns {void}
         */
        rebuild() {
            this.rows = this.items.map((item) => ({
                id: item.id,
                cells: this.cellsOf(item),
                created: momentText(item.created_at),
                updated: momentText(item.updated_at),
            }));
            this.totalRow = totalCells(this.fields, this.totals);
        },

        /**
         * How each field shows its value in an item.
         *
         * @param {Item} item
         * @param {boolean} [full] Whether to show the whole values (the details of an item) rather than what fits a table cell.
         * @returns {Record<string, any>[]}
         */
        cellsOf(item, full = false) {
            return this.fields.map((field) => ({
                key: field.id,
                name: field.name,
                ...describeValue(field, item.values?.[field.id], { full }),
            }));
        },

        /**
         * Takes the person back to the workspace when the inventory itself is gone (a 404 on its own address: it was deleted
         * by somebody else, or the person was taken out of the workspace), since nothing on this page means anything now.
         *
         * @param {unknown} error
         * @returns {boolean} Whether the person was sent away.
         */
        leaveIfGone(error) {
            if (!(error instanceof HttpError) || error.status !== 404) {
                return false;
            }

            redirectTo(this.workspacePage);

            return true;
        },

        /** Goes to the previous page of items. */
        previous() {
            if (this.hasPrevious) {
                this.loadItems(this.page - 1);
            }
        },

        /** Goes to the next page of items. */
        next() {
            if (this.hasNext) {
                this.loadItems(this.page + 1);
            }
        },

        /**
         * Opens the dialog that adds an item, with every control empty.
         *
         * @returns {void}
         */
        startCreate() {
            this.begin('create', null);
        },

        /**
         * Opens the dialog on the item of a row that was clicked, unless the click was on a link or a button of the row
         * (a click on the address of an item is for the address).
         *
         * @param {{ id: string }} row
         * @param {Event} event
         * @returns {void}
         */
        openFromRow(row, event) {
            if (!(event.target instanceof Element) || event.target.closest('a, button') !== null) {
                return;
            }

            this.open(row);
        },

        /**
         * Opens the dialog on an item: the form for the people who may edit, the details for the others.
         *
         * @param {{ id: string }} row
         * @returns {void}
         */
        open(row) {
            const item = this.items.find((candidate) => candidate.id === row.id);

            if (item !== undefined) {
                this.begin(this.canEdit ? 'edit' : 'view', item);
            }
        },

        /**
         * Sets the dialog up for an item (or a new one) and shows it.
         *
         * @param {'create' | 'edit' | 'view'} mode
         * @param {Item | null} item The item, null to add one.
         * @returns {void}
         */
        begin(mode, item) {
            this.resetDialog();
            this.mode = mode;
            this.itemId = item === null ? null : item.id;
            this.values = Object.fromEntries(
                this.fields.map((field) => [
                    field.id,
                    item === null ? emptyInput(field) : toInput(field, item.values?.[field.id]),
                ]),
            );
            this.original = JSON.parse(JSON.stringify(this.values));
            this.details = item === null ? [] : this.cellsOf(item, true);
            showModal(this.$refs.modal);
        },

        /**
         * Forgets the refusals, the unfinished tags and the confirmation: the dialog starts clean.
         *
         * @returns {void}
         */
        resetDialog() {
            this.errors = {};
            this.formError = '';
            this.drafts = {};
            this.confirming = false;
        },

        // The field types know how a field is entered and which choices it has (see `../fields`): the template asks
        // them through the component, with the field as the only argument.
        control: controlOf,
        inputType: inputTypeOf,
        inputMode: inputModeOf,
        maxLength: maxLengthOf,
        choices: choicesOf,

        /**
         * The `id` of the control of a field, which its label points to.
         *
         * @param {Field} field
         * @returns {string}
         */
        controlId(field) {
            return 'item-field-' + field.id;
        },

        /**
         * What the control of a field holds. (Not called `valueOf`: that would replace the method every object has, which
         * the language calls to turn an object into a primitive.)
         *
         * @param {Field} field
         * @returns {any}
         */
        inputValue(field) {
            return this.values[field.id];
        },

        /**
         * Takes what was typed or picked in the control of a field.
         *
         * @param {Field} field
         * @param {Event} event
         * @returns {void}
         */
        onInput(field, event) {
            this.set(field, /** @type {HTMLInputElement} */ (event.target).value);
        },

        /**
         * Sets what the control of a field holds, and forgets the refusal that was about it.
         *
         * @param {Field} field
         * @param {any} value
         * @returns {void}
         */
        set(field, value) {
            this.values[field.id] = value;
            delete this.errors[field.id];
        },

        /**
         * The classes of the control of a field: marked when its value was refused.
         *
         * @param {Field} field
         * @returns {string}
         */
        controlClass(field) {
            return this.errors[field.id] === undefined ? 'form-control' : 'form-control is-invalid';
        },

        /**
         * The classes of the `<select>` of a field.
         *
         * @param {Field} field
         * @returns {string}
         */
        selectClass(field) {
            return this.errors[field.id] === undefined ? 'form-select' : 'form-select is-invalid';
        },

        /**
         * The message under the control of a field, "" when its value was not refused.
         *
         * @param {Field} field
         * @returns {string}
         */
        errorOf(field) {
            return this.errors[field.id] ?? '';
        },

        /**
         * The options of the `<select>` of a field: a blank one, then the answers or the choices.
         *
         * @param {Field} field
         * @returns {{ value: string, label: string }[]}
         */
        selectOptions(field) {
            return selectOptionsOf(field, {
                blank: t('inventories.item.none'),
                yes: t('fields.boolean.yes'),
                no: t('fields.boolean.no'),
            });
        },

        /**
         * Whether an option of the `<select>` of a field is the one it holds.
         *
         * @param {Field} field
         * @param {string} value
         * @returns {boolean}
         */
        isSelected(field, value) {
            return this.values[field.id] === value;
        },

        /**
         * Whether a choice of a multiple choice is ticked.
         *
         * @param {Field} field
         * @param {string} id
         * @returns {boolean}
         */
        isChosen(field, id) {
            return Array.isArray(this.values[field.id]) && this.values[field.id].includes(id);
        },

        /**
         * Ticks or unticks a choice of a multiple choice.
         *
         * @param {Field} field
         * @param {string} id
         * @param {Event} event
         * @returns {void}
         */
        toggleChoice(field, id, event) {
            const chosen = (this.values[field.id] ?? []).filter((other) => other !== id);

            if (/** @type {HTMLInputElement} */ (event.target).checked) {
                chosen.push(id);
            }

            this.set(
                field,
                this.choices(field)
                    .map((choice) => choice.id)
                    .filter((other) => chosen.includes(other)),
            );
        },

        /**
         * The tags a field holds.
         *
         * @param {Field} field
         * @returns {string[]}
         */
        tagsOf(field) {
            return this.values[field.id] ?? [];
        },

        /**
         * What was typed in the tag box of a field and not yet added.
         *
         * @param {Field} field
         * @returns {string}
         */
        draftOf(field) {
            return this.drafts[field.id] ?? '';
        },

        /**
         * Takes what is typed in the tag box of a field.
         *
         * @param {Field} field
         * @param {Event} event
         * @returns {void}
         */
        onDraft(field, event) {
            this.drafts[field.id] = /** @type {HTMLInputElement} */ (event.target).value;
        },

        /**
         * Adds what is typed in the tag box as a tag (unless it is empty, or the field has it already, capitals not counted).
         *
         * @param {Field} field
         * @returns {void}
         */
        commitTag(field) {
            const tag = this.draftOf(field).trim();
            const known = this.tagsOf(field);

            this.drafts[field.id] = '';

            if (tag !== '' && !known.some((other) => other.toLowerCase() === tag.toLowerCase())) {
                this.set(field, [...known, tag]);
            }
        },

        /**
         * Removes a tag.
         *
         * @param {Field} field
         * @param {string} tag
         * @returns {void}
         */
        removeTag(field, tag) {
            this.set(
                field,
                this.tagsOf(field).filter((other) => other !== tag),
            );
        },

        /**
         * The accessible name of the button that removes a tag.
         *
         * @param {string} tag
         * @returns {string}
         */
        removeTagLabel(tag) {
            return t('inventories.item.remove_tag', { name: tag });
        },

        /**
         * The grades a rating can be given: 1 up to its maximum.
         *
         * @param {Field} field
         * @returns {number[]}
         */
        scale(field) {
            return Array.from({ length: Number(field.options?.max ?? 5) }, (_unused, index) => index + 1);
        },

        /**
         * Gives a grade, or takes it back when it is the one already given.
         *
         * @param {Field} field
         * @param {number} grade
         * @returns {void}
         */
        setRating(field, grade) {
            this.set(field, this.values[field.id] === grade ? null : grade);
        },

        /**
         * Whether a grade is within the rating a field holds (its star is filled).
         *
         * @param {Field} field
         * @param {number} grade
         * @returns {boolean}
         */
        isRated(field, grade) {
            return typeof this.values[field.id] === 'number' && grade <= this.values[field.id];
        },

        /**
         * The classes of the star of a grade.
         *
         * @param {Field} field
         * @param {number} grade
         * @returns {string}
         */
        starClass(field, grade) {
            return this.isRated(field, grade)
                ? 'btn btn-link p-0 fs-2 lh-1 text-yellow'
                : 'btn btn-link p-0 fs-2 lh-1 text-secondary';
        },

        /**
         * The glyph of the star of a grade.
         *
         * @param {Field} field
         * @param {number} grade
         * @returns {string}
         */
        starGlyph(field, grade) {
            return this.isRated(field, grade) ? '★' : '☆';
        },

        /**
         * The accessible name of the star of a grade.
         *
         * @param {Field} field
         * @param {number} grade
         * @returns {string}
         */
        starLabel(field, grade) {
            return t('fields.rating', { value: grade, max: this.scale(field).length });
        },

        /**
         * Gives a field its first color, black, which the picker then shows and lets the person move on from. A picker cannot
         * be empty and fires no event for the color it opens on, so black could never be chosen if the field showed the
         * picker while it had no color: a field without one shows "Set a color" instead.
         *
         * @param {Field} field
         * @returns {void}
         */
        chooseColor(field) {
            this.set(field, FIRST_COLOR);
        },

        /**
         * Whether a field holds a text (a color is emptied with its own button).
         *
         * @param {Field} field
         * @returns {boolean}
         */
        hasText(field) {
            return typeof this.values[field.id] === 'string' && this.values[field.id] !== '';
        },

        /**
         * Empties the color of a field.
         *
         * @param {Field} field
         * @returns {void}
         */
        clearValue(field) {
            this.set(field, '');
        },

        /**
         * Converts what the controls hold into what the API takes. Adding an item: every field that holds something, the
         * others are left out. Changing one: only the fields whose control holds something else than when the dialog
         * opened, and null clears a field.
         *
         * @returns {Record<string, any>}
         */
        valuesToSend() {
            const editing = this.mode === 'edit';
            const values = {};

            for (const field of this.fields) {
                if (editing && JSON.stringify(this.values[field.id]) === JSON.stringify(this.original[field.id])) {
                    continue;
                }

                const value = fromInput(field, this.values[field.id]);

                if (value !== null || editing) {
                    values[field.id] = value;
                }
            }

            return values;
        },

        /**
         * Saves the item: adds it, or sends what changed.
         *
         * @returns {Promise<void>}
         */
        async save() {
            if (this.busy || !this.isForm) {
                return;
            }

            const creating = this.mode === 'create';
            const values = this.valuesToSend();

            // Nothing was changed: there is nothing to send.
            if (!creating && Object.keys(values).length === 0) {
                hideModal(this.$refs.modal);

                return;
            }

            this.busy = true;
            this.errors = {};
            this.formError = '';

            try {
                if (creating) {
                    await http.post(this.itemsUrl, { values });
                } else {
                    await http.patch(fillUrl(this.itemUrl, this.itemId), { values });
                }

                hideModal(this.$refs.modal);
                notify('success', t(creating ? 'inventories.notice.item_added' : 'inventories.notice.item_saved'));

                if (creating) {
                    this.countItems(1);
                }

                // Newest first: a new item is at the top of the first page.
                await this.loadItems(creating ? 1 : this.page);
            } catch (error) {
                this.failChange(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Keeps the count of the items of the inventory right as one is added or deleted, before the list is read again (it
         * says the count of the whole inventory itself when nothing narrows it down, but not when a search or filters do).
         *
         * @param {number} change +1 for an item added, -1 for one deleted.
         * @returns {void}
         */
        countItems(change) {
            if (this.itemsCount !== null) {
                this.itemsCount = Math.max(0, this.itemsCount + change);
            }
        },

        /**
         * Asks for the confirmation of the deletion of the item.
         *
         * @returns {void}
         */
        askDelete() {
            this.confirming = true;
            // The button that was pressed is gone: the keyboard goes to the one that confirms.
            this.$nextTick(() => this.$refs.confirmDelete?.focus());
        },

        /**
         * Gives up deleting.
         *
         * @returns {void}
         */
        cancelDelete() {
            this.confirming = false;
        },

        /**
         * Deletes the item, once confirmed.
         *
         * @returns {Promise<void>}
         */
        async confirmDelete() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.delete(fillUrl(this.itemUrl, this.itemId));
                hideModal(this.$refs.modal);
                notify('success', t('inventories.notice.item_deleted'));
                this.countItems(-1);
                await this.loadItems(this.page);
            } catch (error) {
                this.failChange(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Tells the person why a request failed, unless the HTTP helper already did: under the inputs a 422 is about, in
         * the dialog for the refusal to go past the quota, or as a toast.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError) || error.reported) {
                return;
            }

            if (error.status === 422) {
                this.refuse(error);
            } else if (error.status === 409 && error.code === 'item_limit_reached') {
                this.formError = t('inventories.error.item_limit', { count: error.body?.limit ?? '' });
            } else {
                notify('danger', error.message);
            }
        },

        /**
         * Tells the person why a change to an item failed. An item that is gone (the helper has said so) closes the dialog and
         * reads the page again: what is shown is not what the server holds any more. An inventory that is gone sends the
         * person back to the workspace.
         *
         * @param {unknown} error
         * @returns {void}
         */
        failChange(error) {
            if (error instanceof HttpError && error.status === 404) {
                if (this.itemId === null) {
                    // Adding an item: the address that is gone is the inventory's own.
                    this.leaveIfGone(error);
                } else {
                    hideModal(this.$refs.modal);
                    // If it is the inventory that is gone, this reading finds out and takes the person away.
                    this.loadItems(this.page);
                }

                return;
            }

            this.fail(error);
        },

        /**
         * Shows the refusals of a 422: each under its field, and the others (a field that no longer exists, say) at the
         * top of the form, after reading the fields again since they are not the ones the form was built from.
         *
         * @param {HttpError} error
         * @returns {void}
         */
        refuse(error) {
            const known = new Set(this.fields.map((field) => field.id));
            const messages = firstMessages(error.errors);
            const unknown = [];
            const errors = {};

            for (const [name, message] of Object.entries(messages)) {
                const id = name.startsWith(VALUES_PREFIX) ? name.slice(VALUES_PREFIX.length) : null;

                if (id !== null && known.has(id)) {
                    errors[id] = message;
                } else {
                    unknown.push(message);
                }
            }

            this.errors = errors;
            this.formError = unknown[0] ?? (Object.keys(errors).length === 0 ? error.message : '');

            if (unknown.length > 0) {
                this.loadInventory();
            }
        },
    });
}
