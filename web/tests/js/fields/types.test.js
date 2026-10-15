import process from 'node:process';

import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';

import {
    controlOf,
    describeValue,
    emptyInput,
    fromInput,
    inputModeOf,
    inputTypeOf,
    maxLengthOf,
    selectOptionsOf,
    toInput,
    typeOf,
    webAddress,
} from '../../../resources/js/fields/index.js';
import { TEXT_PREVIEW_LENGTH } from '../../../resources/js/fields/types.js';
import { FIELD_TYPES } from '../../../resources/js/fields/types.js';

const MESSAGES = {
    'fields.boolean.yes': 'Yes',
    'fields.boolean.no': 'No',
    'fields.rating': ':value out of :max',
};

/** The spaces the formatters use (no-break, narrow no-break) written as plain ones, to compare text. */
const plain = (text) => text.replace(/[\u00a0\u202f]/g, ' ');

/** A field of an inventory. */
function field(type, options = {}, extra = {}) {
    return { id: 'f1', name: 'Field', type, options, required: false, position: 0, ...extra };
}

const CHOICES = {
    choices: [
        { id: 'aaaaaaaa', label: 'New', color: 'green' },
        { id: 'bbbbbbbb', label: 'Used', color: 'red' },
        { id: 'cccccccc', label: 'Broken' },
    ],
};

const originalZone = process.env.TZ;

/** Runs the following code as if the browser were in a timezone. */
function inZone(zone) {
    process.env.TZ = zone;
}

beforeAll(() => {
    document.body.innerHTML = `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>`;
});

afterEach(() => {
    document.documentElement.lang = 'en';
    inZone(originalZone ?? 'UTC');
});

afterAll(() => {
    document.body.innerHTML = '';
});

describe('the registry', () => {
    it('knows every type of the server', () => {
        expect(Object.keys(FIELD_TYPES)).toEqual([
            'text',
            'textarea',
            'number',
            'money',
            'percentage',
            'boolean',
            'date',
            'datetime',
            'time',
            'select',
            'multiselect',
            'tags',
            'url',
            'email',
            'phone',
            'rating',
            'color',
            'barcode',
        ]);
    });

    it('chooses the control of the form by type', () => {
        const controls = Object.fromEntries(Object.keys(FIELD_TYPES).map((type) => [type, controlOf(field(type))]));

        expect(controls).toEqual({
            text: 'input',
            textarea: 'textarea',
            number: 'input',
            money: 'input',
            percentage: 'input',
            boolean: 'select',
            date: 'input',
            datetime: 'input',
            time: 'input',
            select: 'select',
            multiselect: 'checks',
            tags: 'tags',
            url: 'input',
            email: 'input',
            phone: 'input',
            rating: 'rating',
            color: 'color',
            barcode: 'input',
        });
    });

    it('chooses the kind of <input> by type', () => {
        expect(inputTypeOf(field('date'))).toBe('date');
        expect(inputTypeOf(field('datetime'))).toBe('datetime-local');
        expect(inputTypeOf(field('time'))).toBe('time');
        expect(inputTypeOf(field('url'))).toBe('url');
        expect(inputTypeOf(field('email'))).toBe('email');
        expect(inputTypeOf(field('phone'))).toBe('tel');
        expect(inputTypeOf(field('text'))).toBe('text');
        // A number is typed in a text box: a comma is as good as a dot, which a number box does not take in every language.
        expect(inputTypeOf(field('number'))).toBe('text');
        expect(inputModeOf(field('number'))).toBe('decimal');
        expect(inputModeOf(field('text'))).toBeNull();
    });

    it('limits the length of a text to what the field allows', () => {
        expect(maxLengthOf(field('text', { max_length: 40 }))).toBe(40);
        expect(maxLengthOf(field('text'))).toBe(255);
        expect(maxLengthOf(field('number'))).toBeNull();
    });

    it('treats a type it does not know as a text, so that a field of a newer version never breaks the page', () => {
        const strange = field('hologram');

        expect(typeOf(strange)).toBe(FIELD_TYPES.text);
        expect(describeValue(strange, 'abc')).toMatchObject({ kind: 'text', text: 'abc' });
        expect(controlOf(strange)).toBe('input');
    });
});

