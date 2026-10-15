import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    blankDraft,
    chipsOf,
    draftOf,
    filterCount,
    filterFromDraft,
    hasAnswer,
    hasChoiceList,
    hasRange,
    labelOf,
    rangeInputMode,
    rangeInputType,
} from '../../../resources/js/grid/filters.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

/** A field of an inventory. */
function field(id, type, options = {}, name = 'Field ' + id) {
    return { id, name, type, options, required: false, position: 0 };
}

const STATE = field(
    'state',
    'select',
    {
        choices: [
            { id: 'aaaaaaaa', label: 'New', color: 'green' },
            { id: 'bbbbbbbb', label: 'Used' },
            { id: 'cccccccc', label: 'Broken' },
        ],
    },
    'State',
);
const PARTS = field(
    'parts',
    'multiselect',
    {
        choices: [
            { id: 'dddddddd', label: 'Red' },
            { id: 'eeeeeeee', label: 'Blue' },
        ],
    },
    'Parts',
);
const PRICE = field('price', 'money', { currency: 'EUR' }, 'Price');
const WEIGHT = field('weight', 'number', { decimals: 2, unit: 'kg' }, 'Weight');
const GRADE = field('grade', 'rating', { max: 5 }, 'Grade');
const BOUGHT = field('bought', 'date', {}, 'Bought');
const SEEN = field('seen', 'datetime', {}, 'Seen');
const OPENS = field('opens', 'time', {}, 'Opens');
const FRAGILE = field('fragile', 'boolean', {}, 'Fragile');
const NAME = field('name', 'text', { max_length: 100 }, 'Name');
const LABELS = field('labels', 'tags', {}, 'Labels');

/** The local form a moment is typed in, and the canonical form it is sent in (UTC, to the second). */
function utc(local, seconds = '00') {
    return new Date(local).toISOString().replace(/:\d{2}\.\d{3}Z$/, `:${seconds}Z`);
}

/** The spaces the formatters use (no-break, narrow no-break) written as plain ones, to compare text. */
const plain = (text) => text.replace(/[\u00a0\u202f]/g, ' ');

