import { beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryFields from '../../../resources/js/components/inventory-fields.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { hideModal, onModalHidden, showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const INVENTORY = '/api/v1/inventories/tools';
const TYPES = [
    { type: 'text', label: 'Text' },
    { type: 'number', label: 'Number' },
    { type: 'money', label: 'Amount' },
    { type: 'select', label: 'Choice' },
    { type: 'rating', label: 'Rating' },
    { type: 'barcode', label: 'Barcode' },
];
const CURRENCIES = [
    { code: 'EUR', name: 'Euro' },
    { code: 'USD', name: 'US Dollar' },
];

/** A field as `InventoryFieldResource` describes it. */
function field(id, type, options = {}, extra = {}) {
    return { id, name: 'Field ' + id, type, options, required: false, position: 0, ...extra };
}

const FIELDS = [
    field('a', 'text', { max_length: 255 }, { name: 'Name', required: true }),
    field('b', 'number', { decimals: 2, unit: 'kg', min: '0' }, { name: 'Weight' }),
    field(
        'c',
        'select',
        {
            choices: [
                { id: 'aaaaaaaa', label: 'New', color: 'green' },
                { id: 'bbbbbbbb', label: 'Used' },
            ],
        },
        { name: 'State' },
    ),
];

/** What `GET /api/v1/inventories/{inventory}` answers. */
function inventoryAnswer(fields = FIELDS) {
    return { data: { id: 'tools', name: 'Tools', fields } };
}

describe('the fields of an inventory', () => {
    let component;
    let fieldModal;
    let deleteModal;

    beforeEach(async () => {
        fieldModal = document.createElement('div');
        deleteModal = document.createElement('div');
        component = inventoryFields();
        component.$root = {
            dataset: {
                url: INVENTORY,
                fieldsUrl: INVENTORY + '/fields',
                orderUrl: INVENTORY + '/fields/order',
                fieldUrl: INVENTORY + '/fields/__id__',
                workspacePage: '/app/w/shop',
                types: JSON.stringify(TYPES),
                currencies: JSON.stringify(CURRENCIES),
            },
        };
        component.$refs = { fieldModal, deleteModal };
        component.$nextTick = (callback) => callback();
        http.get.mockResolvedValue(inventoryAnswer());
        await component.init();
    });

    describe('the list', () => {
        it('reads the types and the currencies of the markup, and the fields of the API', () => {
            expect(component.types).toEqual(TYPES);
            expect(component.currencies).toEqual(CURRENCIES);
            expect(component.fields.map((one) => one.id)).toEqual(['a', 'b', 'c']);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);
        });

        it('knows when the inventory has no field', async () => {
            http.get.mockResolvedValue(inventoryAnswer([]));

            await component.load();

            expect(component.empty).toBe(true);
        });

        it('forgets the form and the field to delete whenever a dialog is closed', () => {
            component.errors = { name: 'Taken.' };
            component.formError = 'Oops';
            component.deleting = FIELDS[0];

            onModalHidden.mock.calls.find(([element]) => element === fieldModal)[1]();
            onModalHidden.mock.calls.find(([element]) => element === deleteModal)[1]();

            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
            expect(component.deleting).toBeNull();
        });

        it('names the type of a field, and falls back to its key', () => {
            expect(component.typeLabel(FIELDS[1])).toBe('Number');
            expect(component.typeLabel(field('z', 'hologram'))).toBe('hologram');
        });

        it('names a currency with its code', () => {
            expect(component.currencyName('EUR')).toBe('Euro (EUR)');
            expect(component.currencyName('XYZ')).toBe('XYZ');
        });

        it('sums up the options of a field', () => {
            expect(component.summary(FIELDS[0])).toBe('Maximum length: 255');
            expect(component.summary(FIELDS[1])).toBe('Decimals: 2 · Unit: kg · Minimum: 0');
            expect(component.summary(FIELDS[2])).toBe('Choices: New, Used');
            expect(component.summary(field('m', 'money', { currency: 'USD' }))).toBe('Currency: US Dollar (USD)');
        });

        it('does not repeat what the HTTP helper already told', async () => {
            const error = new HttpError(500, 'Server error');
            error.reported = true;
            http.get.mockRejectedValue(error);

            await component.load();

            expect(notify).not.toHaveBeenCalled();
        });

        it('does not claim the inventory has no field when the fields could not be read, and reads them again on request', async () => {
            const error = new HttpError(500, 'Server error');
            error.reported = true;
            http.get.mockRejectedValue(error);
            component.fields = [];

            await component.load();

            expect(component.loadFailed).toBe(true);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);

            http.get.mockResolvedValue(inventoryAnswer([]));
            const retrying = component.load();

            expect(component.loadFailed).toBe(false);

            await retrying;

            expect(component.loadFailed).toBe(false);
            expect(component.empty).toBe(true);
        });

        it('remembers a failure of the reading that follows a change too', async () => {
            http.get.mockRejectedValue(new HttpError(0, 'Network down', { kind: 'network' }));

            await component.load();

            expect(component.loadFailed).toBe(true);
            expect(component.fields.map((one) => one.id)).toEqual(['a', 'b', 'c']);
        });

        it('shows other failures as a toast', async () => {
            http.get.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'odd' }));

            await component.load();

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');
        });

        it('takes the person back to the workspace when the inventory itself is gone', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.get.mockRejectedValue(gone);

            await component.load();

            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('moving a field', () => {
        const reordered = (ids) => ids.map((id) => FIELDS.find((one) => one.id === id));

        it('sends the whole new order and shows the one the server answers', async () => {
            http.put.mockResolvedValue({ data: reordered(['b', 'a', 'c']) });

            await component.moveDown(FIELDS[0]);

            expect(http.put).toHaveBeenCalledWith(INVENTORY + '/fields/order', { fields: ['b', 'a', 'c'] });
            expect(component.fields.map((one) => one.id)).toEqual(['b', 'a', 'c']);
            expect(component.busy).toBe(false);
        });

        it('moves a field up', async () => {
            http.put.mockResolvedValue({ data: reordered(['a', 'c', 'b']) });

            await component.moveUp(FIELDS[2]);

            expect(http.put).toHaveBeenCalledWith(INVENTORY + '/fields/order', { fields: ['a', 'c', 'b'] });
        });

        it('cannot move the first field up, nor the last one down', async () => {
            expect(component.isFirst(FIELDS[0])).toBe(true);
            expect(component.isFirst(FIELDS[1])).toBe(false);
            expect(component.isLast(FIELDS[2])).toBe(true);
            expect(component.isLast(FIELDS[1])).toBe(false);

            await component.moveUp(FIELDS[0]);
            await component.moveDown(FIELDS[2]);

            expect(http.put).not.toHaveBeenCalled();
        });

        it('ignores a second click while the order is being sent', async () => {
            http.put.mockResolvedValue({ data: reordered(['b', 'a', 'c']) });

            await Promise.all([component.moveDown(FIELDS[0]), component.moveDown(FIELDS[0])]);

            expect(http.put).toHaveBeenCalledTimes(1);
        });

        it('shows what the server holds when the order was refused', async () => {
            http.put.mockRejectedValue(
                new HttpError(422, 'Invalid.', { errors: { fields: ['The list must contain every field.'] } }),
            );
            http.get.mockResolvedValue(inventoryAnswer([...FIELDS, field('d', 'text')]));

            await component.moveDown(FIELDS[0]);

            expect(notify).toHaveBeenCalledWith('danger', 'The list must contain every field.');
            expect(component.fields.map((one) => one.id)).toEqual(['a', 'b', 'c', 'd']);
            expect(component.busy).toBe(false);
        });

        it('names the buttons after the field', () => {
            expect(component.upLabel(FIELDS[0])).toBe('Move Name up');
            expect(component.downLabel(FIELDS[0])).toBe('Move Name down');
            expect(component.editLabel(FIELDS[0])).toBe('Edit Name');
            expect(component.deleteLabel(FIELDS[0])).toBe('Delete Name');
        });
    });

    describe('adding a field', () => {
        it('opens the dialog on a text, with the defaults', () => {
            component.errors = { name: 'Old.' };

            component.startCreate();

            expect(component.editing).toBe(false);
            expect(component.modalTitle).toBe('Add a field');
            expect(component.form).toMatchObject({ id: null, type: 'text', name: '', required: false });
            expect(component.errors).toEqual({});
            expect(showModal).toHaveBeenCalledWith(fieldModal);
        });

        it('shows the options of the type that was picked, from their defaults', () => {
            component.startCreate();

            expect(component.hasOption('max_length')).toBe(true);
            expect(component.hasOption('decimals')).toBe(false);

            component.selectType({ target: { value: 'number' } });

            expect(component.isType('number')).toBe(true);
            expect(component.hasOption('decimals')).toBe(true);
            expect(component.hasOption('unit')).toBe(true);
            expect(component.hasOption('max_length')).toBe(false);
            expect(component.form.options.decimals).toBe('0');

            component.selectType({ target: { value: 'percentage' } });

            expect(component.form.options.decimals).toBe('2');
        });

        it('cannot be sent without a name', async () => {
            component.startCreate();
            component.form.name = '  ';

            expect(component.canSubmit).toBe(false);

            await component.save();

            expect(http.post).not.toHaveBeenCalled();
        });

        it('sends the type, the name, whether it is required and the options of the type', async () => {
            http.post.mockResolvedValue({ data: field('n', 'number') });
            component.startCreate();
            component.selectType({ target: { value: 'number' } });
            Object.assign(component.form, { name: ' Weight ', required: true });
            Object.assign(component.form.options, { decimals: '3', min: '0,5', max: '', unit: ' kg ' });

            await component.save();

            expect(http.post).toHaveBeenCalledWith(INVENTORY + '/fields', {
                type: 'number',
                name: 'Weight',
                required: true,
                options: { decimals: 3, min: '0.5', max: null, unit: 'kg' },
            });
            expect(hideModal).toHaveBeenCalledWith(fieldModal);
            expect(notify).toHaveBeenCalledWith('success', 'Field added.');
            expect(component.busy).toBe(false);
        });

        it('sends the currency of an amount, the grade of a rating and the format of a barcode', async () => {
            http.post.mockResolvedValue({ data: field('n', 'money') });

            for (const [type, set, expected] of [
                ['money', (options) => (options.currency = 'USD'), { currency: 'USD' }],
                ['rating', (options) => component.selectMax({ target: { value: '8' } }) ?? options, { max: 8 }],
                [
                    'barcode',
                    (options) => component.selectFormat({ target: { value: 'isbn13' } }) ?? options,
                    { format: 'isbn13' },
                ],
            ]) {
                http.post.mockClear();
                component.startCreate();
                component.selectType({ target: { value: type } });
                component.form.name = 'Field';
                set(component.form.options);

                await component.save();

                expect(http.post.mock.calls[0][1].options, type).toEqual(expected);
            }
        });

        it('takes the currency picked in the list', () => {
            component.startCreate();
            component.selectType({ target: { value: 'money' } });

            component.selectCurrency({ target: { value: 'USD' } });

            expect(component.form.options.currency).toBe('USD');
            expect(component.ratingMaxima).toEqual(['3', '4', '5', '6', '7', '8', '9', '10']);
            expect(component.formats.map((format) => format.value)).toEqual([
                'any',
                'ean13',
                'ean8',
                'upca',
                'isbn10',
                'isbn13',
            ]);
        });

        it('lists the colors a choice can have', () => {
            expect(component.colors[0]).toEqual({ value: '', label: 'No color' });
            expect(component.colors.map((color) => color.label)).toContain('Purple');
            expect(component.colors).toHaveLength(11);
        });

        it('sends the choices of a select, and takes out the empty rows first', async () => {
            http.post.mockResolvedValue({ data: field('n', 'select') });
            component.startCreate();
            component.selectType({ target: { value: 'select' } });
            component.form.name = 'State';
            component.form.options.choices[0].label = 'New';
            component.selectColor(component.form.options.choices[0], { target: { value: 'green' } });
            component.addChoice();
            component.addChoice();
            component.form.options.choices[2].label = 'Used';

            expect(component.swatch(component.form.options.choices[0])).toBe('app-swatch bg-green-lt');

            await component.save();

            expect(http.post.mock.calls[0][1].options).toEqual({
                choices: [
                    { label: 'New', color: 'green' },
                    { label: 'Used', color: null },
                ],
            });
            expect(component.form.options.choices).toHaveLength(2);
        });

        it('adds and removes choices', () => {
            component.startCreate();
            component.selectType({ target: { value: 'select' } });

            component.addChoice();
            expect(component.form.options.choices).toHaveLength(2);

            component.form.options.choices[1].label = 'Used';
            expect(component.removeChoiceLabel(component.form.options.choices[1])).toBe('Remove the choice Used');
            expect(component.removeChoiceLabel(component.form.options.choices[0])).toBe('Remove the choice');

            component.removeChoice(0);
            expect(component.form.options.choices.map((choice) => choice.label)).toEqual(['Used']);
        });

        it('shows the refusals under the inputs they are about, the options and the choices too', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: {
                        name: ['This inventory already has a field with this name.'],
                        'options.decimals': ['The number of decimals must be between 0 and 6.'],
                        'options.choices.1.label': ['The choice label is required.'],
                        'options.choices': ['At least one choice is required.'],
                    },
                }),
            );
            component.startCreate();
            component.form.name = 'Name';

            await component.save();

            expect(component.errorOf('name')).toBe('This inventory already has a field with this name.');
            expect(component.errorOf('options.decimals')).toBe('The number of decimals must be between 0 and 6.');
            expect(component.errorOf('options.choices')).toBe('At least one choice is required.');
            expect(component.choiceError(1)).toBe('The choice label is required.');
            expect(component.choiceError(0)).toBe('');
            expect(component.controlClass('name')).toBe('form-control is-invalid');
            expect(component.selectClass('options.currency')).toBe('form-select');
            expect(component.formError).toBe('');
            expect(hideModal).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('shows a refusal about nothing in the form at the top of the dialog', async () => {
            http.post.mockRejectedValue(new HttpError(422, 'Invalid.', { errors: { odd: ['Odd.'] } }));
            component.startCreate();
            component.form.name = 'Name';

            await component.save();

            expect(component.formError).toBe('Odd.');
        });

        it('says the quota is reached', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'field_limit_reached', body: { limit: 100 } }),
            );
            component.startCreate();
            component.form.name = 'Name';

            await component.save();

            expect(component.formError).toBe('Field limit reached (100 per inventory).');
        });

        it('shows other failures as a toast, but not the ones the HTTP helper already told', async () => {
            http.post.mockRejectedValueOnce(new HttpError(409, 'Conflict', { code: 'odd' }));
            component.startCreate();
            component.form.name = 'Name';
            await component.save();

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');

            notify.mockClear();
            const reported = new HttpError(403, 'Not allowed');
            reported.reported = true;
            http.post.mockRejectedValueOnce(reported);
            await component.save();

            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('changing a field', () => {
        it('opens the dialog with the field as it is, its type fixed', async () => {
            await component.startEdit(FIELDS[1]);

            expect(component.editing).toBe(true);
            expect(component.modalTitle).toBe('Edit the field');
            expect(component.form).toMatchObject({ id: 'b', type: 'number', name: 'Weight', required: false });
            expect(component.form.options).toMatchObject({ decimals: '2', unit: 'kg', min: '0', max: '' });
            expect(showModal).toHaveBeenCalledWith(fieldModal);
            expect(component.busy).toBe(false);
        });

        it('reads the list again before it opens, and shows the field as the server holds it now', async () => {
            http.get.mockClear();
            http.get.mockResolvedValue(
                inventoryAnswer([FIELDS[0], { ...FIELDS[1], name: 'Mass', options: { decimals: 3 } }, FIELDS[2]]),
            );

            await component.startEdit(FIELDS[1]);

            expect(http.get).toHaveBeenCalledWith(INVENTORY);
            expect(component.form).toMatchObject({ id: 'b', name: 'Mass' });
            expect(component.form.options.decimals).toBe('3');
        });

        it('opens nothing for a field that was deleted meanwhile, and shows what is left', async () => {
            http.get.mockResolvedValue(inventoryAnswer([FIELDS[0], FIELDS[2]]));

            await component.startEdit(FIELDS[1]);

            expect(showModal).not.toHaveBeenCalled();
            expect(component.fields.map((one) => one.id)).toEqual(['a', 'c']);
            expect(component.busy).toBe(false);
        });

        it('opens nothing when the list could not be read', async () => {
            http.get.mockRejectedValue(new HttpError(0, 'Network down', { kind: 'network' }));

            await component.startEdit(FIELDS[1]);

            expect(showModal).not.toHaveBeenCalled();
            expect(component.loadFailed).toBe(true);
            expect(component.busy).toBe(false);
        });

        it('ignores a second click while the list is being read', async () => {
            await Promise.all([component.startEdit(FIELDS[1]), component.startEdit(FIELDS[1])]);

            expect(showModal).toHaveBeenCalledTimes(1);
        });

        it('sends the name, whether it is required and the options, without the type', async () => {
            http.patch.mockResolvedValue({ data: FIELDS[1] });
            await component.startEdit(FIELDS[1]);
            component.form.name = 'Mass';
            component.form.required = true;
            component.form.options.min = '';

            await component.save();

            expect(http.patch).toHaveBeenCalledWith(INVENTORY + '/fields/b', {
                name: 'Mass',
                required: true,
                options: { decimals: 2, min: null, max: null, unit: 'kg' },
            });
            expect(notify).toHaveBeenCalledWith('success', 'Field saved.');
            expect(hideModal).toHaveBeenCalledWith(fieldModal);
        });

        it('keeps the id of a choice that is renamed or recolored, and takes a removed one out', async () => {
            http.patch.mockResolvedValue({ data: FIELDS[2] });
            await component.startEdit(FIELDS[2]);
            component.form.options.choices[0].label = 'Brand new';
            component.selectColor(component.form.options.choices[0], { target: { value: 'blue' } });
            component.removeChoice(1);
            component.addChoice();
            component.form.options.choices[1].label = 'Refurbished';

            await component.save();

            expect(http.patch.mock.calls[0][1].options).toEqual({
                choices: [
                    { id: 'aaaaaaaa', label: 'Brand new', color: 'blue' },
                    { label: 'Refurbished', color: null },
                ],
            });
        });

        it('does not send the choices when the person only renamed the field: choices added meanwhile survive', async () => {
            http.patch.mockResolvedValue({ data: FIELDS[2] });
            await component.startEdit(FIELDS[2]);
            component.form.name = 'Condition';

            await component.save();

            expect(http.patch).toHaveBeenCalledWith(INVENTORY + '/fields/c', {
                name: 'Condition',
                required: false,
                options: {},
            });
        });

        it('sends the choices of a new select, which has no earlier list to compare with', async () => {
            http.post.mockResolvedValue({ data: FIELDS[2] });
            component.startCreate();
            component.selectType({ target: { value: 'select' } });
            component.form.name = 'State';
            component.form.options.choices[0].label = 'New';

            await component.save();

            expect(http.post.mock.calls[0][1].options).toEqual({ choices: [{ label: 'New', color: null }] });
        });

        it('forgets the earlier list when a new field is added after one was changed', async () => {
            await component.startEdit(FIELDS[2]);
            component.startCreate();

            expect(component.opened).toBeNull();
        });

        it('reads the list again after a change', async () => {
            http.patch.mockResolvedValue({ data: FIELDS[0] });
            await component.startEdit(FIELDS[0]);
            http.get.mockClear();

            await component.save();

            expect(http.get).toHaveBeenCalledWith(INVENTORY);
        });

        it('closes the dialog and shows what is left when the field was deleted meanwhile', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.patch.mockRejectedValue(gone);
            await component.startEdit(FIELDS[1]);
            component.form.name = 'Mass';
            http.get.mockClear();
            http.get.mockResolvedValue(inventoryAnswer([FIELDS[0], FIELDS[2]]));

            await component.save();
            await vi.waitFor(() => expect(component.fields.map((one) => one.id)).toEqual(['a', 'c']));

            expect(hideModal).toHaveBeenCalledWith(fieldModal);
            expect(http.get).toHaveBeenCalledWith(INVENTORY);
            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('takes the person back to the workspace when the inventory itself is gone', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.post.mockRejectedValue(gone);
            component.startCreate();
            component.form.name = 'Name';
            http.get.mockRejectedValue(gone);

            await component.save();
            await vi.waitFor(() => expect(redirectTo).toHaveBeenCalledWith('/app/w/shop'));

            expect(hideModal).toHaveBeenCalledWith(fieldModal);
        });
    });

    describe('deleting a field', () => {
        it('asks for a confirmation that says its values are erased from every item', () => {
            component.askDelete(FIELDS[1]);

            expect(component.confirmText).toBe('Delete the field Weight?');
            expect(showModal).toHaveBeenCalledWith(deleteModal);
            expect(http.delete).not.toHaveBeenCalled();
        });

        it('has nothing to ask until a field is chosen', () => {
            expect(component.confirmText).toBe('');
        });

        it('deletes the field once confirmed, then reads the list again', async () => {
            http.delete.mockResolvedValue(null);
            component.askDelete(FIELDS[1]);
            http.get.mockResolvedValue(inventoryAnswer([FIELDS[0], FIELDS[2]]));

            await component.confirmDelete();

            expect(http.delete).toHaveBeenCalledWith(INVENTORY + '/fields/b');
            expect(hideModal).toHaveBeenCalledWith(deleteModal);
            expect(notify).toHaveBeenCalledWith('success', 'Field deleted.');
            expect(component.fields.map((one) => one.id)).toEqual(['a', 'c']);
        });

        it('does nothing without a field to delete, nor twice at once', async () => {
            await component.confirmDelete();

            expect(http.delete).not.toHaveBeenCalled();

            http.delete.mockResolvedValue(null);
            component.askDelete(FIELDS[1]);

            await Promise.all([component.confirmDelete(), component.confirmDelete()]);

            expect(http.delete).toHaveBeenCalledTimes(1);
        });

        it('closes the dialog and shows what is left when the field was already deleted by somebody else', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.delete.mockRejectedValue(gone);
            component.askDelete(FIELDS[1]);
            http.get.mockResolvedValue(inventoryAnswer([FIELDS[0], FIELDS[2]]));

            await component.confirmDelete();

            expect(hideModal).toHaveBeenCalledWith(deleteModal);
            expect(component.fields.map((one) => one.id)).toEqual(['a', 'c']);
            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('keeps the dialog open when the deletion fails', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'odd' }));
            component.askDelete(FIELDS[1]);

            await component.confirmDelete();

            expect(hideModal).not.toHaveBeenCalled();
            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');
            expect(component.busy).toBe(false);
        });
    });
});
