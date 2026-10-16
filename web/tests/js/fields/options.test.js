import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import {
    BARCODE_FORMATS,
    barcodeFormatName,
    blankChoice,
    canBeRequired,
    formToOptions,
    optionNames,
    optionsToForm,
    summarizeOptions,
} from '../../../resources/js/fields/options.js';
import { COLORS, badgeClass, swatchClass } from '../../../resources/js/fields/palette.js';

const MESSAGES = {
    'fields.barcode.any': 'Any code',
    'fields.summary.max_length': 'Maximum length: :count',
    'fields.summary.decimals': 'Decimals: :count',
    'fields.summary.unit': 'Unit: :unit',
    'fields.summary.min': 'Minimum: :value',
    'fields.summary.max': 'Maximum: :value',
    'fields.summary.currency': 'Currency: :currency',
    'fields.summary.choices': 'Choices: :list',
    'fields.summary.format': 'Format: :format',
    'fields.summary.max_files': 'Files allowed: :count',
};

beforeAll(() => {
    document.body.innerHTML = `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>`;
});

afterEach(() => {
    document.documentElement.lang = 'en';
});

describe('which options a type has', () => {
    it('lists the options of each type, and none for the others', () => {
        expect(optionNames('text')).toEqual(['max_length']);
        expect(optionNames('number')).toEqual(['decimals', 'min', 'max', 'unit']);
        expect(optionNames('money')).toEqual(['currency']);
        expect(optionNames('percentage')).toEqual(['decimals']);
        expect(optionNames('select')).toEqual(['choices']);
        expect(optionNames('multiselect')).toEqual(['choices']);
        expect(optionNames('rating')).toEqual(['max']);
        expect(optionNames('barcode')).toEqual(['format']);

        for (const type of [
            'textarea',
            'boolean',
            'date',
            'datetime',
            'time',
            'tags',
            'url',
            'email',
            'phone',
            'color',
        ]) {
            expect(optionNames(type), type).toEqual([]);
        }
    });
});

describe('the options in the form', () => {
    it('starts a new field from the defaults of its type', () => {
        expect(optionsToForm('number')).toMatchObject({ decimals: '0', min: '', max: '', unit: '' });
        expect(optionsToForm('percentage').decimals).toBe('2');
        expect(optionsToForm('money').currency).toBe('EUR');
        expect(optionsToForm('rating').max).toBe('5');
        expect(optionsToForm('barcode').format).toBe('any');
        expect(optionsToForm('text').max_length).toBe('');
        expect(optionsToForm('select').choices).toEqual([blankChoice()]);
    });

    it('shows the options of a field as text boxes hold them', () => {
        const form = optionsToForm('number', { decimals: 2, min: '0.5', max: '99.5', unit: 'kg' });

        expect(form).toMatchObject({ decimals: '2', min: '0.5', max: '99.5', unit: 'kg' });
        expect(optionsToForm('text', { max_length: 40 }).max_length).toBe('40');
        expect(optionsToForm('rating', { max: 7 }).max).toBe('7');
        expect(optionsToForm('money', { currency: 'JPY' }).currency).toBe('JPY');
    });

    it('shows the bounds of a number with the decimal separator of the language', () => {
        document.documentElement.lang = 'fr';

        expect(optionsToForm('number', { decimals: 2, min: '0.5' }).min).toBe('0,5');
    });

    it('keeps the ids and colors of the choices of a select', () => {
        const form = optionsToForm('select', {
            choices: [
                { id: 'aaaaaaaa', label: 'New', color: 'green' },
                { id: 'bbbbbbbb', label: 'Used' },
            ],
        });

        expect(form.choices).toEqual([
            { id: 'aaaaaaaa', label: 'New', color: 'green' },
            { id: 'bbbbbbbb', label: 'Used', color: '' },
        ]);
    });
});