describe('the filters of the list of items', () => {
    beforeEach(() => {
        document.documentElement.lang = 'en';
    });

    describe('what each kind of field is filtered by', () => {
        it('gives a lowest and a highest value to numbers, amounts, ratings, dates, moments and times', () => {
            for (const one of [WEIGHT, PRICE, GRADE, BOUGHT, SEEN, OPENS, field('rate', 'percentage')]) {
                expect(hasRange(one), one.type).toBe(true);
                expect(hasAnswer(one), one.type).toBe(false);
                expect(hasChoiceList(one), one.type).toBe(false);
            }
        });

        it('gives an answer to a yes / no, and a list of choices to a choice or several', () => {
            expect(hasAnswer(FRAGILE)).toBe(true);
            expect(hasChoiceList(STATE)).toBe(true);
            expect(hasChoiceList(PARTS)).toBe(true);
            expect(hasRange(FRAGILE)).toBe(false);
        });

        it('leaves a text, a tag, a link and the like with the question of whether they hold a value', () => {
            for (const one of [NAME, LABELS, field('site', 'url'), field('mail', 'email'), field('tint', 'color')]) {
                expect(hasRange(one) || hasAnswer(one) || hasChoiceList(one), one.type).toBe(false);
            }
        });

        it('types a number in a plain box (the language of the person decides the decimal sign) and a date in a date box', () => {
            expect(rangeInputType(PRICE)).toBe('text');
            expect(rangeInputMode(PRICE)).toBe('decimal');
            expect(rangeInputType(GRADE)).toBe('text');
            expect(rangeInputType(BOUGHT)).toBe('date');
            expect(rangeInputType(SEEN)).toBe('datetime-local');
            expect(rangeInputType(OPENS)).toBe('time');
            expect(rangeInputMode(BOUGHT)).toBeNull();
        });
    });

    describe('from the boxes of the panel to the filter', () => {
        it('reads a number the way it is typed, with a comma or a dot, into the canonical form', () => {
            const { filter, errors } = filterFromDraft(PRICE, { ...blankDraft(), min: '89,90', max: ' 1 200.5 ' });

            expect(filter).toEqual({ min: '89.90', max: '1200.5' });
            expect(errors).toEqual({});
        });

        it('reads a comma of a thousands group as the language of the page does', () => {
            expect(filterFromDraft(PRICE, { ...blankDraft(), min: '1,234' }).filter).toEqual({ min: '1234' });

            document.documentElement.lang = 'fr';

            expect(filterFromDraft(PRICE, { ...blankDraft(), min: '1,234' }).filter).toEqual({ min: '1.234' });
        });

        it('refuses what is no number, or more than the server takes, and says which box', () => {
            const { filter, errors } = filterFromDraft(WEIGHT, { ...blankDraft(), min: 'abc', max: '1.1234567' });

            expect(filter).toBeNull();
            expect(errors).toEqual({
                min: 'Enter a number with at most 6 decimals.',
                max: 'Enter a number with at most 6 decimals.',
            });
            expect(filterFromDraft(WEIGHT, { ...blankDraft(), min: '1000000000000000' }).errors.min).toBeDefined();
            expect(filterFromDraft(WEIGHT, { ...blankDraft(), min: '-5' }).filter).toEqual({ min: '-5' });
        });

        it('keeps one bound when the other box is empty', () => {
            expect(filterFromDraft(WEIGHT, { ...blankDraft(), max: '7' }).filter).toEqual({ max: '7' });
            expect(filterFromDraft(WEIGHT, { ...blankDraft(), min: '  ' }).filter).toBeNull();
        });

        it('keeps a day as it is, and a time to the minute', () => {
            expect(filterFromDraft(BOUGHT, { ...blankDraft(), min: '2026-10-01', max: '2026-10-31' }).filter).toEqual({
                min: '2026-10-01',
                max: '2026-10-31',
            });
            expect(filterFromDraft(OPENS, { ...blankDraft(), min: '09:30:15', max: '18:00' }).filter).toEqual({
                min: '09:30',
                max: '18:00',
            });
        });

        it('sends a moment in UTC to the second, the highest one running to the end of its minute', () => {
            const { filter } = filterFromDraft(SEEN, {
                ...blankDraft(),
                min: '2026-10-15T14:30',
                max: '2026-10-15T16:45',
            });

            expect(filter).toEqual({ min: utc('2026-10-15T14:30'), max: utc('2026-10-15T16:45', '59') });
            expect(filter.min).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/);
            expect(filter.max).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:59Z$/);
        });

        it('refuses a moment or a day nobody can read', () => {
            expect(filterFromDraft(SEEN, { ...blankDraft(), min: 'tomorrow' }).errors.min).toBe(
                'This value is not valid.',
            );
            expect(filterFromDraft(BOUGHT, { ...blankDraft(), max: '15/10/2026' }).errors.max).toBe(
                'This value is not valid.',
            );
        });

        it('reads the answer of a yes / no', () => {
            expect(filterFromDraft(FRAGILE, { ...blankDraft(), is: 'true' }).filter).toEqual({ is: true });
            expect(filterFromDraft(FRAGILE, { ...blankDraft(), is: 'false' }).filter).toEqual({ is: false });
            expect(filterFromDraft(FRAGILE, blankDraft()).filter).toBeNull();
        });

        it('lists the ticked choices in the order of the choices, and drops the ones the field no longer has', () => {
            expect(filterFromDraft(STATE, { ...blankDraft(), in: ['cccccccc', 'aaaaaaaa', 'gone'] }).filter).toEqual({
                in: ['aaaaaaaa', 'cccccccc'],
            });
            expect(filterFromDraft(PARTS, { ...blankDraft(), in: ['gone'] }).filter).toBeNull();
        });

        it('asks for the items with no value, whatever else the boxes hold', () => {
            expect(filterFromDraft(PRICE, { ...blankDraft(), presence: 'empty', min: '10' }).filter).toEqual({
                empty: true,
            });
            expect(filterFromDraft(NAME, { ...blankDraft(), presence: 'empty' }).filter).toEqual({ empty: true });
            expect(filterFromDraft(LABELS, { ...blankDraft(), presence: 'empty' }).filter).toEqual({ empty: true });
        });

        it('asks for the items with a value only when nothing else is asked, since any bound needs one already', () => {
            expect(filterFromDraft(NAME, { ...blankDraft(), presence: 'filled' }).filter).toEqual({ empty: false });
            expect(filterFromDraft(PRICE, { ...blankDraft(), presence: 'filled', min: '10' }).filter).toEqual({
                min: '10',
            });
            expect(filterFromDraft(FRAGILE, { ...blankDraft(), presence: 'filled', is: 'true' }).filter).toEqual({
                is: true,
            });
        });

        it('has no filter for a field whose boxes are left alone', () => {
            for (const one of [NAME, PRICE, FRAGILE, STATE, LABELS]) {
                expect(filterFromDraft(one, blankDraft()), one.type).toEqual({ filter: null, errors: {} });
            }
        });
    });

    describe('from the filter back to the boxes of the panel', () => {
        it('shows a number with the decimal sign of the language of the page', () => {
            document.documentElement.lang = 'fr';

            expect(draftOf(PRICE, { min: '89.90', max: '120' })).toMatchObject({ min: '89,90', max: '120' });
        });

        it('shows a moment in the timezone of the browser, to the minute', () => {
            expect(draftOf(SEEN, { min: utc('2026-10-15T14:30') }).min).toBe('2026-10-15T14:30');
        });

        it('shows the presence, the answer and the ticked choices', () => {
            expect(draftOf(NAME, { empty: true }).presence).toBe('empty');
            expect(draftOf(NAME, { empty: false }).presence).toBe('filled');
            expect(draftOf(FRAGILE, { is: false }).is).toBe('false');
            expect(draftOf(STATE, { in: ['aaaaaaaa'] }).in).toEqual(['aaaaaaaa']);
            expect(draftOf(NAME, undefined)).toEqual(blankDraft());
        });

        it('does not share the list of choices with the filter it came from', () => {
            const filter = { in: ['aaaaaaaa'] };

            draftOf(STATE, filter).in.push('bbbbbbbb');

            expect(filter.in).toEqual(['aaaaaaaa']);
        });

        it.each([
            [PRICE, { min: '89.90', max: '120' }],
            [WEIGHT, { max: '0.5' }],
            [GRADE, { min: '3' }],
            [BOUGHT, { min: '2026-10-01', max: '2026-10-31' }],
            [OPENS, { min: '09:30' }],
            [FRAGILE, { is: true }],
            [STATE, { in: ['aaaaaaaa', 'bbbbbbbb'] }],
            [NAME, { empty: true }],
            [NAME, { empty: false }],
        ])('gives the same filter back for %j', (one, filter) => {
            expect(filterFromDraft(one, draftOf(one, filter)).filter).toEqual(filter);
        });

        it('gives the same moment back, the highest one at the end of its minute', () => {
            const filter = { min: utc('2026-10-15T14:30'), max: utc('2026-10-15T16:45', '59') };

            expect(filterFromDraft(SEEN, draftOf(SEEN, filter)).filter).toEqual(filter);
        });
    });

    describe('as chips', () => {
        it('says a range, formatted like the values of the field', () => {
            expect(plain(labelOf(PRICE, { min: '10', max: '50' }))).toBe('Price: €10.00 – €50.00');
            expect(labelOf(WEIGHT, { min: '1.5' })).toBe('Weight: from 1.50 kg');
            expect(labelOf(WEIGHT, { max: '2' })).toBe('Weight: up to 2.00 kg');
            expect(labelOf(BOUGHT, { min: '2026-10-15' })).toBe('Bought: from Oct 15, 2026');
        });

        it('says the grade of a rating as a number, not as stars', () => {
            expect(labelOf(GRADE, { min: '3', max: '5' })).toBe('Grade: 3 – 5');
        });

        it('says an answer, the choices by their labels, and presence', () => {
            expect(labelOf(FRAGILE, { is: true })).toBe('Fragile: Yes');
            expect(labelOf(FRAGILE, { is: false })).toBe('Fragile: No');
            expect(labelOf(STATE, { in: ['aaaaaaaa', 'bbbbbbbb'] })).toBe('State: New, Used');
            expect(labelOf(NAME, { empty: true })).toBe('Name: empty');
            expect(labelOf(NAME, { empty: false })).toBe('Name: not empty');
        });

        it('says the id of a choice the field no longer has rather than nothing', () => {
            expect(labelOf(STATE, { in: ['gone'] })).toBe('State: gone');
        });

        it('has one chip for each filtered field, in the order of the fields, with the name of its remove button', () => {
            const chips = chipsOf([NAME, PRICE, STATE], { state: { in: ['aaaaaaaa'] }, name: { empty: true } });

            expect(chips.map((chip) => chip.label)).toEqual(['Name: empty', 'State: New']);
            expect(chips.map((chip) => chip.fieldId)).toEqual(['name', 'state']);
            expect(chips[0].removeLabel).toBe('Remove the filter Name: empty');
        });

        it('shows no chip for a field the inventory no longer has, nor for a filter that asks for nothing', () => {
            expect(chipsOf([NAME], { gone: { empty: true } })).toEqual([]);
            expect(chipsOf([NAME], { name: {} })).toEqual([]);
        });

        it('counts the filtered fields', () => {
            expect(filterCount({})).toBe(0);
            expect(filterCount({ a: { empty: true }, b: { min: '1' } })).toBe(2);
        });
    });
});
