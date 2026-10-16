/**
 * Editing a value in its cell, at the level of the component: what it keeps while a cell is edited, what it sends, and
 * what it does with the answer.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { showModal } from '../../../resources/js/lib/modal.js';
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

const NOTES = field('notes', 'textarea', {}, { name: 'Notes' });
const ALL = [...FIELDS, NOTES];

/** What a reading that asks for the totals adds to its parameters: the fields of the fixture that add up. */
const ASK_TOTALS = { 'totals[0]': 'qty', 'totals[1]': 'price' };

const HAMMER = item('i1', { name: 'Hammer', qty: '1.50', price: '19.90', state: 'aaaaaaaa', fragile: false });
const SAW = item('i2', { name: 'Saw', price: '10.00' });

/** The answer of the API to a change of an item: the item as it is now. */
function changed(original, values) {
    return { data: { ...original, values: { ...original.values, ...values } } };
}

describe('editing a cell in place', () => {
    let component;

    /** Opens the page with two items; the totals are those of a list of 2. */
    async function open(options = {}) {
        component = mountComponent(inventoryItems, options);
        http.get.mockImplementation(async (url) =>
            url === INVENTORY
                ? inventoryAnswer(ALL, 2)
                : page([HAMMER, SAW], { totals: { qty: '1.50', price: '29.90' } }),
        );
        await component.init();
        http.get.mockClear();
    }

    /** The cell of a field in the row of an item. */
    const cellOf = (rowIndex, fieldId) => component.rows[rowIndex].cells.find((cell) => cell.key === fieldId);

    /** Starts editing a cell. */
    const edit = (rowIndex, fieldId) => component.startInline(component.rows[rowIndex], cellOf(rowIndex, fieldId));

    beforeEach(async () => {
        vi.useFakeTimers();
        await open();
    });

    afterEach(() => {
        component.destroy();
        vi.useRealTimers();
    });

    describe('starting', () => {
        it('turns a cell into the control of its field, holding the value as a box takes it', async () => {
            await edit(0, 'price');

            expect(component.isEditing(component.rows[0], cellOf(0, 'price'))).toBe(true);
            expect(component.isEditing(component.rows[1], cellOf(1, 'price'))).toBe(false);
            expect(component.inlineControl).toBe('input');
            expect(component.inlineInput).toBe('19.90');
            expect(component.inlineLabel).toBe('Price');
            expect(component.inlineMode).toBe('decimal');
            expect(showModal).not.toHaveBeenCalled();
        });

        it('shows a text with the limit of its length, and a date as a date box', async () => {
            await edit(0, 'name');
            expect(component.inlineType).toBe('text');
            expect(component.inlineMax).toBe(100);

            await edit(0, 'bought');
            expect(component.inlineType).toBe('date');
            expect(component.inlineMax).toBeNull();
        });

        it('shows a yes / no and a choice as a select, holding what the item holds', async () => {
            await edit(0, 'fragile');
            expect(component.inlineControl).toBe('select');
            expect(component.inlineInput).toBe('false');
            expect(component.inlineOptions.map((option) => option.label)).toEqual(['Not set', 'Yes', 'No']);

            await edit(0, 'state');
            expect(component.inlineInput).toBe('aaaaaaaa');
            expect(component.inlineOptions.map((option) => option.label)).toEqual(['Not set', 'New', 'Used']);
        });

        it('edits one cell at a time: another one replaces the one that is edited', async () => {
            await edit(0, 'price');
            await edit(1, 'name');

            expect(component.isEditing(component.rows[0], cellOf(0, 'price'))).toBe(false);
            expect(component.isEditing(component.rows[1], cellOf(1, 'name'))).toBe(true);
            expect(component.inlineInput).toBe('Saw');
            expect(http.patch).not.toHaveBeenCalled();
        });

        it('leaves the cell alone that is edited already', async () => {
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '25' } });

            await edit(0, 'price');

            expect(component.inlineInput).toBe('25');
        });

        it.each([
            ['notes', 'a long text'],
            ['parts', 'several choices'],
            ['labels', 'tags'],
            ['grade', 'a rating'],
            ['tint', 'a color'],
        ])('opens the dialog of the item on the field for %s (%s), which a cell cannot hold', async (fieldId) => {
            await edit(0, fieldId);

            expect(component.inlineRow).toBeNull();
            expect(component.mode).toBe('edit');
            expect(component.itemId).toBe('i1');
            expect(showModal).toHaveBeenCalledWith(component.$refs.modal, {
                focus: [
                    `[data-field="${fieldId}"] :is(input:not([type="hidden"]):not([type="color"]), select, textarea)`,
                    `[data-field="${fieldId}"] button:not(.btn-close)`,
                ],
            });
        });

        it('gives people who may not edit no control, and no button', async () => {
            await open({ canEdit: false });

            await edit(0, 'price');

            expect(component.inlineRow).toBeNull();
            expect(component.cellsOf(HAMMER)[0].plain).toBe(false);
        });

        it('marks the cells of an editor as plain, so that a link is put beside the button, and the details as not', () => {
            expect(component.cellsOf(HAMMER).every((cell) => cell.plain)).toBe(true);
            expect(component.cellsOf(HAMMER, true).some((cell) => cell.plain)).toBe(false);
        });
    });

    describe('what the button of a cell is called', () => {
        it('names the field and what the cell holds', () => {
            expect(component.editCellLabel(cellOf(0, 'name'))).toBe('Edit Name: Hammer');
            expect(component.editCellLabel(cellOf(0, 'price'))).toBe('Edit Price: €19.90');
            expect(component.editCellLabel(cellOf(0, 'state'))).toBe('Edit State: New');
            expect(component.editCellLabel(cellOf(0, 'fragile'))).toBe('Edit Fragile: No');
        });

        it('says "Not set" for a cell that holds nothing, and reads a rating as a grade', async () => {
            expect(component.editCellLabel(cellOf(1, 'qty'))).toBe('Edit Weight: Not set');

            component.items = [item('i3', { grade: 4, parts: ['cccccccc', 'dddddddd'] })];
            component.rebuild();

            expect(component.editCellLabel(component.rows[0].cells.find((cell) => cell.key === 'grade'))).toBe(
                'Edit Grade: 4 out of 5',
            );
            expect(component.editCellLabel(component.rows[0].cells.find((cell) => cell.key === 'parts'))).toBe(
                'Edit Parts: Red, Blue',
            );
        });
    });

    describe('saving', () => {
        it('sends the one value that changed, as the dialog would, and shows the item as the answer has it', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, { price: '25.50' }));
            await edit(0, 'price');

            component.onInlineInput({ target: { value: '25,50' } });
            await component.commitInline('enter');

            expect(http.patch).toHaveBeenCalledWith(ITEMS + '/i1', { values: { price: '25.50' } });
            expect(component.inlineRow).toBeNull();
            expect(component.items[0].values.price).toBe('25.50');
            expect(cellOf(0, 'price').text).toBe('€25.50');
            expect(component.inlineSaving).toBe(false);
        });

        it('keeps the rows where they were: an item is not moved by being changed, whatever the sort', async () => {
            component.query = { ...component.query, sort: 'price' };
            http.patch.mockResolvedValue(changed(HAMMER, { price: '1.00' }));
            await edit(0, 'price');

            component.onInlineInput({ target: { value: '1' } });
            await component.commitInline('blur');

            expect(component.rows.map((row) => row.id)).toEqual(['i1', 'i2']);
        });

        it('clears the field with null when the control is emptied, as the dialog does', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, { qty: undefined }));
            await edit(0, 'qty');
            component.onInlineInput({ target: { value: '' } });

            await component.commitInline('enter');

            expect(http.patch).toHaveBeenCalledWith(ITEMS + '/i1', { values: { qty: null } });
        });

        it('sends a choice, a yes / no and a date in the form the API takes', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, {}));

            await edit(0, 'fragile');
            component.onInlineInput({ target: { value: 'true' } });
            await component.commitInline('enter');
            expect(http.patch).toHaveBeenLastCalledWith(ITEMS + '/i1', { values: { fragile: true } });

            await edit(0, 'state');
            component.onInlineInput({ target: { value: '' } });
            await component.commitInline('enter');
            expect(http.patch).toHaveBeenLastCalledWith(ITEMS + '/i1', { values: { state: null } });

            await edit(0, 'bought');
            component.onInlineInput({ target: { value: '2026-10-15' } });
            await component.commitInline('enter');
            expect(http.patch).toHaveBeenLastCalledWith(ITEMS + '/i1', { values: { bought: '2026-10-15' } });
        });

        it('sends nothing, and just ends the editing, when the value was not changed', async () => {
            await edit(0, 'price');

            await component.commitInline('blur');

            expect(http.patch).not.toHaveBeenCalled();
            expect(component.inlineRow).toBeNull();
        });

        it('sends nothing after Escape, and forgets what was typed', async () => {
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '99' } });

            component.cancelInline();
            await component.commitInline('blur');

            expect(http.patch).not.toHaveBeenCalled();
            expect(component.inlineRow).toBeNull();
            expect(component.items[0].values.price).toBe('19.90');
        });

        it('does not send twice when Enter and the cursor leaving come one after the other', async () => {
            const sent = deferred();
            http.patch.mockReturnValue(sent.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '5' } });

            const first = component.commitInline('enter');
            await component.commitInline('blur');
            sent.resolve(changed(HAMMER, { price: '5.00' }));
            await first;

            expect(http.patch).toHaveBeenCalledTimes(1);
        });

        it('disables the control while the value is on its way', async () => {
            const sent = deferred();
            http.patch.mockReturnValue(sent.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '5' } });

            const saving = component.commitInline('enter');

            expect(component.inlineSaving).toBe(true);

            sent.resolve(changed(HAMMER, { price: '5.00' }));
            await saving;

            expect(component.inlineSaving).toBe(false);
        });

        it('waits for the value on its way before another cell is edited', async () => {
            const sent = deferred();
            http.patch.mockReturnValue(sent.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '5' } });
            const saving = component.commitInline('blur');

            const next = edit(1, 'name');
            await Promise.resolve();
            expect(component.inlineRow).toBe('i1');

            sent.resolve(changed(HAMMER, { price: '5.00' }));
            await saving;
            await next;

            expect(component.inlineRow).toBe('i2');
            expect(component.inlineFieldId).toBe('name');
        });
    });

    describe('when the server refuses the value', () => {
        const refusal = () =>
            new HttpError(422, 'The given data was invalid.', {
                errors: { 'values.price': ['The Price must be a number.'] },
            });

        it('says why under the control, which stays so that the value can be fixed', async () => {
            http.patch.mockRejectedValue(refusal());
            await edit(0, 'price');
            component.onInlineInput({ target: { value: 'abc' } });

            await component.commitInline('blur');

            expect(component.inlineError).toBe('The Price must be a number.');
            expect(component.inlineClass).toBe('form-control form-control-sm is-invalid');
            expect(component.inlineInvalid).toBe('true');
            expect(component.inlineRow).toBe('i1');
            expect(component.inlineInput).toBe('abc');
            expect(component.inlineSaving).toBe(false);
            expect(component.items[0].values.price).toBe('19.90');
        });

        it('forgets the refusal as soon as the value is typed again, and saves the fixed value', async () => {
            http.patch.mockRejectedValueOnce(refusal()).mockResolvedValueOnce(changed(HAMMER, { price: '12.00' }));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: 'abc' } });
            await component.commitInline('enter');

            component.onInlineInput({ target: { value: '12' } });
            expect(component.inlineError).toBe('');
            expect(component.inlineInvalid).toBeNull();

            await component.commitInline('enter');

            expect(http.patch).toHaveBeenLastCalledWith(ITEMS + '/i1', { values: { price: '12' } });
            expect(component.inlineRow).toBeNull();
        });

        it('gives up the value that was refused when another cell is edited, and opens that cell', async () => {
            http.patch.mockRejectedValue(refusal());
            await edit(0, 'price');
            component.onInlineInput({ target: { value: 'abc' } });
            await component.commitInline('blur');

            await edit(1, 'name');

            expect(component.inlineRow).toBe('i2');
            expect(component.inlineFieldId).toBe('name');
            expect(component.inlineInput).toBe('Saw');
            expect(component.inlineError).toBe('');
            expect(component.items[0].values.price).toBe('19.90');
            expect(cellOf(0, 'price').text).toBe('€19.90');
            // The person had seen the message under the control: no need to say it again.
            expect(notify).not.toHaveBeenCalled();
        });

        it('says that the value was not saved when the refusal comes after the click on another cell', async () => {
            const sent = deferred();
            http.patch.mockReturnValue(sent.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: 'abc' } });
            const saving = component.commitInline('blur');

            const next = edit(1, 'name');
            sent.reject(refusal());
            await saving;
            await next;

            expect(notify).toHaveBeenCalledWith(
                'warning',
                'The new value of Price was not saved: The Price must be a number.',
            );
            expect(component.inlineRow).toBe('i2');
            expect(component.inlineFieldId).toBe('name');
            expect(component.inlineError).toBe('');
        });

        it('points the control to the message that says why its value was refused', async () => {
            http.patch.mockRejectedValue(refusal());
            await edit(0, 'price');
            expect(component.inlineDescribedBy).toBeNull();

            component.onInlineInput({ target: { value: 'abc' } });
            await component.commitInline('blur');

            expect(component.inlineDescribedBy).toBe('item-inline-error');

            component.onInlineInput({ target: { value: '1' } });

            expect(component.inlineDescribedBy).toBeNull();
        });

        it('shows the message of the server when it names no field of the item', async () => {
            http.patch.mockRejectedValue(new HttpError(422, 'Invalid.', { errors: { values: ['Unknown field.'] } }));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '1' } });

            await component.commitInline('blur');

            expect(component.inlineError).toBe('Unknown field.');
        });
    });

    describe('when the control is closed before the answer comes', () => {
        const refusal = () =>
            new HttpError(422, 'The given data was invalid.', {
                errors: { 'values.price': ['The Price must be a number.'] },
            });

        /** Starts saving a price, and leaves the request pending. */
        async function sendPrice() {
            const sent = deferred();
            http.patch.mockReturnValue(sent.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: 'abc' } });

            return { sent, saving: component.commitInline('blur') };
        }

        const NOT_SAVED = 'The new value of Price was not saved: The Price must be a number.';

        it('says which field was refused, by a toast, when the view changed meanwhile', async () => {
            const { sent, saving } = await sendPrice();

            component.chooseView('gallery');
            expect(component.inlineRow).toBeNull();

            sent.reject(refusal());
            await saving;

            expect(notify).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('warning', NOT_SAVED);
            expect(component.inlineError).toBe('');
            expect(component.inlineRow).toBeNull();
            expect(component.inlineSaving).toBe(false);
        });

        it('says which field was refused when the page changed meanwhile and the row is no longer there', async () => {
            const { sent, saving } = await sendPrice();

            http.get.mockResolvedValue(page([SAW]));
            await component.loadItems(1);
            expect(component.inlineRow).toBeNull();

            sent.reject(refusal());
            await saving;

            expect(notify).toHaveBeenCalledWith('warning', NOT_SAVED);
            expect(component.inlineError).toBe('');
        });

        it('says which field was refused with the message of the server for the item when it names no field', async () => {
            const { sent, saving } = await sendPrice();
            component.chooseView('gallery');

            sent.reject(new HttpError(422, 'Invalid.', { errors: { values: ['Unknown field.'] } }));
            await saving;

            expect(notify).toHaveBeenCalledWith('warning', 'The new value of Price was not saved: Unknown field.');
        });

        it('says which field failed for any other answer of the server', async () => {
            const { sent, saving } = await sendPrice();
            component.chooseView('gallery');

            sent.reject(new HttpError(500, 'Server down'));
            await saving;

            expect(notify).toHaveBeenCalledWith('warning', 'The new value of Price was not saved: Server down');
        });

        it('does not repeat a toast the HTTP helper has already shown for the answer', async () => {
            const { sent, saving } = await sendPrice();
            component.chooseView('gallery');
            const down = new HttpError(500, 'Server down');
            down.reported = true;

            sent.reject(down);
            await saving;

            expect(notify).not.toHaveBeenCalled();
        });

        it('has nothing to say about a request that was cancelled', async () => {
            const { sent, saving } = await sendPrice();
            component.chooseView('gallery');

            sent.reject(new DOMException('Aborted', 'AbortError'));
            await saving;

            expect(notify).not.toHaveBeenCalled();
        });

        it('still opens the cell that is edited next when the item turns out to be gone', async () => {
            const { sent, saving } = await sendPrice();
            component.chooseView('gallery');
            // The person comes back to the table and starts another cell once the value on its way has been answered.
            component.chooseView('table');
            const next = edit(1, 'name');

            sent.reject(new HttpError(404, 'This item no longer exists.'));
            await saving;
            await next;

            expect(notify).toHaveBeenCalledWith('warning', 'This item no longer exists.');
            expect(component.inlineRow).toBe('i2');
            expect(component.inlineFieldId).toBe('name');
        });
    });

    describe('when the item is gone', () => {
        it('ends the editing, reads the list again, and tells the person if the helper did not', async () => {
            http.patch.mockRejectedValue(new HttpError(404, 'This item no longer exists.'));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '1' } });

            await component.commitInline('blur');

            expect(notify).toHaveBeenCalledWith('warning', 'This item no longer exists.');
            expect(component.inlineRow).toBeNull();
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
        });

        it('does not tell the person twice when the helper did', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.patch.mockRejectedValue(gone);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '1' } });

            await component.commitInline('blur');

            expect(notify).not.toHaveBeenCalled();
            expect(http.get).toHaveBeenCalled();
        });
    });

    describe('when the request fails otherwise', () => {
        it('leaves the control as it is, to try again, and tells the person if the helper did not', async () => {
            http.patch.mockRejectedValue(new HttpError(409, 'Conflict.'));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '1' } });

            await component.commitInline('blur');

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict.');
            expect(component.inlineRow).toBe('i1');
            expect(component.inlineSaving).toBe(false);
        });

        it('does not repeat a failure the helper has reported', async () => {
            const down = new HttpError(500, 'Server down');
            down.reported = true;
            http.patch.mockRejectedValue(down);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '1' } });

            await component.commitInline('blur');

            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('the totals', () => {
        it('are read again when the field adds up, without replacing the rows', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, { price: '30.00' }));
            http.get.mockResolvedValue(
                // The list as it is now: another order, another count. Only the totals and the count are taken from it.
                page([SAW, HAMMER], { total: 2, totals: { qty: '1.50', price: '40.00' } }),
            );
            const before = component.rows;
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });

            await component.commitInline('enter');

            expect(http.get).toHaveBeenCalledTimes(1);
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1, ...ASK_TOTALS } });
            expect(component.totalRow.find((cell) => cell.key === 'price').text).toBe('€40.00');
            expect(component.rows.map((row) => row.id)).toEqual(['i1', 'i2']);
            expect(component.items.map((one) => one.id)).toEqual(['i1', 'i2']);
            expect(component.rows).not.toBe(before);
        });

        it('are read with the criteria and the page in force', async () => {
            component.query = { ...component.query, q: 'ham' };
            http.patch.mockResolvedValue(changed(HAMMER, { qty: '3.00' }));
            http.get.mockResolvedValue(page([HAMMER], { total: 1, totals: { qty: '3.00', price: '19.90' } }));
            await edit(0, 'qty');
            component.onInlineInput({ target: { value: '3' } });

            await component.commitInline('enter');

            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1, q: 'ham', ...ASK_TOTALS } });
            expect(component.total).toBe(1);
            expect(component.totalsLabel).toBe('Selection total');
        });

        it('keep the count of the inventory when nothing narrows the list down', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, { price: '30.00' }));
            http.get.mockResolvedValue(page([HAMMER, SAW], { total: 3, totals: { price: '40.00' } }));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });

            await component.commitInline('enter');

            expect(component.inventoryCount).toBe(3);
        });

        it.each([
            ['a search', { q: 'ham' }, { page: 1, q: 'ham', ...ASK_TOTALS }],
            [
                'a filter',
                { filters: { [ulid('state')]: { in: ['aaaaaaaa'] } } },
                { page: 1, [`filter[${ulid('state')}][in][0]`]: 'aaaaaaaa', ...ASK_TOTALS },
            ],
        ])(
            'are read again, for the count too, when %s narrows the list down, whatever the field: an edit can change what matches',
            async (_name, criteria, params) => {
                component.query = { ...component.query, ...criteria };
                http.patch.mockResolvedValue(changed(HAMMER, { name: 'Big hammer' }));
                http.get.mockResolvedValue(page([SAW], { total: 1, totals: { qty: '', price: '10.00' } }));
                await edit(0, 'name');
                component.onInlineInput({ target: { value: 'Big hammer' } });

                await component.commitInline('enter');

                expect(http.get).toHaveBeenCalledTimes(1);
                expect(http.get).toHaveBeenCalledWith(ITEMS, { params });
                expect(component.total).toBe(1);
                expect(component.totalRow.find((cell) => cell.key === 'price').text).toBe('€10.00');
                // The rows stay where they are, whatever the answer says.
                expect(component.rows.map((row) => row.id)).toEqual(['i1', 'i2']);
                expect(cellOf(0, 'name').text).toBe('Big hammer');
            },
        );

        it('keep the count of the inventory when it is a search that narrows the list down', async () => {
            component.itemsCount = 7;
            component.query = { ...component.query, q: 'ham' };
            http.patch.mockResolvedValue(changed(HAMMER, { name: 'Big hammer' }));
            http.get.mockResolvedValue(page([HAMMER], { total: 1 }));
            await edit(0, 'name');
            component.onInlineInput({ target: { value: 'Big hammer' } });

            await component.commitInline('enter');

            expect(component.total).toBe(1);
            expect(component.inventoryCount).toBe(7);
        });

        it('are not read again for a sort alone, which changes no item that matches', async () => {
            component.query = { ...component.query, sort: 'name' };
            http.patch.mockResolvedValue(changed(HAMMER, { name: 'Big hammer' }));
            await edit(0, 'name');
            component.onInlineInput({ target: { value: 'Big hammer' } });

            await component.commitInline('enter');

            expect(http.get).not.toHaveBeenCalled();
        });

        it('are not read again for a field that does not add up', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, { name: 'Big hammer' }));
            await edit(0, 'name');
            component.onInlineInput({ target: { value: 'Big hammer' } });

            await component.commitInline('enter');

            expect(http.get).not.toHaveBeenCalled();
            expect(cellOf(0, 'name').text).toBe('Big hammer');
        });

        it('are dropped when a newer reading of the list was asked for in the meantime', async () => {
            const refresh = deferred();
            http.patch.mockResolvedValue(changed(HAMMER, { price: '30.00' }));
            http.get.mockReturnValueOnce(refresh.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });
            const saving = component.commitInline('enter');
            await vi.advanceTimersByTimeAsync(0);

            http.get.mockResolvedValueOnce(page([HAMMER, SAW], { totals: { qty: '1.50', price: '99.00' } }));
            await component.loadItems(1);
            refresh.resolve(page([HAMMER, SAW], { totals: { qty: '1.50', price: '1.00' } }));
            await saving;

            expect(component.totalRow.find((cell) => cell.key === 'price').text).toBe('€99.00');
        });

        it('leave the value saved when they cannot be read', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, { price: '30.00' }));
            http.get.mockRejectedValue(new HttpError(500, 'Server down'));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });

            await component.commitInline('enter');

            expect(cellOf(0, 'price').text).toBe('€30.00');
            expect(component.inlineRow).toBeNull();
        });
    });

    describe('when the list is read again meanwhile', () => {
        it('ends the editing of a row that is no longer on the page', async () => {
            await edit(0, 'price');
            http.get.mockResolvedValue(page([SAW]));

            await component.loadItems(1);

            expect(component.inlineRow).toBeNull();
        });

        it('keeps the editing of a row that is still there', async () => {
            await edit(0, 'price');
            http.get.mockResolvedValue(page([HAMMER, SAW]));

            await component.loadItems(1);

            expect(component.inlineRow).toBe('i1');
        });
    });
    describe('when a reading of the list is under way as the value is saved', () => {
        /** Saves a price of the first row with the request left pending, then starts reading the list. */
        async function saveWhileReading() {
            const patch = deferred();
            const staleReading = deferred();
            http.patch.mockReturnValue(patch.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });
            const saving = component.commitInline('enter');

            // The reading starts before the value is committed: whatever it answers, it answers the list as it was.
            http.get.mockReturnValueOnce(staleReading.promise);
            const reading = component.loadItems(1);

            return { patch, staleReading, saving, reading };
        }

        it('drops its answer, which would put the old value back, and reads the list again', async () => {
            const { patch, staleReading, saving, reading } = await saveWhileReading();
            const fresh = changed(HAMMER, { price: '30.00' }).data;
            http.get.mockResolvedValueOnce(page([fresh, SAW], { totals: { qty: '1.50', price: '40.00' } }));

            patch.resolve(changed(HAMMER, { price: '30.00' }));
            await vi.advanceTimersByTimeAsync(0);
            staleReading.resolve(page([HAMMER, SAW], { totals: { qty: '1.50', price: '29.90' } }));
            await saving;
            await reading;

            expect(cellOf(0, 'price').text).toBe('€30.00');
            expect(component.items[0].values.price).toBe('30.00');
            expect(component.totalRow.find((cell) => cell.key === 'price').text).toBe('€40.00');
            expect(component.loadingItems).toBe(false);
            expect(component.inlineSaving).toBe(false);
        });

        it('reads the list again with the criteria in force and the page it was moving to, with the totals', async () => {
            component.query = { ...component.query, q: 'saw' };
            const { patch, staleReading, saving, reading } = await saveWhileReading();
            http.get.mockResolvedValueOnce(page([SAW], { totals: { qty: '', price: '10.00' } }));

            patch.resolve(changed(HAMMER, { price: '30.00' }));
            await vi.advanceTimersByTimeAsync(0);
            staleReading.resolve(page([HAMMER], {}));
            await saving;
            await reading;

            const readings = http.get.mock.calls.filter(([url]) => url === ITEMS);

            expect(readings).toHaveLength(2);
            expect(readings[1][1].params).toEqual({ page: 1, q: 'saw', ...ASK_TOTALS });
            expect(component.items.map((one) => one.id)).toEqual(['i2']);
        });

        it('does not read the list twice for the totals as well', async () => {
            const { patch, staleReading, saving, reading } = await saveWhileReading();
            http.get.mockResolvedValue(page([HAMMER, SAW], { totals: { qty: '1.50', price: '40.00' } }));

            patch.resolve(changed(HAMMER, { price: '30.00' }));
            await vi.advanceTimersByTimeAsync(0);
            staleReading.resolve(page([HAMMER, SAW], {}));
            await saving;
            await reading;

            expect(http.get.mock.calls.filter(([url]) => url === ITEMS)).toHaveLength(2);
        });

        it('leaves a cell the person started editing meanwhile open, on the item as the list says it is now', async () => {
            const { patch, staleReading, saving, reading } = await saveWhileReading();
            http.get.mockResolvedValueOnce(page([changed(HAMMER, { price: '30.00' }).data, SAW], {}));

            patch.resolve(changed(HAMMER, { price: '30.00' }));
            await vi.advanceTimersByTimeAsync(0);
            const next = edit(1, 'price');
            staleReading.resolve(page([HAMMER, SAW], {}));
            await saving;
            await reading;
            await next;

            expect(component.inlineRow).toBe('i2');
            expect(component.inlineFieldId).toBe('price');
            expect(component.items[0].values.price).toBe('30.00');
        });
    });

    describe('starting a cell while a value is still on its way', () => {
        it('opens it on the item as the list holds it once the value was answered, not as it was when the click came', async () => {
            const patch = deferred();
            http.patch.mockReturnValue(patch.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });
            const saving = component.commitInline('enter');
            await vi.advanceTimersByTimeAsync(0);

            const click = edit(0, 'qty');
            // The answer of the server brings the item as it is now, with another weight than the one the click saw.
            patch.resolve(changed(HAMMER, { price: '30.00', qty: '9.00' }));
            await saving;
            await click;

            expect(component.inlineRow).toBe('i1');
            expect(component.inlineFieldId).toBe('qty');
            expect(component.inlineOriginal).toBe('9.00');
            expect(component.inlineInput).toBe('9.00');
        });

        it('does not open at all when the item is no longer on the page', async () => {
            const patch = deferred();
            http.patch.mockReturnValue(patch.promise);
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });
            const saving = component.commitInline('enter');
            await vi.advanceTimersByTimeAsync(0);

            const click = edit(0, 'qty');
            // The list is read again meanwhile, and the item is gone from it.
            component.items = [SAW];
            patch.resolve(changed(HAMMER, { price: '30.00' }));
            await saving;
            await click;

            expect(component.inlineRow).toBeNull();
            expect(showModal).not.toHaveBeenCalled();
        });
    });

    describe('reading the totals again', () => {
        it('takes the position of the page too, with the count', async () => {
            http.patch.mockResolvedValue(changed(HAMMER, { price: '30.00' }));
            http.get.mockResolvedValue(
                page([HAMMER, SAW], { total: 120, last: 3, from: 51, to: 52, totals: { qty: '1.50', price: '40.00' } }),
            );
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });

            await component.commitInline('enter');

            expect(component.from).toBe(51);
            expect(component.to).toBe(52);
            expect(component.total).toBe(120);
            expect(component.lastPage).toBe(3);
            expect(component.rangeLabel).toBe('51–52 of 120');
        });

        it('keeps the answer of the latest reading, whatever the order the answers come in', async () => {
            const older = deferred();
            const newer = deferred();
            http.get.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);

            const first = component.refreshTotals();
            const second = component.refreshTotals();
            newer.resolve(page([HAMMER, SAW], { totals: { qty: '1.50', price: '40.00' } }));
            await second;
            older.resolve(page([HAMMER, SAW], { totals: { qty: '1.50', price: '1.00' } }));
            await first;

            expect(component.totalRow.find((cell) => cell.key === 'price').text).toBe('€40.00');
        });

        it('is the totals of the search and filters in force from then on, so that a page keeps them', async () => {
            component.query = { ...component.query, q: 'ham' };
            http.patch.mockResolvedValue(changed(HAMMER, { price: '30.00' }));
            http.get.mockResolvedValue(page([HAMMER], { total: 1, totals: { qty: '1.50', price: '30.00' } }));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '30' } });
            await component.commitInline('enter');
            http.get.mockClear();

            await component.loadItems(2);

            expect(http.get.mock.calls[0][1].params).toEqual({ page: 2, q: 'ham' });
        });
    });
});