describe('showing a value', () => {
    it('shows nothing as a dash', () => {
        for (const value of [undefined, null, '', []]) {
            expect(describeValue(field('text'), value)).toMatchObject({ kind: 'empty', text: '—' });
        }
    });

    it('shows `false` and `0` as values', () => {
        expect(describeValue(field('boolean'), false).kind).toBe('badges');
        expect(describeValue(field('number', { decimals: 0 }), '0').text).toBe('0');
    });

    it('never throws for a value the type cannot make sense of', () => {
        expect(describeValue(field('date'), '2026-13-45')).toMatchObject({ kind: 'text', text: '2026-13-45' });
        expect(describeValue(field('datetime'), 'yesterday')).toMatchObject({ kind: 'text', text: 'yesterday' });
        expect(describeValue(field('time'), 'noon')).toMatchObject({ kind: 'text', text: 'noon' });
    });

    describe('texts', () => {
        it('shows a text as it is', () => {
            expect(describeValue(field('text'), 'Hammer')).toMatchObject({ kind: 'text', text: 'Hammer' });
        });

        it('shows a text with markup as text, never as markup', () => {
            const cell = describeValue(field('text'), '<img src=x onerror=alert(1)>');

            expect(cell).toMatchObject({ kind: 'text', text: '<img src=x onerror=alert(1)>' });
        });

        it('cuts a long text to one line, and keeps the whole text as the tooltip', () => {
            const long = ('Line one\nLine two ' + 'x'.repeat(100)).trim();
            const cell = describeValue(field('textarea'), long);

            expect(cell.text.startsWith('Line one Line two xxx')).toBe(true);
            expect(cell.text.endsWith('…')).toBe(true);
            expect(cell.text.length).toBeLessThanOrEqual(TEXT_PREVIEW_LENGTH + 1);
            expect(cell.title).toBe(long);
        });

        it('leaves a short text alone, without a tooltip', () => {
            expect(describeValue(field('textarea'), 'Short\ntext')).toMatchObject({ text: 'Short text', title: null });
        });

        it('shows a barcode in monospace', () => {
            expect(describeValue(field('barcode'), '4006381333931')).toMatchObject({
                kind: 'mono',
                text: '4006381333931',
            });
        });
    });

    describe('numbers', () => {
        const kilograms = field('number', { decimals: 2, unit: 'kg' });

        it('shows the decimals of the field and its unit, in the language of the page', () => {
            expect(describeValue(kilograms, '1234.50').text).toBe('1,234.50 kg');

            document.documentElement.lang = 'fr';
            expect(plain(describeValue(kilograms, '1234.50').text)).toBe('1 234,50 kg');
        });

        it('shows a whole number without a decimal point', () => {
            expect(describeValue(field('number', { decimals: 0 }), '1500').text).toBe('1,500');
        });

        it('shows an amount in its currency', () => {
            expect(describeValue(field('money', { currency: 'EUR' }), '19.90').text).toBe('€19.90');
            expect(describeValue(field('money', { currency: 'USD' }), '19.90').text).toBe('$19.90');
            expect(describeValue(field('money', {}), '19.90').text).toBe('€19.90');

            document.documentElement.lang = 'fr';
            expect(plain(describeValue(field('money', { currency: 'EUR' }), '1234.50').text)).toBe('1 234,50 €');
        });

        it('shows a percentage with the percent sign of the language', () => {
            const rate = field('percentage', { decimals: 1 });

            expect(describeValue(rate, '12.5').text).toBe('12.5%');

            document.documentElement.lang = 'fr';
            expect(plain(describeValue(rate, '12.5').text)).toBe('12,5 %');
        });
    });

    describe('yes / no', () => {
        it('shows a yes in green and a no in gray', () => {
            const yes = describeValue(field('boolean'), true);
            const no = describeValue(field('boolean'), false);

            expect(yes.badges).toEqual([{ text: 'Yes', class: 'badge bg-green-lt' }]);
            expect(no.badges).toEqual([{ text: 'No', class: 'badge bg-secondary-lt' }]);
        });
    });

    describe('dates', () => {
        it('shows a day as the day it is, whatever the timezone of the browser', () => {
            for (const zone of ['America/Los_Angeles', 'Pacific/Kiritimati', 'Europe/Paris', 'UTC']) {
                inZone(zone);

                expect(describeValue(field('date'), '2026-10-15').text, zone).toBe('Oct 15, 2026');
                expect(describeValue(field('date'), '2026-01-01').text, zone).toBe('Jan 1, 2026');
            }
        });

        it('shows a day in the language of the page', () => {
            document.documentElement.lang = 'fr';

            expect(describeValue(field('date'), '2026-10-15').text).toBe('15 oct. 2026');
        });

        it('shows a moment in the timezone of the browser', () => {
            inZone('Europe/Paris');
            expect(plain(describeValue(field('datetime'), '2026-10-15T12:30:00Z').text)).toBe('Oct 15, 2026, 2:30 PM');

            inZone('America/New_York');
            expect(plain(describeValue(field('datetime'), '2026-10-15T12:30:00Z').text)).toBe('Oct 15, 2026, 8:30 AM');
        });

        it('shows a time of day as it was typed, whatever the timezone of the browser', () => {
            inZone('America/Los_Angeles');

            expect(plain(describeValue(field('time'), '08:05').text)).toBe('8:05 AM');

            document.documentElement.lang = 'fr';
            expect(describeValue(field('time'), '08:05').text).toBe('08:05');
        });
    });

    describe('choices', () => {
        const select = field('select', CHOICES);
        const multiselect = field('multiselect', CHOICES);

        it('shows a choice as a badge in its color', () => {
            expect(describeValue(select, 'aaaaaaaa')).toMatchObject({
                kind: 'badges',
                badges: [{ text: 'New', class: 'badge bg-green-lt' }],
            });
            expect(describeValue(select, 'bbbbbbbb').badges[0].class).toBe('badge bg-red-lt');
        });

        it('shows a choice without a color, or with one the palette does not have, as a gray badge', () => {
            expect(describeValue(select, 'cccccccc').badges[0].class).toBe('badge bg-secondary-lt');
            expect(
                describeValue(
                    field('select', { choices: [{ id: 'dddddddd', label: 'Odd', color: 'chartreuse' }] }),
                    'dddddddd',
                ).badges[0].class,
            ).toBe('badge bg-secondary-lt');
        });

        it('shows nothing for a choice that no longer exists', () => {
            expect(describeValue(select, 'zzzzzzzz').kind).toBe('empty');
            expect(describeValue(multiselect, ['zzzzzzzz']).kind).toBe('empty');
        });

        it('shows several choices as several badges, in the order of the field', () => {
            expect(describeValue(multiselect, ['bbbbbbbb', 'aaaaaaaa']).badges.map((badge) => badge.text)).toEqual([
                'New',
                'Used',
            ]);
        });

        it('shows tags as badges', () => {
            const cell = describeValue(field('tags'), ['Tool', '<b>x</b>']);

            expect(cell.kind).toBe('badges');
            expect(cell.badges.map((badge) => badge.text)).toEqual(['Tool', '<b>x</b>']);
        });

        it('uses every color of the palette', () => {
            const colors = ['gray', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink'];
            const choices = colors.map((color, index) => ({ id: 'abcdefg' + index, label: color, color }));
            const palette = field('multiselect', { choices });
            const classes = describeValue(
                palette,
                choices.map((choice) => choice.id),
            ).badges.map((badge) => badge.class);

            expect(classes).toEqual([
                'badge bg-secondary-lt',
                'badge bg-red-lt',
                'badge bg-orange-lt',
                'badge bg-yellow-lt',
                'badge bg-green-lt',
                'badge bg-teal-lt',
                'badge bg-blue-lt',
                'badge bg-indigo-lt',
                'badge bg-purple-lt',
                'badge bg-pink-lt',
            ]);
        });
    });

    describe('links', () => {
        it('links a web address, in a new tab', () => {
            expect(describeValue(field('url'), 'https://example.com/a?b=c')).toMatchObject({
                kind: 'link',
                text: 'https://example.com/a?b=c',
                href: 'https://example.com/a?b=c',
                target: '_blank',
            });
            expect(describeValue(field('url'), 'http://example.com').kind).toBe('link');
        });

        it.each([
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            ' javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
            'ftp://example.com',
            'example.com',
            '//example.com',
        ])('does not link %j, which is not a web address: the text is shown, not a link', (value) => {
            const cell = describeValue(field('url'), value);

            expect(cell.kind).toBe('text');
            expect(cell.href).toBeNull();
            expect(cell.text).toBe(value);
        });

        it('canonicalizes a web address and nothing else', () => {
            expect(webAddress('HTTPS://EXAMPLE.com/Path')).toBe('https://example.com/Path');
            expect(webAddress('javascript:alert(1)')).toBeNull();
            expect(webAddress('')).toBeNull();
        });

        it('links an email address with mailto:', () => {
            expect(describeValue(field('email'), 'shop@example.com')).toMatchObject({
                kind: 'link',
                text: 'shop@example.com',
                href: 'mailto:shop@example.com',
                target: null,
            });
        });

        it('links a phone number with tel:, without the spaces and signs people write between the digits', () => {
            expect(describeValue(field('phone'), '+33 (0)1 23-45.67.89')).toMatchObject({
                kind: 'link',
                text: '+33 (0)1 23-45.67.89',
                href: 'tel:+330123456789',
            });
        });
    });

    describe('ratings and colors', () => {
        it('shows a rating as stars out of the maximum, with the grade as the tooltip', () => {
            expect(describeValue(field('rating', { max: 5 }), 4)).toMatchObject({
                kind: 'stars',
                text: '★★★★☆',
                title: '4 out of 5',
            });
            expect(describeValue(field('rating', { max: 10 }), 7).text).toBe('★★★★★★★☆☆☆');
            expect(describeValue(field('rating', {}), 1).text).toBe('★☆☆☆☆');
        });

        it('shows a color as a swatch', () => {
            expect(describeValue(field('color'), '#aabbcc')).toMatchObject({
                kind: 'swatch',
                text: '#aabbcc',
                style: { backgroundColor: '#aabbcc' },
            });
        });

        it('never puts anything but a hexadecimal color in a style', () => {
            const cell = describeValue(field('color'), 'red; background: url(//evil.example/x)');

            expect(cell.kind).toBe('text');
            expect(cell.style).toBeNull();
        });
    });
});

describe('typing a value', () => {
    describe('decimals', () => {
        const number = field('number', { decimals: 2 });

        it('sends a decimal typed with a comma or with a dot, with a dot', () => {
            expect(fromInput(number, '12,5')).toBe('12.5');
            expect(fromInput(number, '12.5')).toBe('12.5');
            expect(fromInput(field('money', { currency: 'EUR' }), '1 299,90')).toBe('1299.90');
            expect(fromInput(field('percentage', {}), '7,25')).toBe('7.25');
        });

        it('sends nothing for an empty box', () => {
            expect(fromInput(number, '')).toBeNull();
            expect(fromInput(number, '  ')).toBeNull();
        });

        it('puts a stored number in the box with the decimal separator of the language', () => {
            expect(toInput(number, '12.50')).toBe('12.50');

            document.documentElement.lang = 'fr';
            expect(toInput(number, '12.50')).toBe('12,50');
            expect(fromInput(number, toInput(number, '12.50'))).toBe('12.50');
        });
    });

    describe('dates', () => {
        it('keeps a day as it is', () => {
            expect(toInput(field('date'), '2026-10-15')).toBe('2026-10-15');
            expect(fromInput(field('date'), '2026-10-15')).toBe('2026-10-15');
            expect(fromInput(field('date'), '')).toBeNull();
        });

        it('shows a moment in the timezone of the browser and sends it in UTC', () => {
            inZone('Europe/Paris');
            expect(toInput(field('datetime'), '2026-10-15T12:30:00Z')).toBe('2026-10-15T14:30');
            expect(fromInput(field('datetime'), '2026-10-15T14:30')).toBe('2026-10-15T12:30:00Z');

            inZone('America/New_York');
            expect(toInput(field('datetime'), '2026-10-15T12:30:00Z')).toBe('2026-10-15T08:30');
            expect(fromInput(field('datetime'), '2026-10-15T08:30')).toBe('2026-10-15T12:30:00Z');
        });

        it('gives back the moment it was given, in every timezone', () => {
            const stored = '2026-03-29T00:30:00Z';

            for (const zone of ['Europe/Paris', 'America/Los_Angeles', 'Asia/Kolkata', 'Pacific/Auckland', 'UTC']) {
                inZone(zone);

                expect(fromInput(field('datetime'), toInput(field('datetime'), stored)), zone).toBe(stored);
            }
        });

        it('sends text that is no moment as it is, for the server to refuse', () => {
            expect(fromInput(field('datetime'), 'tomorrow')).toBe('tomorrow');
            expect(fromInput(field('datetime'), '')).toBeNull();
        });

        it('keeps the hours and minutes of a time, dropping seconds a box may add', () => {
            expect(fromInput(field('time'), '08:05')).toBe('08:05');
            expect(fromInput(field('time'), '08:05:30')).toBe('08:05');
            expect(fromInput(field('time'), '')).toBeNull();
        });
    });

    describe('yes / no', () => {
        const boolean = field('boolean');

        it('tells yes, no and nothing apart', () => {
            expect(toInput(boolean, true)).toBe('true');
            expect(toInput(boolean, false)).toBe('false');
            expect(toInput(boolean, undefined)).toBe('');
            expect(fromInput(boolean, 'true')).toBe(true);
            expect(fromInput(boolean, 'false')).toBe(false);
            expect(fromInput(boolean, '')).toBeNull();
        });

        it('offers a blank answer, yes and no', () => {
            expect(selectOptionsOf(boolean, { blank: 'Not set', yes: 'Yes', no: 'No' })).toEqual([
                { value: '', label: 'Not set' },
                { value: 'true', label: 'Yes' },
                { value: 'false', label: 'No' },
            ]);
        });
    });

    describe('choices', () => {
        it('offers the choices of a select after a blank one', () => {
            expect(selectOptionsOf(field('select', CHOICES), { blank: 'Not set', yes: 'Yes', no: 'No' })).toEqual([
                { value: '', label: 'Not set' },
                { value: 'aaaaaaaa', label: 'New' },
                { value: 'bbbbbbbb', label: 'Used' },
                { value: 'cccccccc', label: 'Broken' },
            ]);
        });

        it('sends the id of the choice, or nothing', () => {
            const select = field('select', CHOICES);

            expect(fromInput(select, 'aaaaaaaa')).toBe('aaaaaaaa');
            expect(fromInput(select, '')).toBeNull();
            expect(toInput(select, undefined)).toBe('');
        });

        it('sends the ids of the choices ticked, or nothing', () => {
            const multiselect = field('multiselect', CHOICES);

            expect(fromInput(multiselect, ['aaaaaaaa', 'cccccccc'])).toEqual(['aaaaaaaa', 'cccccccc']);
            expect(fromInput(multiselect, [])).toBeNull();
            expect(toInput(multiselect, ['aaaaaaaa'])).toEqual(['aaaaaaaa']);
            expect(toInput(multiselect, undefined)).toEqual([]);
        });

        it('hands out a copy of the ticked choices, so that the form cannot change the item', () => {
            const stored = ['aaaaaaaa'];

            toInput(field('multiselect', CHOICES), stored).push('bbbbbbbb');

            expect(stored).toEqual(['aaaaaaaa']);
        });

        it('sends tags trimmed and without the empty ones', () => {
            expect(fromInput(field('tags'), [' red ', '', 'blue'])).toEqual(['red', 'blue']);
            expect(fromInput(field('tags'), [' '])).toBeNull();
        });

        it('sends a grade as a number, or nothing', () => {
            const rating = field('rating', { max: 5 });

            expect(fromInput(rating, 4)).toBe(4);
            expect(fromInput(rating, null)).toBeNull();
            expect(toInput(rating, 3)).toBe(3);
            expect(toInput(rating, undefined)).toBeNull();
        });
    });

    describe('texts', () => {
        it('trims a text and sends nothing for a blank one', () => {
            expect(fromInput(field('text'), '  Hammer ')).toBe('Hammer');
            expect(fromInput(field('text'), '   ')).toBeNull();
            expect(fromInput(field('textarea'), '  line 1\nline 2\n')).toBe('line 1\nline 2');
            expect(fromInput(field('url'), ' https://example.com ')).toBe('https://example.com');
            expect(fromInput(field('barcode'), ' 4006381333931 ')).toBe('4006381333931');
            expect(fromInput(field('color'), '')).toBeNull();
        });
    });

    it('starts every control empty', () => {
        const empties = Object.fromEntries(Object.keys(FIELD_TYPES).map((type) => [type, emptyInput(field(type))]));

        expect(empties).toEqual({
            text: '',
            textarea: '',
            number: '',
            money: '',
            percentage: '',
            boolean: '',
            date: '',
            datetime: '',
            time: '',
            select: '',
            multiselect: [],
            tags: [],
            url: '',
            email: '',
            phone: '',
            rating: null,
            color: '',
            barcode: '',
        });
    });

    it('turns what the API sends into what the control holds and back, for every type', () => {
        inZone('Europe/Paris');
        const samples = {
            text: ['Hammer', {}],
            textarea: ['Line 1\nLine 2', {}],
            number: ['12.50', { decimals: 2 }],
            money: ['19.90', { currency: 'EUR' }],
            percentage: ['7.0', { decimals: 1 }],
            boolean: [false, {}],
            date: ['2026-10-15', {}],
            datetime: ['2026-10-15T12:30:00Z', {}],
            time: ['08:05', {}],
            select: ['aaaaaaaa', CHOICES],
            multiselect: [['aaaaaaaa', 'bbbbbbbb'], CHOICES],
            tags: [['Tool', 'Hand'], {}],
            url: ['https://example.com/hammer', {}],
            email: ['shop@example.com', {}],
            phone: ['+33 1 23 45 67 89', {}],
            rating: [4, { max: 5 }],
            color: ['#aabbcc', {}],
            barcode: ['4006381333931', { format: 'ean13' }],
        };

        expect(Object.keys(samples)).toEqual(Object.keys(FIELD_TYPES));

        for (const [type, [stored, options]] of Object.entries(samples)) {
            const typed = field(type, options);

            expect(fromInput(typed, toInput(typed, stored)), type).toEqual(stored);
        }
    });
});
