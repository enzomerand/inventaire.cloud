/**
 * The search, the filters, the sort and the totals of the items of an inventory, at the level of the component: what it
 * asks the API for, what it keeps in the address of the page, and what it shows of the answers.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { hideModal } from '../../../resources/js/lib/modal.js';
import { hideOffcanvas, showOffcanvas } from '../../../resources/js/lib/offcanvas.js';
import {
    FIELDS,
    INVENTORY,
    ITEMS,
    deferred,
    field,
    inventoryAnswer,
    item,
    mountComponent,
    page,
    ulid,
    withUlids,
} from '../support/inventory.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

/** Answers the two reads of the page, whatever the criteria. */
function answer(items, { fields = FIELDS, itemsCount = items.length, ...meta } = {}) {
    http.get.mockImplementation(async (url) =>
        url === INVENTORY ? inventoryAnswer(fields, itemsCount) : page(items, meta),
    );
}

/** What a reading that asks for the totals adds to its parameters: the fields of the fixture that add up. */
const ASK_TOTALS = { 'totals[0]': 'qty', 'totals[1]': 'price' };

/** The parameters of the last reading of the items. */
function lastParams() {
    return http.get.mock.lastCall[1].params;
}

/** Lets the pending promises run. */
const settle = () => vi.advanceTimersByTimeAsync(0);

/**
 * Answers like the server: the totals only come when the reading asks for them (`totals[]`).
 *
 * @param {Record<string, any>[]} items The items of the page.
 * @param {Record<string, string>} totals What the server adds up.
 * @param {Record<string, any>} [options] The fields of the inventory and the rest of `meta`.
 */
function answerLikeTheServer(items, totals, { fields = FIELDS, ...meta } = {}) {
    http.get.mockImplementation(async (url, options) => {
        if (url === INVENTORY) {
            return inventoryAnswer(fields, items.length);
        }

        const answered = page(items, meta);
        const asked = Object.keys(options.params).some((name) => name.startsWith('totals['));

        if (asked) {
            answered.meta.totals = totals;
        } else {
            delete answered.meta.totals;
        }

        return answered;
    });
}

/** Whether a reading of the items asks for the totals. */
const asksForTotals = ([, options]) => Object.keys(options?.params ?? {}).some((name) => name.startsWith('totals['));

/** A 422 as the API answers a list it refuses. */
function refused() {
    return new HttpError(422, 'The given data was invalid.', { errors: { sort: ['This field does not exist.'] } });
}

