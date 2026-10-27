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
 * older answer never replaces a newer one. The totals of the numbers and amounts cost the server a pass over every item, so
 * they are asked for only when they can have changed: the first time, with a new search or new filters, and after an item
 * is added, changed or deleted; a page or a sort keeps the totals it has. Editing a value in its cell, without the
 * dialog, is `../grid/inline.js`; laying the items out as a table or as cards is `../grid/gallery.js`.
 *
 * The dialog of an existing item has three tabs: its details (the form, or the read-only list), its attachments and its
 * history. The tabs are `../item/tabs.js`, the files (the photo and file fields of the form, the attachments tab, the
 * uploads) `../item/files.js`, the history `../item/history.js`. Files never travel with the values of an item: each goes
 * up on its own request, and the files chosen while an item is added go up right after the form was saved.
 *
 * The toolbar's "Export" button opens a second dialog, `../export/dialog.js`: it exports the items, or the view the grid
 * holds (its `query`), in a format the person picks, and lists the exports they asked for lately. Its "Import" button
 * (for the people who may edit) opens a third, `../import/dialog.js`: a spreadsheet goes in, its columns are matched with
 * the fields (or with fields it creates), and the grid is read again when the items have come in.
 *
 * When the instance and the workspace allow it (`data-photo-entry`), the "Add from photos" button opens a dialog of its own,
 * `../photo-entry/dialog.js`: one to five photos of an object go to the analysis, the person reviews the values it proposes
 * and the form that adds an item opens with the ones they accepted, and the photos in its photo field.
 *
 * A barcode field of the form has a button that reads the code with the camera, and the search box has one too (a code
 * that no item has can then be added at once): that is `../scanner/inventory-scan.js`, which talks to the scanner panels
 * (`./barcode-scanner.js`) set in the dialog of the item and in a dialog of their own.
 *
 * The dialog cannot be closed by hand (Escape, a click outside, a button) while a request that holds it is on its way: it
 * closes itself when the request is done. A request belongs to the dialog that was open when it began (`dialogToken`): an
 * answer that finds another dialog in its place leaves it alone.
 */
import {
    choicesOf,
    controlOf,
    describeValue,
    emptyInput,
    fromInput,
    inputModeOf,
    inputRefusal,
    inputTypeOf,
    isSummable,
    maxLengthOf,
    selectOptionsOf,
    toInput,
} from '../fields/index.js';
import { firstMessages, fillUrl, revealFirstRefusal } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';
import { compose } from '../lib/compose.js';
import exportDialog from '../export/dialog.js';
import importDialog from '../import/dialog.js';
import gridCriteria from '../grid/criteria.js';
import gridInline from '../grid/inline.js';
import gridView from '../grid/gallery.js';
import { totalsKeyOf } from '../grid/query.js';
import { fieldFocusSelectors, momentText } from '../grid/rows.js';
import { hasTotals, totalCells } from '../grid/totals.js';
import itemFiles from '../item/files.js';
import itemHistory from '../item/history.js';
import itemTabs from '../item/tabs.js';
import photoEntryDialog from '../photo-entry/dialog.js';
import inventoryScan from '../scanner/inventory-scan.js';

/** The color a field gets when a person chooses to give it one: the picker then lets them move on from there. */
const FIRST_COLOR = '#000000';

/** The prefix of the names the API gives the errors of the values of an item (`values.<field ULID>`). */
const VALUES_PREFIX = 'values.';

/**
 * The fields the totals add up, as one text to tell whether they are the same ones.
 *
 * @param {import('../fields/types.js').Field[]} fields
 * @returns {string}
 */
