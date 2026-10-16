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

        it('does not let another cell take its place while the value is refused', async () => {
            http.patch.mockRejectedValue(refusal());
            await edit(0, 'price');
            component.onInlineInput({ target: { value: 'abc' } });
            await component.commitInline('blur');

            await edit(1, 'name');

            expect(component.inlineRow).toBe('i1');
            expect(component.inlineFieldId).toBe('price');
        });

        it('shows the message of the server when it names no field of the item', async () => {
            http.patch.mockRejectedValue(new HttpError(422, 'Invalid.', { errors: { values: ['Unknown field.'] } }));
            await edit(0, 'price');
            component.onInlineInput({ target: { value: '1' } });

            await component.commitInline('blur');

            expect(component.inlineError).toBe('Unknown field.');
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
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
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

            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1, q: 'ham' } });
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
});