describe('the options to send', () => {
    it('sends only the options of the type, each one present', () => {
        const form = optionsToForm('number', { decimals: 2, unit: 'kg' });

        expect(formToOptions('number', form)).toEqual({ decimals: 2, min: null, max: null, unit: 'kg' });
        expect(formToOptions('text', optionsToForm('text'))).toEqual({ max_length: null });
        expect(formToOptions('boolean', optionsToForm('boolean'))).toEqual({});
    });

    it('sends null for an empty option, which takes it back to its default', () => {
        const form = optionsToForm('number', { decimals: 2, min: '1', max: '9', unit: 'kg' });
        Object.assign(form, { min: '', max: ' ', unit: '  ' });

        expect(formToOptions('number', form)).toEqual({ decimals: 2, min: null, max: null, unit: null });
    });

    it('sends whole numbers as numbers and bounds as decimal text with a dot', () => {
        const form = optionsToForm('number');
        Object.assign(form, { decimals: '3', min: '-1,5', max: '1 000,25' });

        expect(formToOptions('number', form)).toEqual({ decimals: 3, min: '-1.5', max: '1000.25', unit: null });
        expect(formToOptions('text', { ...optionsToForm('text'), max_length: '120' })).toEqual({ max_length: 120 });
        expect(formToOptions('rating', { ...optionsToForm('rating'), max: '8' })).toEqual({ max: 8 });
    });

    it('sends text that is not a number as it is, for the server to refuse', () => {
        expect(formToOptions('percentage', { ...optionsToForm('percentage'), decimals: 'two' })).toEqual({
            decimals: 'two',
        });
    });

    it('sends the currency and the format', () => {
        expect(formToOptions('money', { ...optionsToForm('money'), currency: 'USD' })).toEqual({ currency: 'USD' });
        expect(formToOptions('barcode', { ...optionsToForm('barcode'), format: 'isbn13' })).toEqual({
            format: 'isbn13',
        });
    });

    it('sends the choices with the ids they have, so that renaming one keeps its values', () => {
        const form = optionsToForm('select', {
            choices: [
                { id: 'aaaaaaaa', label: 'New', color: 'green' },
                { id: 'bbbbbbbb', label: 'Used' },
            ],
        });
        form.choices[0].label = ' Brand new ';
        form.choices.push({ id: null, label: 'Broken', color: 'red' });

        expect(formToOptions('select', form)).toEqual({
            choices: [
                { id: 'aaaaaaaa', label: 'Brand new', color: 'green' },
                { id: 'bbbbbbbb', label: 'Used', color: null },
                { label: 'Broken', color: 'red' },
            ],
        });
    });

    it('sends the choices of an existing field only when the person changed them', () => {
        const stored = {
            choices: [
                { id: 'aaaaaaaa', label: 'New', color: 'green' },
                { id: 'bbbbbbbb', label: 'Used' },
            ],
        };
        const opened = optionsToForm('select', stored);
        const form = optionsToForm('select', stored);

        // A dialog older than the field must not send back a list that lacks the choices added since.
        expect(formToOptions('select', form, opened)).toEqual({});

        form.choices[0].label = 'Brand new';

        expect(formToOptions('select', form, opened).choices).toEqual([
            { id: 'aaaaaaaa', label: 'Brand new', color: 'green' },
            { id: 'bbbbbbbb', label: 'Used', color: null },
        ]);
    });

    it('sends the choices of a field that is being created, and the other options of an existing one, whatever changed', () => {
        const opened = optionsToForm('select', { choices: [{ id: 'aaaaaaaa', label: 'New' }] });
        const created = optionsToForm('select');
        created.choices[0].label = 'First';

        expect(formToOptions('select', created, null).choices).toEqual([{ label: 'First', color: null }]);
        expect(
            formToOptions('select', optionsToForm('select', { choices: [{ id: 'aaaaaaaa', label: 'New' }] }), opened),
        ).toEqual({});

        const number = optionsToForm('number', { decimals: 2 });

        expect(formToOptions('number', number, optionsToForm('number', { decimals: 2 }))).toEqual({
            decimals: 2,
            min: null,
            max: null,
            unit: null,
        });
    });

    it('leaves out a choice that was added and not filled in, but never one that exists', () => {
        const form = optionsToForm('select', { choices: [{ id: 'aaaaaaaa', label: 'New' }] });
        form.choices.push(blankChoice());
        form.choices[0].label = '';

        expect(formToOptions('select', form).choices).toEqual([{ id: 'aaaaaaaa', label: '', color: null }]);
    });
});