function summedIds(fields) {
    return fields
        .filter(isSummable)
        .map((field) => field.id)
        .join();
}

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
    // What the toolbar opens (the dialogs that export the items, import some, add one from photos and scan a code for the search)
    // and the scan of the barcode fields of the item dialog, as one part.
    const dialogs = compose(exportDialog(), importDialog(), photoEntryDialog(), inventoryScan());

    return compose(gridCriteria(), gridInline(), gridView(), itemTabs(), itemFiles(), itemHistory(), dialogs, {
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

        /** True while the code itself closes the dialog, which the guard against closing it by hand lets through. */
        closingDialog: false,

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

        /**
         * The search and the filters (see `totalsKeyOf`) that `totals` were read for, null when they are not known to be right:
         * before the first reading, and after an item came, changed or went.
         */
        totalsFor: null,

        /** Ticket of the last reading of the totals alone (see `refreshTotals` in `../grid/inline.js`): an older answer is dropped. */
        totalsTicket: 0,

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

        /** The element the component is set on, kept when the page opens (see `rootElement`); null until then. */
        rootNode: null,

        /**
         * The element the component is set on, which carries the endpoints and the abilities (`data-*`). Taken when the page
         * opens rather than asked of Alpine each time: Alpine stops answering `$root` to a call made from the scope of an
         * element that has been taken out of the page since (the close button of a chip that was removed, the button of a
         * cell that turned into its control), and the reading of the list that such a call starts still needs them.
         */
        get rootElement() {
            return this.rootNode ?? this.$root;
        },

        /** Endpoint of the inventory (GET), from `data-url` on the component root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /** Endpoint of the items (GET, POST), from `data-items-url` on the component root. */
        get itemsUrl() {
            return this.rootElement.dataset.itemsUrl;
        },

        /** Endpoint of one item, with `__id__` where its ULID goes, from `data-item-url` on the component root. */
        get itemUrl() {
            return this.rootElement.dataset.itemUrl;
        },

        /** Page of the workspace the inventory belongs to, where the person goes if it was deleted, from `data-workspace-page`. */
        get workspacePage() {
            return this.rootElement.dataset.workspacePage;
        },

        /** Whether the person may add, change and delete items, from `data-can-edit` on the component root. */
        get canEdit() {
            return this.rootElement.dataset.canEdit === '1';
        },

        /** Whether the person is offered the link to the field editor, from `data-can-manage-fields` on the root. */
        get canManageFields() {
            return this.rootElement.dataset.canManageFields === '1';
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

        /** Whether the dialog offers to delete the item: while it changes one, before a deletion is asked for. */
        get canDeleteItem() {
            return this.mode === 'edit' && !this.asking;
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
         * The inventory comes first: its fields say which of them the totals add up, and the page asks for the totals along
         * with the items (a second reading just for them would run the page twice).
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.rootNode = this.$root;
            this.$nextTick(() => {
                onModalHidden(this.$refs.modal, () => this.onDialogHidden());
                // `hide.bs.modal` is Bootstrap's event, fired before it closes the dialog; preventing it keeps the dialog open.
                this.$refs.modal.addEventListener('hide.bs.modal', (event) => this.guardClosing(event));
            });

            const page = this.restoreQuery();

            this.pickView();

            await this.loadInventory();
            await this.loadItems(page);
        },

        /**
         * Stops the pause that waits for the end of the typing, when the page is taken apart (Alpine calls `destroy()` when
         * the element of the component is removed from the page, as it calls `init()` when it is set up).
         *
         * @returns {void}
         */
        destroy() {
            this.stopSearchTimer();
            this.stopExportPolling();
            this.stopImportPolling();
            this.stopPhotoEntry();
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

                const summed = summedIds(this.fields);

                this.fields = inventory.fields ?? [];
                this.itemsCount = inventory.items_count ?? this.itemsCount;

                // Other fields add up now: the totals shown are not the ones of the page the next reading asks for.
                if (summedIds(this.fields) !== summed) {
                    this.totalsFor = null;
                }

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
         * does not claim the inventory holds no item.
         *
         * The totals ride along only when they are not known to be right: they depend on the search and the filters alone,
         * so a page or a sort keeps the ones the list has. A reading whose answer is dropped (a newer one took over) leaves
         * them unknown, and the newer one asks for them.
         *
         * When the server refuses criteria (a field that was deleted since the address was shared), only the criteria it
         * names are taken out, with a toast, and the list is read again, once. The fields the server refuses to total are
         * a sign that the fields of the page are out of date: they are read again too.
         *
         * Only the latest reading counts: the answer to an older one (a search typed over, a page clicked past) is dropped,
         * whenever it comes.
         *
         * @param {number} page Number of the page to read.
         * @param {boolean} [recovered] Whether this reading is the one that follows a refusal, which is not recovered from again.
         * @returns {Promise<void>}
         */
        async loadItems(page, recovered = false) {
            const ticket = ++this.ticket;
            this.loadingItems = true;
            this.itemsFailed = false;
            this.filesChanged = false;
            this.wantedPage = page;
            this.syncAddress(page);

            const totalsKey = totalsKeyOf(this.query);
            const withTotals = this.totalsFor !== totalsKey && this.fields.some(isSummable);

            try {
                const answer = await http.get(this.itemsUrl, {
                    params: this.queryParams(page, { totals: withTotals }),
                });

                if (ticket !== this.ticket) {
                    return;
                }

                // Kept even when the page is read again just below: they do not depend on the page.
                if (withTotals) {
                    this.totals = answer.meta.totals ?? {};
                    this.totalsFor = totalsKey;
                }

                // Items were deleted since the page was shown and it is past the end now: the last one it is.
                if (answer.data.length === 0 && page > answer.meta.last_page) {
                    this.loadingItems = false;
                    await this.loadItems(answer.meta.last_page, recovered);

                    return;
                }

                this.items = answer.data;
                this.page = answer.meta.current_page;
                this.lastPage = answer.meta.last_page;
                this.total = answer.meta.total;
                this.from = answer.meta.from ?? 0;
                this.to = answer.meta.to ?? 0;
                this.loadedOnce = true;

                // The row being edited is not on this page.
                if (this.inlineRow !== null && !this.items.some((one) => one.id === this.inlineRow)) {
                    this.closeInline(false);
                }

                // Nothing narrows the list down: it holds the whole inventory.
                if (!this.hasCriteria) {
                    this.itemsCount = answer.meta.total;
                }

                this.rebuild();
            } catch (error) {
                if (ticket === this.ticket && !recovered && (await this.recoverFromRefusal(error, page))) {
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
         * Gets over the server refusing the criteria of a reading (a `422`): takes out the criteria it names and no others,
         * reads the fields again when it refused to total one, tells the person, and reads the list again.
         *
         * @param {unknown} error
         * @param {number} page The page that was asked for.
         * @returns {Promise<boolean>} Whether the list was read again; false when the refusal names nothing that can be taken out.
         */
        async recoverFromRefusal(error, page) {
            if (!(error instanceof HttpError) || error.status !== 422) {
                return false;
            }

            const { dropped, staleFields } = this.dropRefused(error.errors ?? {});

            if (!dropped && !staleFields) {
                return false;
            }

            if (staleFields) {
                await this.loadInventory();
            }

            this.totalsFor = null;
            notify('warning', t(dropped ? 'grid.notice.dropped' : 'grid.notice.fields_changed'));
            await this.loadItems(dropped ? 1 : page, true);

            return true;
        },

        /**
         * Reads again what could not be read: the inventory, and the page of items that was asked for.
         *
         * @returns {Promise<void>}
         */
        async retry() {
            // The alert goes while the page is read again, as the readings below would take it away one after the other.
            this.itemsFailed = false;

            // In this order, for the reason `init` gives.
            if (this.inventoryFailed) {
                await this.loadInventory();
            }

            await this.loadItems(this.wantedPage);
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
            this.buildCards();
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
                // The cell of a person who edits is a button, which cannot hold a link: the table puts one beside it.
                plain: this.canEdit && !full,
                ...describeValue(field, item.values?.[field.id], { full, attachments: item.attachments ?? [] }),
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
         * Opens the dialog on the item of a row that was clicked, unless the click was on a link, a button or a control of the
         * row (a click on the address of an item is for the address, one on a cell is for the cell). An `<a>` with no `href` is
         * no link (the thumbnails of the files in the table are such), so a click on it is a click on the row.
         *
         * What was clicked is read from the path the event took when it was dispatched, not from where its target is now:
         * a cell that turns into its control at that very click is gone from the page by the time the click reaches the row.
         *
         * @param {{ id: string }} row
         * @param {Event} event
         * @returns {void}
         */
        openFromRow(row, event) {
            if (!(event.target instanceof Element)) {
                return;
            }

            const path = typeof event.composedPath === 'function' ? event.composedPath() : [event.target];
            const onControl = [...path, event.target].some(
                (node) => node instanceof Element && node.matches('a[href], button, input, select, textarea'),
            );

            if (!onControl && event.target.closest('a[href], button') === null) {
                this.open(row);
            }
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
         * @param {string | null} [focusField] The id of the field the cursor goes to (the first control otherwise).
         * @returns {void}
         */
        begin(mode, item, focusField = null) {
            this.resetDialog();
            this.fillDialog(mode, item);

            if (focusField === null) {
                showModal(this.$refs.modal);
            } else {
                showModal(this.$refs.modal, { focus: fieldFocusSelectors(focusField) });
            }
        },

        /**
         * Puts an item (or nothing, to add one) in the dialog: what its controls hold, and the details it shows.
         *
         * @param {'create' | 'edit' | 'view'} mode
         * @param {Item | null} item The item, null to add one.
         * @returns {void}
         */
        fillDialog(mode, item) {
            this.mode = mode;
            this.itemId = item === null ? null : item.id;
            this.dialogItem = item;
            this.values = Object.fromEntries(
                this.fields.map((field) => [
                    field.id,
                    item === null ? emptyInput(field) : toInput(field, item.values?.[field.id]),
                ]),
            );
            this.original = JSON.parse(JSON.stringify(this.values));
            this.details = item === null ? [] : this.cellsOf(item, true);
        },

        /**
         * Forgets the refusals, the unfinished tags, the confirmation, the files, the history and the scan of a barcode: the dialog starts clean.
         *
         * @returns {void}
         */
        resetDialog() {
            this.errors = {};
            this.formError = '';
            this.drafts = {};
            this.confirming = false;
            this.resetTabs();
            this.resetFiles();
            this.resetHistory();
            this.stopFieldScan();
        },

        /**
         * Closes the dialog from the code: when a request is done with it, or what it shows is gone.
         *
         * @returns {void}
         */
        closeDialog() {
            this.closingDialog = true;

            try {
                hideModal(this.$refs.modal);
            } finally {
                this.closingDialog = false;
            }
        },

        /**
         * Keeps the dialog open while a request that holds it is on its way (the person pressed Escape, clicked outside or
         * on a button that closes it): the answer would find the form gone, and what was chosen for the item lost. The code
         * closes the dialog itself when it is done ({@link closeDialog}).
         *
         * It also keeps it open while the camera scans a barcode field: what the person closes then is the scan, and the
         * dialog goes on showing its form.
         *
         * @param {Event} event The `hide.bs.modal` event, fired before the dialog closes.
         * @returns {void}
         */
        guardClosing(event) {
            if (this.closingDialog) {
                return;
            }

            if (this.busy) {
                event.preventDefault();
            } else if (this.scanning) {
                event.preventDefault();
                this.cancelFieldScan();
            }
        },

        /**
         * The dialog has been closed, however it was: it forgets the item. The list is read again when files were added or
         * removed meanwhile and nothing has read it since, so that it shows the item as it is, whatever the filters ask.
         *
         * @returns {void}
         */
        onDialogHidden() {
            const changed = this.filesChanged;

            this.resetDialog();

            if (changed) {
                this.totalsFor = null;
                this.loadItems(this.page);
            }
        },

        // The field types know how a field is entered and which choices it has (see `../fields`): the template asks
        // them through the component, with the field as the only argument.
        control: controlOf,
        inputType: inputTypeOf,
        inputMode: inputModeOf,
        maxLength: maxLengthOf,
        choices: choicesOf,

        /**
         * The `id` of the label of a field, which a group of controls without a control of their own is named by.
         *
         * @param {Field} field
         * @returns {string}
         */
        labelId(field) {
            return 'item-label-' + field.id;
        },

        /**
         * The control a label points to (`for`): the control of the field, except for the files, whose only button (the one that
         * adds a file) is not there when the field is full, and does not stand for the field: its group is named by the label.
         *
         * @param {Field} field
         * @returns {string | null}
         */
        labelTarget(field) {
            return controlOf(field) === 'files' ? null : this.controlId(field);
        },

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
         * The fields the next save takes into account: adding an item, every field but the files; changing one, only the fields
         * whose control holds something else than when the dialog opened. The files of a field go up on their own request,
         * never with the values.
         *
         * @returns {Field[]}
         */
        fieldsToSend() {
            const editing = this.mode === 'edit';

            return this.fields.filter(
                (field) =>
                    controlOf(field) !== 'files' &&
                    !(editing && JSON.stringify(this.values[field.id]) === JSON.stringify(this.original[field.id])),
            );
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

            for (const field of this.fieldsToSend()) {
                const value = fromInput(field, this.values[field.id]);

                if (value !== null || editing) {
                    values[field.id] = value;
                }
            }

            return values;
        },

        /**
         * The messages for the controls whose content would be sent but cannot be a value of its field: text typed in a number
         * box that is no number. The API refuses those too, in words written for the people who call it ("written with a dot"
         * for a box that takes a comma): they are told here, under the control, before anything is sent.
         *
         * @returns {Record<string, string>} The message of each such field, by field id.
         */
        refusedInputs() {
            const refused = {};

            for (const field of this.fieldsToSend()) {
                const message = inputRefusal(field, this.values[field.id]);

                if (message !== null) {
                    refused[field.id] = message;
                }
            }

            return refused;
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
                this.closeDialog();

                return;
            }

            // A value that is no value of its field is not sent: it is said under the control, which is brought into view.
            const refused = this.refusedInputs();

            if (Object.keys(refused).length > 0) {
                this.errors = refused;
                this.formError = '';
                this.revealRefusal();

                return;
            }

            // The dialog this save belongs to, and the files chosen in it: the dialog can be closed and opened again on something
            // else while the requests are on their way, and what it holds (the files, the form) is not the next dialog's.
            const token = this.dialogToken;
            const staged = this.staged;

            this.busy = true;
            this.errors = {};
            this.formError = '';

            try {
                let created = null;

                if (creating) {
                    created = (await http.post(this.itemsUrl, { values }))?.data ?? null;
                    this.countItems(1);
                } else {
                    await http.patch(fillUrl(this.itemUrl, this.itemId), { values });
                }

                // Newest first: a new item is at the top of the first page. What the columns add up to has changed.
                this.totalsFor = null;

                // The item exists: the files chosen for it go up now, and the dialog stays on it while they do, so that the ones
                // that fail can be seen. When the dialog is not the one that was saved, its files are not sent anywhere.
                let failed = 0;

                if (created !== null && staged.length > 0) {
                    if (token === this.dialogToken) {
                        this.fillDialog('edit', created);
                        failed = (await this.sendStaged(created.id, token)).failed;
                    } else {
                        failed = staged.length;
                    }
                }

                if (failed === 0) {
                    // Only the dialog that was saved is closed and congratulated: it may have given way to another meanwhile.
                    if (token === this.dialogToken) {
                        this.closeDialog();
                        notify(
                            'success',
                            t(creating ? 'inventories.notice.item_added' : 'inventories.notice.item_saved'),
                        );
                    }
                } else {
                    notify('warning', tn('files.notice.partial', failed));
                }

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
                this.closeDialog();
                notify('success', t('inventories.notice.item_deleted'));
                this.countItems(-1);
                this.totalsFor = null;
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
                this.revealRefusal();
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
                    this.closeDialog();
                    // If it is the inventory that is gone, this reading finds out and takes the person away. The item is gone:
                    // so is what it added to the totals.
                    this.totalsFor = null;
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

            this.revealRefusal();
        },

        /**
         * Brings the first refusal of the dialog into view and puts the cursor on its control, once it has been rendered: in a
         * long form it can lie outside what the scrolling part of the dialog shows, and "Save" would look as if it did nothing.
         * The dialog is taken before the wait, for the same reason as the root (see `rootElement`).
         *
         * @returns {void}
         */
        revealRefusal() {
            const dialog = this.$refs.modal;

            this.$nextTick(() => revealFirstRefusal(dialog));
        },
    });
}
