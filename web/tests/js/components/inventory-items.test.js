import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { hideModal, onModalHidden, showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';

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

const INVENTORY = '/api/v1/inventories/tools';
const ITEMS = INVENTORY + '/items';

/** A field of the inventory. */
function field(id, type, options = {}, extra = {}) {
    return { id, name: 'Field ' + id, type, options, required: false, position: 0, ...extra };
}

const FIELDS = [
    field('name', 'text', { max_length: 100 }, { name: 'Name', required: true }),
    field('qty', 'number', { decimals: 2, unit: 'kg' }, { name: 'Weight' }),
    field('price', 'money', { currency: 'EUR' }, { name: 'Price' }),
    field(
        'state',
        'select',
        {
            choices: [
                { id: 'aaaaaaaa', label: 'New', color: 'green' },
                { id: 'bbbbbbbb', label: 'Used' },
            ],
        },
        { name: 'State' },
    ),
    field(
        'parts',
        'multiselect',
        {
            choices: [
                { id: 'cccccccc', label: 'Red' },
                { id: 'dddddddd', label: 'Blue' },
                { id: 'eeeeeeee', label: 'Green' },
            ],
        },
        { name: 'Parts' },
    ),
    field('fragile', 'boolean', {}, { name: 'Fragile' }),
    field('bought', 'date', {}, { name: 'Bought' }),
    field('seen', 'datetime', {}, { name: 'Seen' }),
    field('labels', 'tags', {}, { name: 'Labels' }),
    field('grade', 'rating', { max: 5 }, { name: 'Grade' }),
    field('tint', 'color', {}, { name: 'Tint' }),
];

/** An item as `ItemResource` describes it. */
function item(id, values = {}) {
    return { id, values, created_at: '2026-10-15T10:00:00Z', updated_at: '2026-10-15T10:00:00Z' };
}

/** What `GET /api/v1/inventories/{inventory}` answers. */
function inventoryAnswer(fields = FIELDS) {
    return { data: { id: 'tools', name: 'Tools', fields } };
}

/** What `GET /api/v1/inventories/{inventory}/items` answers for a page. */
function page(
    items,
    { current = 1, last = 1, total = items.length, from = items.length ? 1 : null, to = items.length || null } = {},
) {
    return { data: items, meta: { current_page: current, last_page: last, total, from, to } };
}

/** Answers the two reads of the page. */
function answer(items, fields = FIELDS, meta = {}) {
    http.get.mockImplementation(async (url) => (url === INVENTORY ? inventoryAnswer(fields) : page(items, meta)));
}

describe('the items of an inventory', () => {
    let component;
    let modal;

    beforeEach(() => {
        modal = document.createElement('div');
        component = inventoryItems();
        component.$root = {
            dataset: {
                url: INVENTORY,
                itemsUrl: ITEMS,
                itemUrl: ITEMS + '/__id__',
                workspacePage: '/app/w/shop',
                canEdit: '1',
                canManageFields: '1',
            },
        };
        component.$refs = { modal, filters: document.createElement('div'), search: document.createElement('input') };
        component.$nextTick = (callback) => callback();
        document.documentElement.lang = 'en';
        // The component keeps its search, sort and filters in the address: every test starts from a clean one.
        window.history.replaceState(null, '', '/');
    });

    afterEach(() => {
        document.documentElement.lang = 'en';
    });

    describe('reading the inventory', () => {
        it('reads the fields and the first page of items when the page opens', async () => {
            answer([item('i1', { name: 'Hammer' })]);

            const reading = component.init();

            expect(component.loading).toBe(true);

            await reading;

            expect(http.get).toHaveBeenCalledWith(INVENTORY);
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
            expect(component.fields).toHaveLength(FIELDS.length);
            expect(component.items.map((one) => one.id)).toEqual(['i1']);
            expect(component.loading).toBe(false);
            expect(component.hasTable).toBe(true);
        });

        it('forgets the dialog whenever it is closed', async () => {
            answer([]);
            await component.init();

            component.errors = { name: 'Oops.' };
            component.formError = 'Oops';
            component.confirming = true;
            component.drafts = { labels: 'half' };
            onModalHidden.mock.calls[0][1]();

            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
            expect(component.confirming).toBe(false);
            expect(component.drafts).toEqual({});
        });

        it('builds a cell for each field of each item, shown as its type shows it', async () => {
            answer([
                item('i1', {
                    name: 'Hammer',
                    qty: '1.50',
                    price: '19.90',
                    state: 'aaaaaaaa',
                    parts: ['cccccccc', 'eeeeeeee'],
                    fragile: false,
                    bought: '2026-10-15',
                    labels: ['Tool'],
                    grade: 4,
                    tint: '#aabbcc',
                }),
            ]);

            await component.init();

            const [row] = component.rows;
            const cells = Object.fromEntries(row.cells.map((cell) => [cell.key, cell]));

            expect(row.id).toBe('i1');
            expect(row.cells.map((cell) => cell.name)).toEqual(FIELDS.map((one) => one.name));
            expect(cells.name).toMatchObject({ kind: 'text', text: 'Hammer' });
            expect(cells.qty.text).toBe('1.50 kg');
            expect(cells.price.text).toBe('€19.90');
            expect(cells.state.badges).toEqual([{ text: 'New', class: 'badge bg-green-lt' }]);
            expect(cells.parts.badges.map((badge) => badge.text)).toEqual(['Red', 'Green']);
            expect(cells.fragile.badges[0].text).toBe('No');
            expect(cells.bought.text).toBe('Oct 15, 2026');
            expect(cells.labels.badges[0].text).toBe('Tool');
            expect(cells.grade.text).toBe('★★★★☆');
            expect(cells.tint.kind).toBe('swatch');
            expect(cells.seen.kind).toBe('empty');
        });

        it('tells an inventory without fields from one without items', async () => {
            answer([], []);
            await component.init();

            expect(component.noFields).toBe(true);
            expect(component.noItems).toBe(false);
            expect(component.hasTable).toBe(false);
            expect(component.canAdd).toBe(false);

            answer([]);
            await component.init();

            expect(component.noFields).toBe(false);
            expect(component.noItems).toBe(true);
            expect(component.canAdd).toBe(true);
        });

        it('does not claim an empty state while it is still reading', () => {
            expect(component.noFields).toBe(false);
            expect(component.noItems).toBe(false);
        });

        it('offers to add items to the people who may edit them, and the fields to the people who may manage them', () => {
            component.fields = FIELDS;

            expect(component.canEdit).toBe(true);
            expect(component.canAdd).toBe(true);
            expect(component.canManageFields).toBe(true);

            Object.assign(component.$root.dataset, { canEdit: '0', canManageFields: '0' });

            expect(component.canEdit).toBe(false);
            expect(component.canAdd).toBe(false);
            expect(component.canManageFields).toBe(false);
            expect(component.openLabel).toBe('Show the details of the item');
        });

        it('does not repeat what the HTTP helper already told', async () => {
            const error = new HttpError(500, 'Server error');
            error.reported = true;
            http.get.mockRejectedValue(error);

            await component.init();

            expect(notify).not.toHaveBeenCalled();
            expect(component.loading).toBe(false);
        });

        it('shows other failures as a toast', async () => {
            http.get.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'odd' }));

            await component.init();

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');
        });

        describe('when something could not be read', () => {
            const down = () => {
                const error = new HttpError(0, 'Network down', { kind: 'network' });
                error.reported = true;

                return error;
            };

            it('does not claim the inventory has no field when it could not be read', async () => {
                http.get.mockImplementation(async (url) => {
                    if (url === INVENTORY) {
                        throw down();
                    }

                    return page([item('i1')]);
                });

                await component.init();

                expect(component.inventoryFailed).toBe(true);
                expect(component.loadFailed).toBe(true);
                expect(component.noFields).toBe(false);
                expect(component.noItems).toBe(false);
                expect(component.loading).toBe(false);
            });

            it('does not claim the inventory holds no item when its items could not be read', async () => {
                http.get.mockImplementation(async (url) => {
                    if (url === ITEMS) {
                        throw down();
                    }

                    return inventoryAnswer();
                });

                await component.init();

                expect(component.itemsFailed).toBe(true);
                expect(component.inventoryFailed).toBe(false);
                expect(component.loadFailed).toBe(true);
                expect(component.noItems).toBe(false);
                expect(component.noFields).toBe(false);
                expect(component.loadingItems).toBe(false);
            });

            it('reads again what failed, and only then claims what it finds', async () => {
                http.get.mockRejectedValue(down());
                await component.init();

                expect(component.loadFailed).toBe(true);

                answer([]);
                const retrying = component.retry();

                expect(component.inventoryFailed).toBe(false);
                expect(component.itemsFailed).toBe(false);

                await retrying;

                expect(component.loadFailed).toBe(false);
                expect(component.noItems).toBe(true);
                expect(component.noFields).toBe(false);
            });

            it('asks again for the page that failed, not for the first one', async () => {
                answer([item('i1')], FIELDS, { current: 1, last: 3, total: 120, from: 1, to: 50 });
                await component.init();

                http.get.mockRejectedValueOnce(down());
                await component.loadItems(2);

                expect(component.itemsFailed).toBe(true);
                expect(component.page).toBe(1);

                answer([item('i51')], FIELDS, { current: 2, last: 3, total: 120, from: 51, to: 100 });
                await component.retry();

                expect(http.get).toHaveBeenLastCalledWith(ITEMS, { params: { page: 2 } });
                expect(component.page).toBe(2);
                expect(component.loadFailed).toBe(false);
            });

            it('does not read the inventory again when only the items failed', async () => {
                http.get.mockImplementation(async (url) => {
                    if (url === ITEMS) {
                        throw down();
                    }

                    return inventoryAnswer();
                });
                await component.init();
                http.get.mockClear();
                answer([item('i1')]);

                await component.retry();

                expect(http.get).toHaveBeenCalledTimes(1);
                expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
            });

            it('takes the person back to the workspace when the inventory itself is gone (deleted elsewhere)', async () => {
                const gone = new HttpError(404, 'This item no longer exists.');
                gone.reported = true;
                http.get.mockRejectedValue(gone);

                await component.init();

                expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
                expect(notify).not.toHaveBeenCalled();
            });

            it("does so when only the items answer 404, since they are the inventory's own", async () => {
                const gone = new HttpError(404, 'This item no longer exists.');
                gone.reported = true;
                http.get.mockImplementation(async (url) => {
                    if (url === ITEMS) {
                        throw gone;
                    }

                    return inventoryAnswer();
                });

                await component.init();

                expect(redirectTo).toHaveBeenCalledTimes(1);
                expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
            });

            it('does not send the person away for other failures', async () => {
                http.get.mockRejectedValue(down());

                await component.init();

                expect(redirectTo).not.toHaveBeenCalled();
            });

            it('forgets the failure of a page that a more recent reading made obsolete', async () => {
                answer([item('i1')]);
                await component.init();

                let failFirst;
                http.get.mockImplementationOnce(() => new Promise((_resolve, reject) => (failFirst = reject)));
                const slow = component.loadItems(2);
                http.get.mockResolvedValueOnce(
                    page([item('i9')], { current: 3, last: 3, total: 130, from: 101, to: 130 }),
                );
                await component.loadItems(3);
                failFirst(down());
                await slow;

                expect(component.itemsFailed).toBe(false);
            });
        });
    });

    describe('pages', () => {
        it('says which items are shown among all', async () => {
            answer([item('i1')], FIELDS, { current: 1, last: 3, total: 120, from: 1, to: 50 });
            await component.init();

            expect(component.rangeLabel).toBe('1–50 of 120');
            expect(component.hasPrevious).toBe(false);
            expect(component.hasNext).toBe(true);
        });

        it('says nothing when there are no items', async () => {
            answer([]);
            await component.init();

            expect(component.rangeLabel).toBe('');
        });

        it('goes to the next and to the previous page', async () => {
            answer([item('i1')], FIELDS, { current: 1, last: 3, total: 120, from: 1, to: 50 });
            await component.init();

            answer([item('i51')], FIELDS, { current: 2, last: 3, total: 120, from: 51, to: 100 });
            component.next();
            await vi.waitFor(() => expect(component.page).toBe(2));

            expect(http.get).toHaveBeenLastCalledWith(ITEMS, { params: { page: 2 } });
            expect(component.rangeLabel).toBe('51–100 of 120');
            expect(component.hasPrevious).toBe(true);

            answer([item('i1')], FIELDS, { current: 1, last: 3, total: 120, from: 1, to: 50 });
            component.previous();
            await vi.waitFor(() => expect(component.page).toBe(1));
        });

        it('does not go before the first page or past the last', async () => {
            answer([item('i1')]);
            await component.init();
            http.get.mockClear();

            component.previous();
            component.next();

            expect(http.get).not.toHaveBeenCalled();
        });

        it('drops the answer to a page that was asked for before a more recent one', async () => {
            answer([item('i1')]);
            await component.init();

            let releaseFirst;
            http.get.mockImplementationOnce(() => new Promise((resolve) => (releaseFirst = resolve)));
            const slow = component.loadItems(2);
            http.get.mockResolvedValueOnce(page([item('i9')], { current: 3, last: 3, total: 130, from: 101, to: 130 }));
            await component.loadItems(3);
            releaseFirst(page([item('i2')], { current: 2, last: 3, total: 130, from: 51, to: 100 }));
            await slow;

            expect(component.page).toBe(3);
            expect(component.items.map((one) => one.id)).toEqual(['i9']);
            expect(component.loadingItems).toBe(false);
        });

        it('goes back to the last page when items were deleted and the page asked for is past the end', async () => {
            answer([item('i1')]);
            await component.init();

            http.get.mockResolvedValueOnce(page([], { current: 3, last: 2, total: 60, from: null, to: null }));
            http.get.mockResolvedValueOnce(page([item('i51')], { current: 2, last: 2, total: 60, from: 51, to: 60 }));
            await component.loadItems(3);

            expect(http.get).toHaveBeenLastCalledWith(ITEMS, { params: { page: 2 } });
            expect(component.page).toBe(2);
            expect(component.loadingItems).toBe(false);
        });
    });

    describe('the form', () => {
        beforeEach(async () => {
            answer([
                item('i1', {
                    name: 'Hammer',
                    qty: '1.50',
                    state: 'aaaaaaaa',
                    parts: ['cccccccc'],
                    fragile: true,
                    bought: '2026-10-15',
                    seen: '2026-10-15T12:30:00Z',
                    labels: ['Tool'],
                    grade: 3,
                    tint: '#aabbcc',
                }),
            ]);
            await component.init();
        });

        it('opens empty to add an item', () => {
            component.errors = { name: 'Old.' };

            component.startCreate();

            expect(component.mode).toBe('create');
            expect(component.itemId).toBeNull();
            expect(component.modalTitle).toBe('New item');
            expect(component.isForm).toBe(true);
            expect(component.errors).toEqual({});
            expect(Object.keys(component.values)).toEqual(FIELDS.map((one) => one.id));
            expect(component.values).toMatchObject({
                name: '',
                parts: [],
                labels: [],
                grade: null,
                fragile: '',
                tint: '',
            });
            expect(showModal).toHaveBeenCalledWith(modal);
        });

        it('opens with the values of the item to change one', () => {
            component.open({ id: 'i1' });

            expect(component.mode).toBe('edit');
            expect(component.itemId).toBe('i1');
            expect(component.modalTitle).toBe('Edit the item');
            expect(component.values).toMatchObject({
                name: 'Hammer',
                qty: '1.50',
                state: 'aaaaaaaa',
                parts: ['cccccccc'],
                fragile: 'true',
                bought: '2026-10-15',
                labels: ['Tool'],
                grade: 3,
                tint: '#aabbcc',
            });
            expect(component.canDeleteItem).toBe(true);
            expect(showModal).toHaveBeenCalledWith(modal);
        });

        it('opens the details, read only, for the people who may not edit', () => {
            component.$root.dataset.canEdit = '0';

            component.open({ id: 'i1' });

            expect(component.mode).toBe('view');
            expect(component.isForm).toBe(false);
            expect(component.modalTitle).toBe('Item');
            expect(component.canDeleteItem).toBe(false);
            expect(component.details.map((detail) => detail.name)).toEqual(FIELDS.map((one) => one.name));
            expect(component.details[0]).toMatchObject({ key: 'name', kind: 'text', text: 'Hammer' });
            expect(showModal).toHaveBeenCalledWith(modal);
        });

        it('shows a long text whole in the details, and cut to one line in the table', async () => {
            const long = 'Line one\nLine two ' + 'x'.repeat(100);
            component.fields = [field('notes', 'textarea', {}, { name: 'Notes' })];
            component.items = [item('i9', { notes: long })];
            component.$root.dataset.canEdit = '0';
            component.rebuild();

            expect(component.rows[0].cells[0].text.endsWith('…')).toBe(true);
            expect(component.rows[0].cells[0].multiline).toBe(false);

            component.open({ id: 'i9' });

            expect(component.details[0]).toMatchObject({ key: 'notes', kind: 'text', text: long, multiline: true });
        });

        it('opens the item of a row that was clicked, but not when the click was on a link or a button of the row', () => {
            const cell = document.createElement('td');
            const link = document.createElement('a');
            const button = document.createElement('button');
            const inner = document.createElement('span');
            button.append(inner);
            cell.append(link, button);

            component.openFromRow({ id: 'i1' }, { target: link });
            component.openFromRow({ id: 'i1' }, { target: inner });

            expect(showModal).not.toHaveBeenCalled();

            component.openFromRow({ id: 'i1' }, { target: cell });

            expect(showModal).toHaveBeenCalledTimes(1);
        });

        it('opens nothing for an item that is no longer in the page', () => {
            component.open({ id: 'gone' });

            expect(showModal).not.toHaveBeenCalled();
        });

        describe('the controls', () => {
            beforeEach(() => {
                component.startCreate();
            });

            it('chooses a control, a kind of input and its limits for each field', () => {
                const byId = Object.fromEntries(FIELDS.map((one) => [one.id, one]));

                expect(component.control(byId.name)).toBe('input');
                expect(component.inputType(byId.name)).toBe('text');
                expect(component.maxLength(byId.name)).toBe(100);
                expect(component.maxLength(byId.qty)).toBeNull();
                expect(component.inputMode(byId.qty)).toBe('decimal');
                expect(component.inputType(byId.bought)).toBe('date');
                expect(component.inputType(byId.seen)).toBe('datetime-local');
                expect(component.control(byId.state)).toBe('select');
                expect(component.control(byId.parts)).toBe('checks');
                expect(component.control(byId.labels)).toBe('tags');
                expect(component.control(byId.grade)).toBe('rating');
                expect(component.control(byId.tint)).toBe('color');
                expect(component.controlId(byId.name)).toBe('item-field-name');
            });

            it('takes what is typed, and forgets the refusal that was about it', () => {
                const [name] = FIELDS;
                component.errors = { name: 'Required.' };

                component.onInput(name, { target: { value: 'Saw' } });

                expect(component.inputValue(name)).toBe('Saw');
                expect(component.errorOf(name)).toBe('');
            });

            it('marks a control whose value was refused', () => {
                const [name] = FIELDS;
                component.errors = { name: 'Required.' };

                expect(component.errorOf(name)).toBe('Required.');
                expect(component.controlClass(name)).toBe('form-control is-invalid');
                expect(component.selectClass(name)).toBe('form-select is-invalid');

                component.errors = {};

                expect(component.controlClass(name)).toBe('form-control');
                expect(component.selectClass(name)).toBe('form-select');
            });

            it('offers the choices of a select and the answers of a yes / no', () => {
                const state = FIELDS[3];
                const fragile = FIELDS[5];

                expect(component.selectOptions(state).map((option) => option.label)).toEqual([
                    'Not set',
                    'New',
                    'Used',
                ]);
                expect(component.selectOptions(fragile).map((option) => option.label)).toEqual([
                    'Not set',
                    'Yes',
                    'No',
                ]);

                component.onInput(state, { target: { value: 'bbbbbbbb' } });

                expect(component.isSelected(state, 'bbbbbbbb')).toBe(true);
                expect(component.isSelected(state, '')).toBe(false);
            });

            it('ticks and unticks choices, always in the order of the field', () => {
                const parts = FIELDS[4];

                component.toggleChoice(parts, 'eeeeeeee', { target: { checked: true } });
                component.toggleChoice(parts, 'cccccccc', { target: { checked: true } });

                expect(component.inputValue(parts)).toEqual(['cccccccc', 'eeeeeeee']);
                expect(component.isChosen(parts, 'cccccccc')).toBe(true);
                expect(component.isChosen(parts, 'dddddddd')).toBe(false);

                component.toggleChoice(parts, 'cccccccc', { target: { checked: false } });

                expect(component.inputValue(parts)).toEqual(['eeeeeeee']);
                expect(component.choices(parts).map((choice) => choice.label)).toEqual(['Red', 'Blue', 'Green']);
            });

            it('adds a tag typed in the box, once, whatever the capitals', () => {
                const labels = FIELDS[8];

                component.onDraft(labels, { target: { value: ' Tool ' } });
                expect(component.draftOf(labels)).toBe(' Tool ');
                component.commitTag(labels);

                expect(component.tagsOf(labels)).toEqual(['Tool']);
                expect(component.draftOf(labels)).toBe('');

                component.onDraft(labels, { target: { value: 'TOOL' } });
                component.commitTag(labels);
                component.onDraft(labels, { target: { value: '   ' } });
                component.commitTag(labels);

                expect(component.tagsOf(labels)).toEqual(['Tool']);
            });

            it('removes a tag', () => {
                const labels = FIELDS[8];
                component.set(labels, ['Tool', 'Hand']);

                component.removeTag(labels, 'Tool');

                expect(component.tagsOf(labels)).toEqual(['Hand']);
                expect(component.removeTagLabel('Hand')).toBe('Remove the tag Hand');
            });

            it('gives a grade, and takes it back when it is the one already given', () => {
                const grade = FIELDS[9];

                expect(component.scale(grade)).toEqual([1, 2, 3, 4, 5]);

                component.setRating(grade, 3);

                expect(component.inputValue(grade)).toBe(3);
                expect([1, 2, 3, 4, 5].map((n) => component.isRated(grade, n))).toEqual([
                    true,
                    true,
                    true,
                    false,
                    false,
                ]);
                expect([1, 4].map((n) => component.starGlyph(grade, n))).toEqual(['★', '☆']);
                expect(component.starClass(grade, 1)).toContain('text-yellow');
                expect(component.starClass(grade, 4)).toContain('text-secondary');
                expect(component.starLabel(grade, 4)).toBe('4 out of 5');

                component.setRating(grade, 3);

                expect(component.inputValue(grade)).toBeNull();
                expect(component.isRated(grade, 1)).toBe(false);
            });

            it('has no color picker while a field has no color, so that black can be chosen: it is given with "Set a color"', () => {
                const tint = FIELDS[10];

                // Nothing to pick from while empty: the picker opens on black and fires no event for it.
                expect(component.hasText(tint)).toBe(false);
                expect(component.inputValue(tint)).toBe('');

                component.chooseColor(tint);

                expect(component.inputValue(tint)).toBe('#000000');
                expect(component.hasText(tint)).toBe(true);

                // The picker then moves on from black.
                component.onInput(tint, { target: { value: '#ff0000' } });

                expect(component.inputValue(tint)).toBe('#ff0000');
            });

            it('clears a color, which shows "Set a color" again and is sent as nothing', () => {
                const tint = FIELDS[10];
                component.chooseColor(tint);

                component.clearValue(tint);

                expect(component.inputValue(tint)).toBe('');
                expect(component.hasText(tint)).toBe(false);
                expect(component.valuesToSend()).toEqual({});
            });
        });
    });

    describe('adding an item', () => {
        beforeEach(async () => {
            answer([]);
            await component.init();
            component.startCreate();
            http.post.mockResolvedValue({ data: item('new') });
        });

        it('sends the values that were filled in, in the form the API takes', async () => {
            const [name, qty, price, state, parts, fragile, bought, seen, labels, grade, tint] = FIELDS;
            document.documentElement.lang = 'fr';
            component.onInput(name, { target: { value: '  Hammer ' } });
            component.onInput(qty, { target: { value: '1,5' } });
            component.onInput(price, { target: { value: '1 299,90' } });
            component.onInput(state, { target: { value: 'aaaaaaaa' } });
            component.toggleChoice(parts, 'dddddddd', { target: { checked: true } });
            component.onInput(fragile, { target: { value: 'false' } });
            component.onInput(bought, { target: { value: '2026-10-15' } });
            component.onInput(seen, { target: { value: '2026-10-15T14:30' } });
            component.onDraft(labels, { target: { value: 'Tool' } });
            component.commitTag(labels);
            component.setRating(grade, 4);
            component.onInput(tint, { target: { value: '#aabbcc' } });

            await component.save();

            const [url, body] = http.post.mock.calls[0];
            expect(url).toBe(ITEMS);
            expect(body.values).toMatchObject({
                name: 'Hammer',
                qty: '1.5',
                price: '1299.90',
                state: 'aaaaaaaa',
                parts: ['dddddddd'],
                fragile: false,
                bought: '2026-10-15',
                labels: ['Tool'],
                grade: 4,
                tint: '#aabbcc',
            });
            // The moment is typed in the timezone of the browser and sent in UTC, with its offset.
            expect(body.values.seen).toMatch(/^2026-10-15T\d{2}:30:00Z$/);
        });

        it('leaves out the fields that were left empty, and keeps a `false`', async () => {
            component.onInput(FIELDS[0], { target: { value: 'Hammer' } });
            component.onInput(FIELDS[5], { target: { value: 'false' } });

            await component.save();

            expect(http.post.mock.calls[0][1]).toEqual({ values: { name: 'Hammer', fragile: false } });
        });

        it('closes the dialog, tells it worked and shows the new item at the top of the first page', async () => {
            component.onInput(FIELDS[0], { target: { value: 'Hammer' } });
            answer([item('new', { name: 'Hammer' })]);
            component.page = 3;

            await component.save();

            expect(hideModal).toHaveBeenCalledWith(modal);
            expect(notify).toHaveBeenCalledWith('success', 'Item added.');
            expect(http.get).toHaveBeenLastCalledWith(ITEMS, { params: { page: 1 } });
            expect(component.items.map((one) => one.id)).toEqual(['new']);
            expect(component.busy).toBe(false);
        });

        it('ignores a second click while the item is being sent', async () => {
            component.onInput(FIELDS[0], { target: { value: 'Hammer' } });

            await Promise.all([component.save(), component.save()]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('shows each refusal under the field it is about', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: {
                        'values.name': ['The Name is required.', 'More.'],
                        'values.qty': ['The Weight must have at most 2 decimals.'],
                    },
                }),
            );

            await component.save();

            expect(component.errors).toEqual({
                name: 'The Name is required.',
                qty: 'The Weight must have at most 2 decimals.',
            });
            expect(component.formError).toBe('');
            expect(hideModal).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('shows a refusal about a field that no longer exists at the top, and reads the fields again', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: { 'values.vanished': ['This field does not exist in this inventory.'] },
                }),
            );
            http.get.mockClear();

            await component.save();

            expect(component.formError).toBe('This field does not exist in this inventory.');
            expect(component.errors).toEqual({});
            expect(http.get).toHaveBeenCalledWith(INVENTORY);
        });

        it('shows the message of a 422 that names no field', async () => {
            http.post.mockRejectedValue(new HttpError(422, 'The given data was invalid.'));

            await component.save();

            expect(component.formError).toBe('The given data was invalid.');
        });

        it('says the quota is reached', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'item_limit_reached', body: { limit: 10000 } }),
            );

            await component.save();

            expect(component.formError).toBe('Item limit reached (10000 per inventory).');
        });

        it('takes the person back to the workspace when the inventory was deleted while the form was open', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.post.mockRejectedValue(gone);

            await component.save();

            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
            expect(component.busy).toBe(false);
        });

        it('shows other failures as a toast, but not the ones the HTTP helper already told', async () => {
            http.post.mockRejectedValueOnce(new HttpError(409, 'Conflict', { code: 'odd' }));
            await component.save();

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');

            notify.mockClear();
            const reported = new HttpError(0, 'Network down', { kind: 'network' });
            reported.reported = true;
            http.post.mockRejectedValueOnce(reported);
            await component.save();

            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('changing an item', () => {
        const STORED = {
            name: 'Hammer',
            qty: '1.50',
            state: 'aaaaaaaa',
            parts: ['cccccccc'],
            seen: '2026-10-15T12:30:45Z',
            labels: ['Tool'],
            grade: 3,
        };

        beforeEach(async () => {
            answer([item('i1', STORED)]);
            await component.init();
            http.patch.mockResolvedValue({ data: item('i1') });
        });

        it('sends only what was changed', async () => {
            component.open({ id: 'i1' });
            component.onInput(FIELDS[0], { target: { value: 'Big hammer' } });

            await component.save();

            expect(http.patch).toHaveBeenCalledWith(ITEMS + '/i1', { values: { name: 'Big hammer' } });
        });

        it('sends nothing at all, so that a moment with seconds keeps them, when nothing was changed', async () => {
            component.open({ id: 'i1' });

            await component.save();

            expect(http.patch).not.toHaveBeenCalled();
            expect(hideModal).toHaveBeenCalledWith(modal);
        });

        it('clears a field that was emptied, with null', async () => {
            component.open({ id: 'i1' });
            component.onInput(FIELDS[3], { target: { value: '' } });
            component.set(FIELDS[8], []);
            component.setRating(FIELDS[9], 3);

            await component.save();

            expect(http.patch).toHaveBeenCalledWith(ITEMS + '/i1', {
                values: { state: null, labels: null, grade: null },
            });
        });

        it('closes the dialog, tells it worked and reads the page again', async () => {
            component.open({ id: 'i1' });
            component.onInput(FIELDS[0], { target: { value: 'Big hammer' } });
            component.page = 2;
            answer([item('i1', { ...STORED, name: 'Big hammer' })], FIELDS, {
                current: 2,
                last: 2,
                total: 51,
                from: 51,
                to: 51,
            });

            await component.save();

            expect(hideModal).toHaveBeenCalledWith(modal);
            expect(notify).toHaveBeenCalledWith('success', 'Item saved.');
            expect(http.get).toHaveBeenLastCalledWith(ITEMS, { params: { page: 2 } });
        });

        it('cannot save a read-only view', async () => {
            component.$root.dataset.canEdit = '0';
            component.open({ id: 'i1' });

            await component.save();

            expect(http.patch).not.toHaveBeenCalled();
            expect(http.post).not.toHaveBeenCalled();
        });

        it('shows the refusal of a required field that was emptied under it', async () => {
            http.patch.mockRejectedValue(
                new HttpError(422, 'Invalid.', { errors: { 'values.name': ['The Name is required.'] } }),
            );
            component.open({ id: 'i1' });
            component.onInput(FIELDS[0], { target: { value: '' } });

            await component.save();

            expect(component.errors).toEqual({ name: 'The Name is required.' });
        });

        it('closes the dialog and reads the page again when the item was deleted by somebody else', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.patch.mockRejectedValue(gone);
            component.open({ id: 'i1' });
            component.onInput(FIELDS[0], { target: { value: 'Big hammer' } });
            http.get.mockClear();

            await component.save();

            expect(hideModal).toHaveBeenCalledWith(modal);
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
            expect(notify).not.toHaveBeenCalled();
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('takes the person back to the workspace when it is the inventory that was deleted', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.patch.mockRejectedValue(gone);
            component.open({ id: 'i1' });
            component.onInput(FIELDS[0], { target: { value: 'Big hammer' } });
            http.get.mockRejectedValue(gone);

            await component.save();
            await vi.waitFor(() => expect(redirectTo).toHaveBeenCalledWith('/app/w/shop'));
        });
    });

    describe('deleting an item', () => {
        beforeEach(async () => {
            answer([item('i1', { name: 'Hammer' })]);
            await component.init();
            component.open({ id: 'i1' });
            http.delete.mockResolvedValue(null);
        });

        it('puts the cursor on the button that confirms, since the one that was pressed has gone', () => {
            const confirm = { focus: vi.fn() };
            component.$refs.confirmDelete = confirm;

            component.askDelete();

            expect(confirm.focus).toHaveBeenCalledTimes(1);
        });

        it('asks for a confirmation first, which can be given up', async () => {
            component.askDelete();

            expect(component.confirming).toBe(true);
            expect(component.canDeleteItem).toBe(false);
            expect(http.delete).not.toHaveBeenCalled();

            component.cancelDelete();

            expect(component.confirming).toBe(false);
            expect(component.canDeleteItem).toBe(true);
        });

        it('deletes the item once confirmed, then reads the page again', async () => {
            component.askDelete();
            answer([]);

            await component.confirmDelete();

            expect(http.delete).toHaveBeenCalledWith(ITEMS + '/i1');
            expect(hideModal).toHaveBeenCalledWith(modal);
            expect(notify).toHaveBeenCalledWith('success', 'Item deleted.');
            expect(component.items).toEqual([]);
            expect(component.noItems).toBe(true);
        });

        it('ignores a second click while the item is being deleted', async () => {
            component.askDelete();

            await Promise.all([component.confirmDelete(), component.confirmDelete()]);

            expect(http.delete).toHaveBeenCalledTimes(1);
        });

        it('keeps the dialog open when the deletion fails', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'odd' }));
            component.askDelete();

            await component.confirmDelete();

            expect(hideModal).not.toHaveBeenCalled();
            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');
            expect(component.busy).toBe(false);
        });

        it('closes the dialog when the item was already gone', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.delete.mockRejectedValue(gone);
            component.askDelete();

            await component.confirmDelete();

            expect(hideModal).toHaveBeenCalledWith(modal);
        });
    });
});