describe('the summary of the options', () => {
    const name = (code) => (code === 'EUR' ? 'Euro (EUR)' : code);

    it('says what the options of a field are', () => {
        expect(summarizeOptions({ type: 'text', options: { max_length: 40 } }, name)).toBe('Maximum length: 40');
        expect(
            summarizeOptions({ type: 'number', options: { decimals: 2, unit: 'kg', min: '0', max: '99.5' } }, name),
        ).toBe('Decimals: 2 · Unit: kg · Minimum: 0 · Maximum: 99.5');
        expect(summarizeOptions({ type: 'money', options: { currency: 'EUR' } }, name)).toBe('Currency: Euro (EUR)');
        expect(summarizeOptions({ type: 'percentage', options: { decimals: 1 } }, name)).toBe('Decimals: 1');
        expect(summarizeOptions({ type: 'rating', options: { max: 10 } }, name)).toBe('Maximum: 10');
        expect(summarizeOptions({ type: 'barcode', options: { format: 'ean13' } }, name)).toBe('Format: EAN-13');
        expect(summarizeOptions({ type: 'barcode', options: { format: 'any' } }, name)).toBe('Format: Any code');
        expect(
            summarizeOptions(
                {
                    type: 'select',
                    options: {
                        choices: [
                            { id: 'a', label: 'New' },
                            { id: 'b', label: 'Used' },
                        ],
                    },
                },
                name,
            ),
        ).toBe('Choices: New, Used');
    });

    it('says nothing for a type without options', () => {
        expect(summarizeOptions({ type: 'date', options: {} }, name)).toBe('');
    });

    it('names the formats of a barcode', () => {
        expect(BARCODE_FORMATS.map(barcodeFormatName)).toEqual([
            'Any code',
            'EAN-13',
            'EAN-8',
            'UPC-A',
            'ISBN-10',
            'ISBN-13',
        ]);
    });
});

describe('the palette', () => {
    it('has the ten colors the API accepts', () => {
        expect(COLORS).toEqual([
            'gray',
            'red',
            'orange',
            'yellow',
            'green',
            'teal',
            'blue',
            'indigo',
            'purple',
            'pink',
        ]);
    });

    it('gives a badge and a swatch to each, and a neutral one to the others', () => {
        expect(badgeClass('purple')).toBe('badge bg-purple-lt');
        expect(badgeClass(null)).toBe('badge bg-secondary-lt');
        expect(badgeClass('chartreuse')).toBe('badge bg-secondary-lt');
        expect(swatchClass('teal')).toBe('app-swatch bg-teal-lt');
    });
});

describe('the options of the photo and file fields', () => {
    it('are the number of files, for both types', () => {
        expect(optionNames('photo')).toEqual(['max_files']);
        expect(optionNames('file')).toEqual(['max_files']);
    });

    it('start from one file, and show the number a field has as a box holds it', () => {
        expect(optionsToForm('photo').max_files).toBe('1');
        expect(optionsToForm('file', { max_files: 4 }).max_files).toBe('4');
    });

    it('send the number of files as a number', () => {
        expect(formToOptions('photo', { ...optionsToForm('photo'), max_files: '6' })).toEqual({ max_files: 6 });
        expect(formToOptions('file', optionsToForm('file', { max_files: 2 }))).toEqual({ max_files: 2 });
    });

    it('send text that is not a number as it is, for the server to refuse', () => {
        expect(formToOptions('photo', { ...optionsToForm('photo'), max_files: 'lots' })).toEqual({ max_files: 'lots' });
    });

    it('are summed up by how many files they take', () => {
        expect(summarizeOptions({ type: 'photo', options: { max_files: 3 } }, String)).toBe('Files allowed: 3');
        expect(summarizeOptions({ type: 'file', options: {} }, String)).toBe('Files allowed: 1');
    });

    it('cannot be required: they are filled by uploading, which an item can be saved without', () => {
        expect(canBeRequired('photo')).toBe(false);
        expect(canBeRequired('file')).toBe(false);
        expect(canBeRequired('text')).toBe(true);
        expect(canBeRequired('select')).toBe(true);
    });
});