describe('the criteria of the items of an inventory', () => {
    let component;

    beforeEach(() => {
        vi.useFakeTimers();
        component = mountComponent(inventoryItems);
    });

    afterEach(() => {
        component.destroy();
        vi.useRealTimers();
        window.history.replaceState(null, '', '/');
    });

    describe('the address of the page', () => {
        it('asks for the first page of everything, newest first, when the address says nothing', async () => {
            answer([item('i1')]);

            await component.init();

            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1, ...ASK_TOTALS } });
            expect(window.location.search).toBe('');
        });

        it('brings back the page, the search, the sort and the filters it holds', async () => {
            const [PRICE, STATE, FRAGILE] = ['price', 'state', 'fragile'].map(ulid);
            window.history.replaceState(
                null,
                '',
                `/?page=2&q=ham+mer&sort=price&direction=desc&filter[${STATE}][in][]=aaaaaaaa&filter[${PRICE}][min]=10&filter[${FRAGILE}][is]=false`,
            );
            answer([item('i1')], {
                current: 2,
                last: 3,
                total: 120,
                fields: withUlids(['price', 'state', 'fragile']),
            });

            await component.init();

            expect(http.get).toHaveBeenCalledWith(ITEMS, {
                params: {
                    page: 2,
                    q: 'ham mer',
                    sort: 'price',
                    direction: 'desc',
                    [`filter[${STATE}][in][0]`]: 'aaaaaaaa',
                    [`filter[${PRICE}][min]`]: '10',
                    [`filter[${FRAGILE}][is]`]: 'false',
                    'totals[0]': 'qty',
                    'totals[1]': PRICE,
                },
            });
            expect(component.searchText).toBe('ham mer');
            expect(component.query.sort).toBe('price');
            expect(component.chips.map((chip) => chip.label)).toEqual([
                'Price: from €10.00',
                'State: New',
                'Fragile: No',
            ]);
        });

        it('leaves out a wrong value of the address on its own: the rest of the view is asked for, and nothing is reset', async () => {
            const [PRICE, STATE, FRAGILE] = ['price', 'state', 'fragile'].map(ulid);
            window.history.replaceState(
                null,
                '',
                `/?q=saw&sort=price&filter[${PRICE}][min]=cheap&filter[${PRICE}][max]=50&filter[${STATE}][in][]=NOT-A-CHOICE&filter[__proto__][min]=1&filter[${FRAGILE}][is]=true`,
            );
            answer([item('i1')], { fields: withUlids(['price', 'state', 'fragile']) });

            await component.init();

            expect(http.get).toHaveBeenCalledTimes(2);
            expect(http.get).toHaveBeenCalledWith(ITEMS, {
                params: {
                    page: 1,
                    q: 'saw',
                    sort: 'price',
                    [`filter[${PRICE}][max]`]: '50',
                    [`filter[${FRAGILE}][is]`]: 'true',
                    'totals[0]': 'qty',
                    'totals[1]': PRICE,
                },
            });
            expect(notify).not.toHaveBeenCalled();
            expect({}.min).toBeUndefined();
        });

        it('keeps the address up to date as the criteria and the page change, so that a reload or Back comes back to them', async () => {
            answer([item('i1')], { last: 3, total: 120 });
            await component.init();

            component.query = { ...component.query, q: 'saw' };
            await component.loadItems(1);
            expect(window.location.search).toBe('?q=saw');

            await component.loadItems(2);
            expect(window.location.search).toBe('?page=2&q=saw');

            component.toggleSort('price');
            await settle();
            expect(new URLSearchParams(window.location.search).get('sort')).toBe('price');
            expect(new URLSearchParams(window.location.search).has('page')).toBe(false);
        });

        it('adds no entry to the history, and clears the address when everything is shown again', async () => {
            answer([item('i1')]);
            await component.init();
            const entries = window.history.length;

            component.query = { ...component.query, q: 'saw' };
            await component.loadItems(1);
            component.clearCriteria();
            await settle();

            expect(window.history.length).toBe(entries);
            expect(window.location.search).toBe('');
        });

        it('takes out only the criteria the server refuses, with a toast, and reads the list again (a sort that is gone)', async () => {
            window.history.replaceState(null, '', '/?sort=gone&q=old&filter[gone][empty]=true&page=3');
            http.get.mockImplementation(async (url, options) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FIELDS, 1);
                }

                if (options.params.sort === 'gone') {
                    throw refused();
                }

                return page([item('i1')]);
            });

            await component.init();

            // The search stays, the page starts again (the list is not the same list), the totals are asked for again.
            expect(http.get).toHaveBeenLastCalledWith(ITEMS, { params: { page: 1, q: 'old', ...ASK_TOTALS } });
            expect(http.get.mock.calls.filter(([url]) => url === ITEMS)).toHaveLength(2);
            expect(notify).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith(
                'warning',
                'Some of the search options no longer apply: they were removed.',
            );
            expect(component.query).toEqual({ q: 'old', sort: '', direction: 'asc', filters: {} });
            expect(component.searchText).toBe('old');
            expect(component.itemsFailed).toBe(false);
            expect(component.items).toHaveLength(1);
            expect(window.location.search).toBe('?q=old');
        });

        it('does not start again in a loop when even everything is refused', async () => {
            http.get.mockImplementation(async (url) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FIELDS, 1);
                }

                throw refused();
            });

            await component.init();

            expect(http.get.mock.calls.filter(([url]) => url === ITEMS)).toHaveLength(1);
            expect(component.itemsFailed).toBe(true);
        });
    });

    describe('when the server refuses part of the criteria', () => {
        const [PRICE, STATE, FRAGILE] = ['price', 'state', 'fragile'].map(ulid);
        const FIELDS_WITH_ULIDS = withUlids(['price', 'state', 'fragile']);

        /** An address that holds a search, a sort and filters on three fields, and the page 2. */
        const ADDRESS = `/?page=2&q=saw&sort=${PRICE}&direction=desc&filter[${PRICE}][min]=10&filter[${PRICE}][max]=50&filter[${STATE}][in][]=aaaaaaaa&filter[${FRAGILE}][is]=true`;

        /**
         * Refuses the first reading of the items with these errors, and answers the next ones.
         *
         * @param {Record<string, string[]>} errors
         * @param {number} [refusals] How many readings of the items are refused.
         */
        function refuseWith(errors, refusals = 1) {
            let refused = 0;

            http.get.mockImplementation(async (url) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FIELDS_WITH_ULIDS, 1);
                }

                if (refused < refusals) {
                    refused += 1;

                    throw new HttpError(422, 'The given data was invalid.', { errors });
                }

                return page([item('i1')]);
            });
        }

        /** The readings of the items so far. */
        const readings = () =>
            http.get.mock.calls.filter(([url]) => url === ITEMS).map(([, options]) => options.params);

        beforeEach(() => {
            window.history.replaceState(null, '', ADDRESS);
        });

        it('takes out the condition it names and keeps the others of the same field, the other fields, the search and the sort', async () => {
            refuseWith({ [`filter.${PRICE}.max`]: ['This value is not valid.'] });

            await component.init();

            expect(readings()[1]).toEqual({
                page: 1,
                q: 'saw',
                sort: PRICE,
                direction: 'desc',
                [`filter[${PRICE}][min]`]: '10',
                [`filter[${STATE}][in][0]`]: 'aaaaaaaa',
                [`filter[${FRAGILE}][is]`]: 'true',
                'totals[0]': 'qty',
                'totals[1]': PRICE,
            });
            expect(component.query.filters[PRICE]).toEqual({ min: '10' });
            expect(notify).toHaveBeenCalledWith(
                'warning',
                'Some of the search options no longer apply: they were removed.',
            );
        });

        it('takes out the whole filter of a field the server says is gone, and no other', async () => {
            refuseWith({ [`filter.${STATE}`]: ['This field does not exist in this inventory.'] });

            await component.init();

            expect(Object.keys(component.query.filters).sort()).toEqual([FRAGILE, PRICE].sort());
            expect(component.query.q).toBe('saw');
            expect(component.query.sort).toBe(PRICE);
            expect(readings()).toHaveLength(2);
        });

        it('takes out a filter whatever the case the server wrote the field in', async () => {
            refuseWith({ [`filter.${PRICE.toUpperCase()}.min`]: ['This value is not valid.'] });

            await component.init();

            expect(component.query.filters[PRICE]).toEqual({ max: '50' });
        });

        it('takes out the search alone, emptying the box', async () => {
            refuseWith({ q: ['The q field must not be greater than 200 characters.'] });

            await component.init();

            expect(component.query.q).toBe('');
            expect(component.searchText).toBe('');
            expect(component.query.sort).toBe(PRICE);
            expect(component.filterCount).toBe(3);
        });

        it('takes out the sort with its direction, or the direction alone', async () => {
            refuseWith({ sort: ['This field cannot be sorted.'] });
            await component.init();

            expect(component.query.sort).toBe('');
            expect(component.query.direction).toBe('asc');
            expect(component.query.q).toBe('saw');

            http.get.mockReset();
            window.history.replaceState(null, '', ADDRESS);
            refuseWith({ direction: ['The direction must be asc or desc.'] });
            component.restoreQuery();
            await component.loadItems(2);

            expect(component.query.sort).toBe(PRICE);
            expect(component.query.direction).toBe('asc');
        });

        it('takes out everything it names at once, and tells the person once', async () => {
            refuseWith({
                q: ['Bad.'],
                sort: ['Bad.'],
                [`filter.${PRICE}.min`]: ['Bad.'],
                [`filter.${FRAGILE}`]: ['Bad.'],
            });

            await component.init();

            expect(component.query).toEqual({
                q: '',
                sort: '',
                direction: 'asc',
                filters: { [PRICE]: { max: '50' }, [STATE]: { in: ['aaaaaaaa'] } },
            });
            expect(notify).toHaveBeenCalledTimes(1);
            expect(readings()).toHaveLength(2);
        });

        it('forgets what the boxes of the filters held, which no longer agree with the filters', async () => {
            refuseWith({ [`filter.${PRICE}`]: ['Bad.'] });
            component.filterDraft = { [PRICE]: { min: '1' } };
            component.filterErrors = { [PRICE]: { min: 'Bad.' } };

            await component.init();

            expect(component.filterDraft).toEqual({});
            expect(component.filterErrors).toEqual({});
        });

        it('reads the fields again, and the list on the same page, when it refuses to total a field', async () => {
            refuseWith({ 'totals.1': ['This field does not exist in this inventory.'] });

            await component.init();

            // The criteria are right and stay; the page stays; the inventory is read again, then the list with its totals.
            expect(http.get.mock.calls.filter(([url]) => url === INVENTORY)).toHaveLength(2);
            expect(readings()[1].page).toBe(2);
            expect(readings()[1].q).toBe('saw');
            expect(readings()[1]['totals[1]']).toBe(PRICE);
            expect(component.query.sort).toBe(PRICE);
            expect(notify).toHaveBeenCalledWith(
                'warning',
                'The fields of this inventory have changed: the list was loaded again.',
            );
        });

        it('recovers once: a list that is refused again is not read a third time', async () => {
            refuseWith({ q: ['Bad.'] }, 2);

            await component.init();

            expect(readings()).toHaveLength(2);
            expect(component.itemsFailed).toBe(true);
            // Told once, when the criteria were taken out; the second refusal is the failure of the reading.
            expect(notify).toHaveBeenCalledTimes(1);
        });

        it('does not recover from a refusal that names nothing it holds', async () => {
            refuseWith({ page: ['The page must be at least 1.'] });

            await component.init();

            expect(readings()).toHaveLength(1);
            expect(component.itemsFailed).toBe(true);
            expect(component.query.q).toBe('saw');
        });

        it('drops the criteria of a refusal that comes while the person searches, not the ones they typed since', async () => {
            window.history.replaceState(null, '', '/');
            answer([item('i1')]);
            await component.init();
            http.get.mockClear();
            let refuse = true;
            http.get.mockImplementation(async (url, options) => {
                if (refuse && options.params.q === 'bad') {
                    refuse = false;

                    throw new HttpError(422, 'The given data was invalid.', { errors: { q: ['Bad.'] } });
                }

                return page([item('i1')]);
            });

            component.query = { ...component.query, q: 'bad' };
            await component.loadItems(1);

            expect(component.query.q).toBe('');
            expect(component.items).toHaveLength(1);
        });
    });

    describe('the search', () => {
        beforeEach(async () => {
            answer([item('i1')], { last: 3, total: 120 });
            await component.init();
            await component.loadItems(3);
            http.get.mockClear();
        });

        /** Types in the search box. */
        function type(text) {
            component.onSearchInput({ target: { value: text } });
        }

        it('waits for a pause in the typing, then asks once for the last words, from the first page', async () => {
            type('h');
            await vi.advanceTimersByTimeAsync(200);
            type('ha');
            await vi.advanceTimersByTimeAsync(200);
            type('ham');

            expect(http.get).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(300);

            expect(http.get).toHaveBeenCalledTimes(1);
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1, q: 'ham', ...ASK_TOTALS } });
        });

        it('keeps what is typed in the box while the reading is under way', async () => {
            type('ham');
            await vi.advanceTimersByTimeAsync(300);
            type('hamm');

            expect(component.searchText).toBe('hamm');
        });

        it('asks at once on Enter, and has nothing left to ask when the pause ends', async () => {
            type('ham');
            component.submitSearch();
            await vi.advanceTimersByTimeAsync(1000);

            expect(http.get).toHaveBeenCalledTimes(1);
            expect(lastParams()).toEqual({ page: 1, q: 'ham', ...ASK_TOTALS });
        });

        it('trims the words, and asks for nothing when they are the ones already asked', async () => {
            type('  ham  ');
            await vi.advanceTimersByTimeAsync(300);
            http.get.mockClear();

            type('ham ');
            await vi.advanceTimersByTimeAsync(300);
            type('   ');
            await vi.advanceTimersByTimeAsync(300);

            expect(http.get).toHaveBeenCalledTimes(1);
            expect(lastParams()).toEqual({ page: 1, ...ASK_TOTALS });
        });

        it('empties the box, shows everything again and puts the cursor back in the box', async () => {
            type('ham');
            component.submitSearch();
            await settle();
            http.get.mockClear();
            const focus = vi.spyOn(component.$refs.search, 'focus');

            component.clearSearch();
            await settle();

            expect(component.searchText).toBe('');
            expect(component.hasSearchText).toBe(false);
            expect(lastParams()).toEqual({ page: 1, ...ASK_TOTALS });
            expect(focus).toHaveBeenCalled();
        });

        it('stops waiting when the page is taken apart', async () => {
            type('ham');
            component.destroy();
            await vi.advanceTimersByTimeAsync(1000);

            expect(http.get).not.toHaveBeenCalled();
        });
    });

    describe('only the latest reading counts', () => {
        it('drops the answer to an older reading that comes last', async () => {
            answer([item('first')]);
            await component.init();
            const slow = deferred();
            const fast = deferred();
            http.get.mockReset();
            http.get.mockReturnValueOnce(slow.promise).mockReturnValueOnce(fast.promise);

            component.query = { ...component.query, q: 'a' };
            const older = component.loadItems(1);
            component.query = { ...component.query, q: 'ab' };
            const newer = component.loadItems(1);

            fast.resolve(page([item('for-ab')]));
            await newer;
            slow.resolve(page([item('for-a')]));
            await older;

            expect(component.items.map((one) => one.id)).toEqual(['for-ab']);
            expect(component.loadingItems).toBe(false);
        });

        it('drops the failure of an older reading too', async () => {
            answer([item('first')]);
            await component.init();
            const slow = deferred();
            http.get.mockReset();
            http.get.mockReturnValueOnce(slow.promise).mockResolvedValueOnce(page([item('newer')]));

            const older = component.loadItems(1);
            await component.loadItems(1);
            slow.reject(new HttpError(500, 'Server down'));
            await older;

            expect(component.items.map((one) => one.id)).toEqual(['newer']);
            expect(component.itemsFailed).toBe(false);
        });

        it('keeps the list while a new reading is under way, and only the first one shows a spinner in its place', async () => {
            const first = deferred();
            http.get.mockImplementation((url) =>
                url === INVENTORY ? Promise.resolve(inventoryAnswer(FIELDS, 1)) : first.promise,
            );

            const opening = component.init();
            await settle();

            expect(component.initialLoading).toBe(true);
            expect(component.refreshing).toBe(false);
            expect(component.countLabel).toBe('');

            first.resolve(page([item('i1')]));
            await opening;
            const second = deferred();
            http.get.mockImplementation(() => second.promise);

            const again = component.loadItems(1);

            expect(component.initialLoading).toBe(false);
            expect(component.refreshing).toBe(true);
            expect(component.rows).toHaveLength(1);
            expect(component.hasTable).toBe(true);
            expect(component.busyAttribute).toBe('true');

            second.resolve(page([]));
            await again;

            expect(component.refreshing).toBe(false);
            expect(component.busyAttribute).toBe('false');
        });
    });

    describe('the sort', () => {
        beforeEach(async () => {
            answer([item('i1')]);
            await component.init();
            http.get.mockClear();
        });

        it('goes ascending, descending, then back to the newest first, as a header is clicked', async () => {
            component.toggleSort('price');
            await settle();
            expect(lastParams()).toEqual({ page: 1, sort: 'price' });
            expect(component.ariaSort('price')).toBe('ascending');
            expect(component.sortClass('price')).toBe('asc');

            component.toggleSort('price');
            await settle();
            expect(lastParams()).toEqual({ page: 1, sort: 'price', direction: 'desc' });
            expect(component.ariaSort('price')).toBe('descending');
            expect(component.sortClass('price')).toBe('desc');

            component.toggleSort('price');
            await settle();
            expect(lastParams()).toEqual({ page: 1 });
            expect(component.ariaSort('price')).toBe('none');
            expect(component.sortClass('price')).toBe('');
        });

        it('goes back to the first page whenever the sort changes', async () => {
            component.toggleSort('created_at');
            await settle();

            expect(lastParams().page).toBe(1);
            expect(lastParams().sort).toBe('created_at');
        });

        it('sorts by what the select holds, and flips the direction with the button', async () => {
            component.chooseSort({ target: { value: 'updated_at' } });
            await settle();
            expect(lastParams()).toEqual({ page: 1, sort: 'updated_at' });
            expect(component.isSorted).toBe(true);
            expect(component.directionLabel).toBe('Ascending order');

            component.flipDirection();
            await settle();
            expect(lastParams()).toEqual({ page: 1, sort: 'updated_at', direction: 'desc' });
            expect(component.isDescending).toBe(true);
            expect(component.directionLabel).toBe('Descending order');
        });

        it('goes back to the newest first when the select is put back on it', async () => {
            component.chooseSort({ target: { value: 'price' } });
            component.flipDirection();
            await settle();

            component.chooseSort({ target: { value: '' } });
            await settle();

            expect(lastParams()).toEqual({ page: 1 });
            expect(component.isSorted).toBe(false);
            expect(component.query.direction).toBe('asc');
        });

        it('offers the newest first, the dates, and the fields that hold one value', () => {
            expect(component.sortOptions.map((option) => option.label)).toEqual([
                'Newest first',
                'Date added',
                'Last modified',
                'Name',
                'Weight',
                'Price',
                'State',
                'Fragile',
                'Bought',
                'Seen',
                'Grade',
                'Tint',
            ]);
            expect(new Set(component.sortOptions.map((option) => option.key)).size).toBe(component.sortOptions.length);
        });

        it('says nothing about the sort of a field that cannot be sorted', () => {
            const parts = FIELDS.find((one) => one.id === 'parts');
            const name = FIELDS.find((one) => one.id === 'name');

            expect(component.isSortable(parts)).toBe(false);
            expect(component.fieldAriaSort(parts)).toBeNull();
            expect(component.fieldAriaSort(name)).toBe('none');
        });
    });

    describe('the filters', () => {
        beforeEach(async () => {
            answer([item('i1')]);
            await component.init();
            http.get.mockClear();
        });

        it('leaves a field the server cannot query out of the panel, and drops a filter on it when the panel is applied', async () => {
            const hologram = field('holo', 'hologram');
            component.fields = [...FIELDS, hologram];
            component.query = { ...component.query, filters: { holo: { empty: true }, price: { min: '5' } } };

            expect(component.filterableFields.map((one) => one.id)).toEqual(FIELDS.map((one) => one.id));
            expect(component.sortOptions.map((option) => option.key)).not.toContain('holo');
            expect(component.isSortable(hologram)).toBe(false);
            expect(component.fieldAriaSort(hologram)).toBeNull();

            component.openFilters();
            component.applyFilters();
            await settle();

            expect(lastParams()).toEqual({ page: 1, 'filter[price][min]': '5', ...ASK_TOTALS });
        });

        it('opens the panel with its boxes holding the filters in force', async () => {
            component.query = { ...component.query, filters: { price: { min: '89.90' }, state: { in: ['bbbbbbbb'] } } };

            component.openFilters();

            expect(showOffcanvas).toHaveBeenCalledWith(component.$refs.filters);
            expect(component.draftValue(FIELDS[2], 'min')).toBe('89.90');
            expect(component.draftHasChoice(FIELDS[3], 'bbbbbbbb')).toBe(true);
            expect(component.draftHasChoice(FIELDS[3], 'aaaaaaaa')).toBe(false);
            expect(component.draftValue(FIELDS[0], 'presence')).toBe('');
        });

        it('applies what the boxes hold, from the first page, and closes the panel', async () => {
            const [, qty, price, state, , fragile] = FIELDS;
            component.openFilters();

            component.setDraft(price, 'min', { target: { value: '89,90' } });
            component.setDraft(price, 'max', { target: { value: '120' } });
            component.setDraft(qty, 'presence', { target: { value: 'empty' } });
            component.toggleDraftChoice(state, 'aaaaaaaa', { target: { checked: true } });
            component.setDraft(fragile, 'is', { target: { value: 'true' } });
            component.applyFilters();
            await settle();

            expect(lastParams()).toEqual({
                page: 1,
                'filter[qty][empty]': 'true',
                'filter[price][min]': '89.90',
                'filter[price][max]': '120',
                'filter[state][in][0]': 'aaaaaaaa',
                'filter[fragile][is]': 'true',
                ...ASK_TOTALS,
            });
            expect(hideOffcanvas).toHaveBeenCalledWith(component.$refs.filters);
            expect(component.filterCount).toBe(4);
            expect(component.hasCriteria).toBe(true);
            expect(window.location.search).toContain('filter%5Bprice%5D%5Bmin%5D=89.90');
        });

        it('keeps the panel open, asks for nothing, and says which box is wrong when one is refused', async () => {
            const price = FIELDS[2];
            component.openFilters();
            component.setDraft(price, 'min', { target: { value: 'cheap' } });

            component.applyFilters();
            await settle();

            expect(http.get).not.toHaveBeenCalled();
            expect(hideOffcanvas).not.toHaveBeenCalled();
            expect(component.filterError(price, 'min')).toBe('Enter a number with at most 6 decimals.');
            expect(component.filterInputClass(price, 'min')).toBe('form-control is-invalid');
            expect(component.filterInputClass(price, 'max')).toBe('form-control');

            component.setDraft(price, 'min', { target: { value: '5' } });

            expect(component.filterError(price, 'min')).toBe('');
        });

        it('forgets a box that was typed in and not applied when the panel is opened again', async () => {
            const price = FIELDS[2];
            component.openFilters();
            component.setDraft(price, 'min', { target: { value: '5' } });

            component.openFilters();

            expect(component.draftValue(price, 'min')).toBe('');
        });

        it('disables the boxes of a field that is asked to hold no value', () => {
            const price = FIELDS[2];
            component.openFilters();

            expect(component.draftEmpty(price)).toBe(false);

            component.setDraft(price, 'presence', { target: { value: 'empty' } });

            expect(component.draftEmpty(price)).toBe(true);
            expect(component.draftIs(price, 'presence', 'empty')).toBe(true);
        });

        it('removes one filter when its chip is closed, and all of them with Reset', async () => {
            component.query = { ...component.query, q: 'saw', filters: { price: { min: '5' }, fragile: { is: true } } };

            component.removeChip(component.chips[0]);
            await settle();

            expect(lastParams()).toEqual({ page: 1, q: 'saw', 'filter[fragile][is]': 'true', ...ASK_TOTALS });

            component.resetFilters();
            await settle();

            expect(lastParams()).toEqual({ page: 1, q: 'saw', ...ASK_TOTALS });
            expect(hideOffcanvas).toHaveBeenCalled();
            expect(component.filterCount).toBe(0);
        });

        it('clears the search and the filters together', async () => {
            component.query = { ...component.query, q: 'saw', sort: 'price', filters: { price: { min: '5' } } };
            component.searchText = 'saw';

            component.clearCriteria();
            await settle();

            expect(lastParams()).toEqual({ page: 1, sort: 'price' });
            expect(component.searchText).toBe('');
            expect(component.hasCriteria).toBe(false);
        });

        /**
         * Puts what the page draws for the chips in the document (the close button of each chip, the button of the
         * filters), as Alpine would, and lets a removal take its chip away before the cursor is placed.
         *
         * @param {string[]} fieldIds The fields that are filtered, in the order of the chips.
         * @returns {{ closers: HTMLButtonElement[], filtersButton: HTMLButtonElement }}
         */
        function drawChips(fieldIds) {
            const filtersButton = document.createElement('button');
            const closers = fieldIds.map((fieldId) => {
                const closer = document.createElement('button');

                closer.dataset.chipRemove = fieldId;

                return closer;
            });

            document.body.replaceChildren(filtersButton, ...closers);
            component.$root.replaceChildren();
            component.$root.append(...closers);
            document.body.append(component.$root);
            component.$refs.filtersButton = filtersButton;
            component.query = {
                ...component.query,
                filters: Object.fromEntries(fieldIds.map((fieldId) => [fieldId, { empty: true }])),
            };
            component.$nextTick = (callback) => {
                // The page draws again before the callback runs: the chip that was removed is gone.
                for (const closer of [...component.$root.querySelectorAll('[data-chip-remove]')]) {
                    if (component.query.filters[closer.dataset.chipRemove] === undefined) {
                        closer.remove();
                    }
                }

                callback();
            };

            return { closers, filtersButton };
        }

        it('puts the cursor on the chip that takes the place of the one that was removed', async () => {
            const { closers } = drawChips(['name', 'qty', 'price']);

            component.removeChip(component.chips[1]);
            await settle();

            expect(document.activeElement).toBe(closers[2]);
        });

        it('puts the cursor on the chip before it when the last one was removed', async () => {
            const { closers } = drawChips(['name', 'qty', 'price']);

            component.removeChip(component.chips[2]);
            await settle();

            expect(document.activeElement).toBe(closers[1]);
        });

        it('puts the cursor on the button of the filters when the only chip was removed', async () => {
            const { filtersButton } = drawChips(['price']);

            component.removeChip(component.chips[0]);
            await settle();

            expect(document.activeElement).toBe(filtersButton);
        });

        it('puts the cursor on the title of the list when the only chip was removed and the toolbar is gone with it', async () => {
            const { filtersButton } = drawChips(['price']);
            const title = document.createElement('h2');

            title.tabIndex = -1;
            document.body.append(title);
            component.$refs.itemsTitle = title;
            // An inventory with no item: nothing is left to narrow down once the filter is gone, so the toolbar is hidden.
            component.itemsCount = 0;
            http.get.mockResolvedValue(page([], { total: 0 }));

            component.removeChip(component.chips[0]);
            await settle();

            expect(component.showToolbar).toBe(false);
            expect(document.activeElement).toBe(title);
            expect(document.activeElement).not.toBe(filtersButton);
        });

        it('puts the cursor in the search box when the search and the filters are cleared', async () => {
            drawChips(['price']);
            document.body.append(component.$refs.search);
            component.query = { ...component.query, q: 'saw' };

            component.clearCriteria();
            await settle();

            expect(document.activeElement).toBe(component.$refs.search);
        });

        it('has a chip for each filter, each with a name for its close button', () => {
            component.query = {
                ...component.query,
                filters: { price: { min: '5', max: '9' }, name: { empty: false } },
            };

            expect(component.chips.map((chip) => chip.label)).toEqual(['Name: not empty', 'Price: €5.00 – €9.00']);
            expect(component.chips[0].removeLabel).toBe('Remove the filter Name: not empty');
        });
    });

    describe('how many items match', () => {
        it('says how many items the inventory holds when nothing narrows the list down', async () => {
            answer([item('i1')], { total: 120, last: 3 });

            await component.init();

            expect(component.countLabel).toBe('120 items');
        });

        it('says one item, not "1 items"', async () => {
            answer([item('i1')], { total: 1 });

            await component.init();

            expect(component.countLabel).toBe('1 item');
        });

        it('says how many match out of how many the inventory holds when a search or filters narrow it down', async () => {
            answer([item('i1')], { itemsCount: 120, total: 3 });
            window.history.replaceState(null, '', '/?q=saw');

            await component.init();

            expect(component.countLabel).toBe('3 results out of 120');
        });

        it('says one result, not "1 results"', async () => {
            answer([item('i1')], { itemsCount: 120, total: 1, fields: withUlids(['price']) });
            window.history.replaceState(null, '', `/?filter[${ulid('price')}][min]=5`);

            await component.init();

            expect(component.countLabel).toBe('1 result out of 120');
        });

        it('keeps the count of the inventory when a search narrows it down, and reads it again when it does not', async () => {
            answer([item('i1')], { itemsCount: 120, total: 120 });
            await component.init();

            answer([item('i1')], { itemsCount: 120, total: 3 });
            component.query = { ...component.query, q: 'saw' };
            await component.loadItems(1);

            expect(component.inventoryCount).toBe(120);

            answer([item('i1')], { itemsCount: 120, total: 119 });
            component.query = { ...component.query, q: '' };
            await component.loadItems(1);

            expect(component.inventoryCount).toBe(119);
        });

        it('counts the items added and deleted while a search narrows the list down', async () => {
            answer([item('i1')], { itemsCount: 120, total: 3 });
            window.history.replaceState(null, '', '/?q=saw');
            await component.init();
            http.post.mockResolvedValue({ data: item('new') });
            http.delete.mockResolvedValue(undefined);

            component.startCreate();
            component.values = { name: 'Saw' };
            await component.save();
            expect(component.inventoryCount).toBe(121);

            component.itemId = 'i1';
            await component.confirmDelete();
            expect(component.inventoryCount).toBe(120);
            expect(hideModal).toHaveBeenCalled();
        });

        it('tells an empty inventory from one that holds items and a list that nothing matches', async () => {
            answer([], { itemsCount: 0, total: 0 });
            await component.init();

            expect(component.noItems).toBe(true);
            expect(component.noMatch).toBe(false);
            expect(component.showToolbar).toBe(false);
        });

        it('says that nothing matches, and keeps the search and the filters at hand, in an inventory that holds items', async () => {
            answer([], { itemsCount: 120, total: 0 });
            window.history.replaceState(null, '', '/?q=nothing');

            await component.init();

            expect(component.noItems).toBe(false);
            expect(component.noMatch).toBe(true);
            expect(component.showToolbar).toBe(true);
            expect(component.countLabel).toBe('0 results out of 120');
        });

        it('says "No item yet" for a search on an inventory that holds nothing', async () => {
            answer([], { itemsCount: 0, total: 0 });
            window.history.replaceState(null, '', '/?q=saw');

            await component.init();

            expect(component.noItems).toBe(true);
            expect(component.noMatch).toBe(false);
        });

        it('does not claim there is no match, nor no item, when the list could not be read', async () => {
            http.get.mockImplementation(async (url) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FIELDS, 5);
                }

                throw new HttpError(500, 'Server down');
            });
            window.history.replaceState(null, '', '/?q=saw');

            await component.init();

            expect(component.noItems).toBe(false);
            expect(component.noMatch).toBe(false);
            expect(component.showToolbar).toBe(false);
        });
    });

    describe('the totals', () => {
        const TOTALS = { qty: '12.5', price: '1234.5' };

        it('shows the sum of each number and amount, formatted like its column, under the table', async () => {
            answer([item('i1', { qty: '2.50', price: '10.00' })], { totals: TOTALS });

            await component.init();

            expect(component.showTotals).toBe(true);
            expect(component.totalRow.map((cell) => cell.text)).toEqual([
                '',
                '12.50 kg',
                '€1,234.50',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ]);
            expect(component.totalsLabel).toBe('Total');
        });

        it('calls them the total of the selection when a search or filters narrow the list down', async () => {
            answer([item('i1')], { totals: TOTALS });
            window.history.replaceState(null, '', '/?q=saw');

            await component.init();

            expect(component.totalsLabel).toBe('Selection total');
        });

        it('stays out of the way when no column adds up, or when there are no rows', async () => {
            answer([item('i1')], { fields: [FIELDS[0], FIELDS[3]], totals: {} });
            await component.init();
            expect(component.showTotals).toBe(false);

            answer([], { totals: TOTALS });
            await component.loadItems(1);
            expect(component.showTotals).toBe(false);
        });

        it('copes with an answer that carries no totals', async () => {
            http.get.mockImplementation(async (url) =>
                url === INVENTORY
                    ? inventoryAnswer(FIELDS, 1)
                    : { data: [item('i1')], meta: { current_page: 1, last_page: 1, total: 1, from: 1, to: 1 } },
            );

            await component.init();

            expect(component.totals).toEqual({});
            expect(component.showTotals).toBe(false);
        });

        it('is read again after an item is added, changed or deleted, so that it stays right', async () => {
            answer([item('i1')], { totals: { qty: '1.00', price: '10.00' } });
            await component.init();
            http.post.mockResolvedValue({ data: item('new') });
            http.patch.mockResolvedValue({ data: item('i1') });
            http.delete.mockResolvedValue(undefined);

            answer([item('new'), item('i1')], { totals: { qty: '3.00', price: '30.00' } });
            component.startCreate();
            await component.save();
            expect(component.totalRow[2].text).toBe('€30.00');
            expect(lastParams()).toEqual({ page: 1, ...ASK_TOTALS });

            answer([item('new'), item('i1')], { totals: { qty: '3.00', price: '45.50' } });
            component.begin('edit', component.items[0]);
            component.set(FIELDS[2], '45,50');
            await component.save();
            expect(component.totalRow[2].text).toBe('€45.50');
            expect(lastParams()).toEqual({ page: 1, ...ASK_TOTALS });

            answer([item('i1')], { totals: { qty: '1.00', price: '10.00' } });
            await component.confirmDelete();
            expect(component.totalRow[2].text).toBe('€10.00');
            expect(lastParams()).toEqual({ page: 1, ...ASK_TOTALS });
        });

        it('is asked for with the first reading, for the fields that add up and no other', async () => {
            answerLikeTheServer([item('i1')], TOTALS);

            await component.init();

            expect(http.get).toHaveBeenCalledWith(ITEMS, {
                params: { page: 1, 'totals[0]': 'qty', 'totals[1]': 'price' },
            });
            expect(component.totals).toEqual(TOTALS);
            expect(component.totalRow[1].text).toBe('12.50 kg');
        });

        it('is kept, not asked for again, when the page or the sort changes', async () => {
            answerLikeTheServer([item('i1')], TOTALS, { last: 3, total: 120 });
            await component.init();
            http.get.mockClear();

            await component.loadItems(2);
            component.toggleSort('qty');
            await settle();
            component.flipDirection();
            await settle();
            component.chooseSort({ target: { value: 'price' } });
            await settle();

            expect(http.get.mock.calls.filter(([url]) => url === ITEMS)).toHaveLength(4);
            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(0);
            expect(component.totals).toEqual(TOTALS);
            expect(component.totalRow[1].text).toBe('12.50 kg');
            expect(component.showTotals).toBe(true);
        });

        it('is asked for again when the search or the filters change, and only then', async () => {
            answerLikeTheServer([item('i1')], TOTALS);
            await component.init();
            http.get.mockClear();

            component.onSearchInput({ target: { value: 'saw' } });
            await vi.advanceTimersByTimeAsync(300);
            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(1);

            // Another page, another sort: the same search and filters, the same totals.
            await component.loadItems(2);
            component.toggleSort('qty');
            await settle();
            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(1);

            component.openFilters();
            component.setDraft(FIELDS[2], 'min', { target: { value: '5' } });
            component.applyFilters();
            await settle();
            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(2);

            component.removeChip(component.chips[0]);
            await settle();
            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(3);
            expect(http.get.mock.calls.filter(([url]) => url === ITEMS)).toHaveLength(5);
        });

        it('does not depend on the order the filters were written in', async () => {
            answerLikeTheServer([item('i1')], TOTALS);
            await component.init();
            component.query = { ...component.query, filters: { price: { min: '5' }, fragile: { is: true } } };
            await component.loadItems(1);
            http.get.mockClear();

            component.query = { ...component.query, filters: { fragile: { is: true }, price: { min: '5' } } };
            await component.loadItems(1);

            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(0);
        });

        it('is never asked for by an inventory with no field that adds up', async () => {
            answerLikeTheServer([item('i1')], {}, { fields: [FIELDS[0], FIELDS[3]] });

            await component.init();
            component.onSearchInput({ target: { value: 'saw' } });
            await vi.advanceTimersByTimeAsync(300);

            expect(http.get.mock.calls.filter(([url]) => url === ITEMS)).toHaveLength(2);
            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(0);
            expect(component.showTotals).toBe(false);
        });

        it('is asked for by the next reading when the one that carried it was dropped for a newer one', async () => {
            answerLikeTheServer([item('i1')], TOTALS, { last: 3, total: 120 });
            await component.init();
            const first = deferred();
            http.get.mockImplementationOnce(async () => first.promise);

            // A search whose answer is slow, then a page of the same search: the second must carry the totals.
            component.onSearchInput({ target: { value: 'saw' } });
            await vi.advanceTimersByTimeAsync(300);
            await component.loadItems(2);
            first.resolve(page([item('old')], { totals: { qty: '999', price: '999' } }));
            await settle();

            expect(asksForTotals(http.get.mock.lastCall)).toBe(true);
            expect(component.totals).toEqual(TOTALS);
            expect(component.items[0].id).toBe('i1');
        });

        it('is asked for again when the inventory is read again and other fields add up', async () => {
            answerLikeTheServer([item('i1')], TOTALS);
            await component.init();
            http.get.mockClear();

            answerLikeTheServer([item('i1')], { qty: '1' }, { fields: [FIELDS[0], FIELDS[1]] });
            await component.loadInventory();
            await component.loadItems(1);

            expect(lastParams()).toEqual({ page: 1, 'totals[0]': 'qty' });
            expect(component.totals).toEqual({ qty: '1' });
        });

        it('is read once for a page past the end, not twice', async () => {
            let reads = 0;
            http.get.mockImplementation(async (url, options) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FIELDS, 1);
                }

                reads += 1;

                return options.params.page > 1
                    ? page([], { current: 3, last: 1, total: 0, totals: TOTALS })
                    : page([item('i1')], { totals: TOTALS });
            });
            window.history.replaceState(null, '', '/?page=4');

            await component.init();

            expect(reads).toBe(2);
            expect(http.get.mock.calls.filter(asksForTotals)).toHaveLength(1);
            expect(component.totals).toEqual(TOTALS);
        });
    });
});
