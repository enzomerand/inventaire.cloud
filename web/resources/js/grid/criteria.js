/**
 * The part of the page of an inventory that narrows and orders its items: the search box, the filters panel, the sort, and
 * the address of the page that remembers all of them.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`), so `this` is the whole component: it reads its
 * `fields` and its numbers, and asks it to read a page again (`loadItems`) when the criteria change. What is asked of the
 * server lives in `query` (see `query.js`: canonical values, the same as the API takes and the address holds); the boxes
 * hold text of their own until it is applied (`searchText`, `filterDraft`, see `filters.js`).
 *
 * The search waits for a pause in the typing (a few hundred milliseconds) before it asks, and each new criteria starts again
 * from the first page. An older answer never replaces a newer one: that is for the component's `loadItems` to see.
 */
import { isSortable } from '../fields/index.js';
import { currentSearch, replaceSearch } from '../lib/location.js';
import { t, tn } from '../lib/i18n.js';
import { hideOffcanvas, showOffcanvas } from '../lib/offcanvas.js';
import {
    blankDraft,
    chipsOf,
    draftOf,
    filterCount,
    filterFromDraft,
    hasAnswer,
    hasChoiceList,
    hasRange,
    rangeInputMode,
    rangeInputType,
} from './filters.js';
import { emptyQuery, fromSearch, hasCriteria, toParams, toSearch } from './query.js';
import { CREATED, UPDATED, cycleSort, directionBy } from './sort.js';

/** How long the search waits for the next key before it asks the server, in milliseconds. */
export const SEARCH_DELAY_MS = 300;

