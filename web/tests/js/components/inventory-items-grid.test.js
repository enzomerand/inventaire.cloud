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

/** The parameters of the last reading of the items. */
function lastParams() {
    return http.get.mock.lastCall[1].params;
}

/** Lets the pending promises run. */
const settle = () => vi.advanceTimersByTimeAsync(0);

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

            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
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

        it('starts again from everything, with a toast, when the server refuses what the address asked (a field deleted since)', async () => {
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

            expect(http.get).toHaveBeenLastCalledWith(ITEMS, { params: { page: 1 } });
            expect(notify).toHaveBeenCalledWith(
                'warning',
                'Some of the search options no longer apply: the list was reset.',
            );
            expect(component.query).toEqual({ q: '', sort: '', direction: 'asc', filters: {} });
            expect(component.searchText).toBe('');
            expect(component.itemsFailed).toBe(false);
            expect(component.items).toHaveLength(1);
            expect(window.location.search).toBe('');
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
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1, q: 'ham' } });
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
            expect(lastParams()).toEqual({ page: 1, q: 'ham' });
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
            expect(lastParams()).toEqual({ page: 1 });
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
            expect(lastParams()).toEqual({ page: 1 });
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

            expect(lastParams()).toEqual({ page: 1, q: 'saw', 'filter[fragile][is]': 'true' });

            component.resetFilters();
            await settle();

            expect(lastParams()).toEqual({ page: 1, q: 'saw' });
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

        it('is read again with every page, so that it stays right after an item is added, changed or deleted', async () => {
            answer([item('i1')], { totals: { qty: '1.00', price: '10.00' } });
            await component.init();
            http.post.mockResolvedValue({ data: item('new') });
            http.patch.mockResolvedValue({ data: item('i1') });
            http.delete.mockResolvedValue(undefined);

            answer([item('new'), item('i1')], { totals: { qty: '3.00', price: '30.00' } });
            component.startCreate();
            await component.save();
            expect(component.totalRow[2].text).toBe('€30.00');

            answer([item('new'), item('i1')], { totals: { qty: '3.00', price: '45.50' } });
            component.begin('edit', component.items[0]);
            component.set(FIELDS[2], '45,50');
            await component.save();
            expect(component.totalRow[2].text).toBe('€45.50');

            answer([item('i1')], { totals: { qty: '1.00', price: '10.00' } });
            await component.confirmDelete();
            expect(component.totalRow[2].text).toBe('€10.00');
        });
    });
});