/**
 * @typedef {import('../fields/types.js').Field} Field
 * @typedef {import('./query.js').Query} Query
 */

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function gridCriteria() {
    return {
        /** What the list is asked for now. */
        query: emptyQuery(),

        /** What the search box holds (the search it will ask for once typing pauses). */
        searchText: '',

        /** The timer of the pause that ends the typing, null when none is running. */
        searchTimer: null,

        /** The way to lay the items out that the address asks for, or that the person chose: null for the default one. */
        urlView: null,

        /** What the boxes of the filters panel hold, by field id. */
        filterDraft: {},

        /** The refusals of the boxes of the panel (`min`, `max`), by field id. */
        filterErrors: {},

        /** Whether the search box holds anything. */
        get hasSearchText() {
            return this.searchText !== '';
        },

        /** Whether the list is narrowed down by a search or by filters. */
        get hasCriteria() {
            return hasCriteria(this.query);
        },

        /** How many fields are filtered. */
        get filterCount() {
            return filterCount(this.query.filters);
        },

        /** The filters as removable chips. */
        get chips() {
            return chipsOf(this.fields, this.query.filters);
        },

        /** What the sort select offers: the newest first, the dates of the items, and the fields that can be sorted. */
        get sortOptions() {
            return [
                { key: 'default', value: '', label: t('grid.sort.default') },
                { key: CREATED, value: CREATED, label: t('grid.sort.created') },
                { key: UPDATED, value: UPDATED, label: t('grid.sort.updated') },
                ...this.fields
                    .filter(isSortable)
                    .map((field) => ({ key: field.id, value: field.id, label: field.name })),
            ];
        },

        /** Whether the list is sorted by something (the direction means nothing otherwise). */
        get isSorted() {
            return this.query.sort !== '';
        },

        /** The accessible name of the button that flips the direction of the sort. */
        get directionLabel() {
            return t(this.query.direction === 'desc' ? 'grid.sort.descending' : 'grid.sort.ascending');
        },

        /** The classes of the sort select and its direction: the table has the headers for it on a wide screen, the gallery has not. */
        get sortBarClass() {
            return 'd-sm-none';
        },

        /** Whether the sort goes from the highest to the lowest. */
        get isDescending() {
            return this.query.direction === 'desc';
        },

        /**
         * How many items match, as a sentence: "120 items", or "3 results out of 120" when a search or filters narrow the
         * list down. "" until the first page is read.
         */
        get countLabel() {
            if (!this.loadedOnce) {
                return '';
            }

            if (!this.hasCriteria) {
                return tn('inventories.items', this.total);
            }

            return tn('grid.results', this.total, { total: this.inventoryCount });
        },

        /**
         * Number of items in the whole inventory: what the inventory said, kept up to date as items come and go, or the
         * number the list answered when nothing narrows it down.
         */
        get inventoryCount() {
            return this.itemsCount ?? this.total;
        },

        /**
         * Reads the criteria from the address of the page: a view that was left (or shared) comes back as it was.
         *
         * @returns {number} The page the address asks for.
         */
        restoreQuery() {
            const restored = fromSearch(currentSearch());

            this.query = restored.query;
            this.searchText = restored.query.q;
            this.urlView = restored.view;

            return restored.page;
        },

        /**
         * The parameters that ask the API for a page of the list.
         *
         * @param {number} page
         * @returns {Record<string, string | number>}
         */
        queryParams(page) {
            return toParams(this.query, { page });
        },

        /**
         * Writes the criteria, the page and the view in the address of the page.
         *
         * @param {number} page
         * @returns {void}
         */
        syncAddress(page) {
            replaceSearch(toSearch(this.query, { page, view: this.urlView }));
        },

        /**
         * Goes back to everything, newest first: what the component does when the server refuses the criteria it was given
         * (a field that was deleted since the address was shared, say).
         *
         * @returns {void}
         */
        resetQuery() {
            this.stopSearchTimer();
            this.query = emptyQuery();
            this.searchText = '';
            this.filterDraft = {};
            this.filterErrors = {};
        },

        /**
         * Takes what is typed in the search box, and asks for it once typing pauses.
         *
         * @param {Event} event
         * @returns {void}
         */
        onSearchInput(event) {
            this.searchText = /** @type {HTMLInputElement} */ (event.target).value;
            this.stopSearchTimer();
            this.searchTimer = setTimeout(() => this.runSearch(), SEARCH_DELAY_MS);
        },

        /**
         * Asks for the search now, without waiting for a pause (the Enter key).
         *
         * @returns {void}
         */
        submitSearch() {
            this.stopSearchTimer();
            this.runSearch();
        },

        /**
         * Empties the search box and shows everything again, with the cursor back in the box.
         *
         * @returns {void}
         */
        clearSearch() {
            this.searchText = '';
            this.submitSearch();
            this.$refs.search?.focus();
        },

        /**
         * Asks for the search box's words, unless they are the ones already asked for.
         *
         * @returns {void}
         */
        runSearch() {
            this.searchTimer = null;

            const words = this.searchText.trim();

            if (words !== this.query.q) {
                this.query = { ...this.query, q: words };
                this.loadItems(1);
            }
        },

        /**
         * Stops waiting for a pause in the typing.
         *
         * @returns {void}
         */
        stopSearchTimer() {
            if (this.searchTimer !== null) {
                clearTimeout(this.searchTimer);
                this.searchTimer = null;
            }
        },

        /**
         * Opens the filters panel, its boxes holding the filters that are applied.
         *
         * @returns {void}
         */
        openFilters() {
            this.filterDraft = Object.fromEntries(
                this.fields.map((field) => [field.id, draftOf(field, this.query.filters[field.id])]),
            );
            this.filterErrors = {};
            showOffcanvas(this.$refs.filters);
        },

        /**
         * Applies what the boxes of the panel hold, unless one of them is refused, and closes the panel.
         *
         * @returns {void}
         */
        applyFilters() {
            const filters = {};
            const errors = {};

            for (const field of this.fields) {
                const result = filterFromDraft(field, this.filterDraft[field.id] ?? blankDraft());

                if (result.filter !== null) {
                    filters[field.id] = result.filter;
                }

                if (Object.keys(result.errors).length > 0) {
                    errors[field.id] = result.errors;
                }
            }

            this.filterErrors = errors;

            if (Object.keys(errors).length > 0) {
                return;
            }

            hideOffcanvas(this.$refs.filters);
            this.query = { ...this.query, filters };
            this.loadItems(1);
        },

        /**
         * Removes every filter (not the search) and closes the panel.
         *
         * @returns {void}
         */
        resetFilters() {
            hideOffcanvas(this.$refs.filters);
            this.filterDraft = {};
            this.filterErrors = {};
            this.query = { ...this.query, filters: {} };
            this.loadItems(1);
        },

        /**
         * Removes the filter of a chip.
         *
         * @param {{ fieldId: string }} chip
         * @returns {void}
         */
        removeChip(chip) {
            const filters = { ...this.query.filters };

            delete filters[chip.fieldId];
            this.query = { ...this.query, filters };
            this.loadItems(1);
        },

        /**
         * Empties the search and removes every filter: what the list shows when nothing matched.
         *
         * @returns {void}
         */
        clearCriteria() {
            this.stopSearchTimer();
            this.searchText = '';
            this.query = { ...this.query, q: '', filters: {} };
            this.loadItems(1);
        },

        /**
         * The `id` of a box of the filters panel, which its label points to.
         *
         * @param {Field} field
         * @param {string} name `min`, `max`, `is` or `presence`.
         * @returns {string}
         */
        filterId(field, name) {
            return `item-filter-${field.id}-${name}`;
        },

        /** Whether the field is filtered by a lowest and a highest value. */
        hasRange,
        /** Whether the field is filtered by a yes / no. */
        hasAnswer,
        /** Whether the field is filtered by a list of choices. */
        hasChoiceList,
        /** The `type` of the boxes of the lowest and highest value of a field. */
        rangeInputType,
        /** The `inputmode` of the boxes of the lowest and highest value of a field. */
        rangeInputMode,

        /**
         * What a box of the panel holds for a field.
         *
         * @param {Field} field
         * @param {'presence' | 'min' | 'max' | 'is'} name
         * @returns {string}
         */
        draftValue(field, name) {
            return this.filterDraft[field.id]?.[name] ?? '';
        },

        /**
         * Whether a select of the panel holds an option.
         *
         * @param {Field} field
         * @param {'presence' | 'is'} name
         * @param {string} value
         * @returns {boolean}
         */
        draftIs(field, name, value) {
            return this.draftValue(field, name) === value;
        },

        /**
         * Whether the panel asks for the items that hold nothing in a field: the boxes of its values then mean nothing.
         *
         * @param {Field} field
         * @returns {boolean}
         */
        draftEmpty(field) {
            return this.draftValue(field, 'presence') === 'empty';
        },

        /**
         * Takes what was typed or picked in a box of the panel.
         *
         * @param {Field} field
         * @param {'presence' | 'min' | 'max' | 'is'} name
         * @param {Event} event
         * @returns {void}
         */
        setDraft(field, name, event) {
            const draft = (this.filterDraft[field.id] ??= blankDraft());

            draft[name] = /** @type {HTMLInputElement} */ (event.target).value;

            if (this.filterErrors[field.id]?.[name] !== undefined) {
                delete this.filterErrors[field.id][name];
            }
        },

        /**
         * Whether a choice is ticked in the panel.
         *
         * @param {Field} field
         * @param {string} id
         * @returns {boolean}
         */
        draftHasChoice(field, id) {
            return (this.filterDraft[field.id]?.in ?? []).includes(id);
        },

        /**
         * Ticks or unticks a choice in the panel.
         *
         * @param {Field} field
         * @param {string} id
         * @param {Event} event
         * @returns {void}
         */
        toggleDraftChoice(field, id, event) {
            const draft = (this.filterDraft[field.id] ??= blankDraft());
            const others = draft.in.filter((other) => other !== id);

            draft.in = /** @type {HTMLInputElement} */ (event.target).checked ? [...others, id] : others;
        },

        /**
         * The classes of a box of the panel: marked when its value was refused.
         *
         * @param {Field} field
         * @param {'min' | 'max'} name
         * @returns {string}
         */
        filterInputClass(field, name) {
            return this.filterError(field, name) === '' ? 'form-control' : 'form-control is-invalid';
        },

        /**
         * Why a box of the panel was refused, "" when it was not.
         *
         * @param {Field} field
         * @param {'min' | 'max'} name
         * @returns {string}
         */
        filterError(field, name) {
            return this.filterErrors[field.id]?.[name] ?? '';
        },

        /**
         * The sort a click on the header of a key leads to: ascending, descending, then the newest first.
         *
         * @param {string} key `created_at`, `updated_at` or the id of a field.
         * @returns {void}
         */
        toggleSort(key) {
            this.query = { ...this.query, ...cycleSort(this.query, key) };
            this.loadItems(1);
        },

        /**
         * Sorts by what the sort select holds.
         *
         * @param {Event} event
         * @returns {void}
         */
        chooseSort(event) {
            const sort = /** @type {HTMLSelectElement} */ (event.target).value;

            this.query = { ...this.query, sort, direction: sort === '' ? 'asc' : this.query.direction };
            this.loadItems(1);
        },

        /**
         * Flips the direction of the sort.
         *
         * @returns {void}
         */
        flipDirection() {
            this.query = { ...this.query, direction: this.isDescending ? 'asc' : 'desc' };
            this.loadItems(1);
        },

        /**
         * What a header says about the sort for assistive technology: `ascending`, `descending` or `none`.
         *
         * @param {string} key
         * @returns {'ascending' | 'descending' | 'none'}
         */
        ariaSort(key) {
            const direction = directionBy(this.query, key);

            return direction === null ? 'none' : direction === 'asc' ? 'ascending' : 'descending';
        },

        /**
         * What the header of a field says about the sort for assistive technology: nothing when the field cannot be sorted.
         *
         * @param {Field} field
         * @returns {'ascending' | 'descending' | 'none' | null}
         */
        fieldAriaSort(field) {
            return isSortable(field) ? this.ariaSort(field.id) : null;
        },

        /**
         * The class that makes the header button of a key show its direction.
         *
         * @param {string} key
         * @returns {string}
         */
        sortClass(key) {
            return directionBy(this.query, key) ?? '';
        },

        /**
         * Whether the list can be sorted by a field.
         */
        isSortable,
    };
}
