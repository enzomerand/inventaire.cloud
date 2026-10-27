import { afterEach, describe, expect, it } from 'vitest';

import {
    decimalScale,
    decimalSeparator,
    formatDecimal,
    formatMoney,
    formatPercentage,
    isDecimalText,
    parseDecimal,
    shiftDecimalLeft,
    toDecimalInput,
} from '../../../resources/js/fields/decimal.js';

/** Replaces the spaces the formatters use (no-break, narrow no-break) by plain ones, to compare text. */
const plain = (text) => text.replace(/[\u00a0\u202f]/g, ' ');

describe('parseDecimal()', () => {
    it.each([
        ['12.5', '12.5'],
        ['12,5', '12.5'],
        ['-0,25', '-0.25'],
        ['  42  ', '42'],
        ['1 234,5', '1234.5'],
        ['1\u00a0234,5', '1234.5'],
        ['1\u202f234.5', '1234.5'],
        ["1'234.5", '1234.5'],
        ['1,234.5', '1234.5'],
        ['1.234,5', '1234.5'],
        ['1,234,567.89', '1234567.89'],
    ])('reads %j as %j, with a dot and no grouping', (typed, sent) => {
        expect(parseDecimal(typed)).toBe(sent);
    });

    it('returns null for nothing at all', () => {
        expect(parseDecimal('')).toBeNull();
        expect(parseDecimal('   ')).toBeNull();
        expect(parseDecimal(null)).toBeNull();
        expect(parseDecimal(undefined)).toBeNull();
    });

    it('lets text that is not a number through, for the server to refuse with a message', () => {
        expect(parseDecimal('12abc')).toBe('12abc');
        expect(parseDecimal('1e5')).toBe('1e5');
    });
});

describe('isDecimalText()', () => {
    it.each(['0', '12', '12.5', '-3', '-0.50', '007', '1234567890123456789.123456789'])(
        'takes %j for a number',
        (text) => {
            expect(isDecimalText(text)).toBe(true);
        },
    );

    it.each(['', '-', 'abc', '12abc', '1.2.3', '12.', '.5', '+5', '--1', '1e3', '12,5', ' 12', '١٢'])(
        'takes %j for something else',
        (text) => {
            expect(isDecimalText(text)).toBe(false);
        },
    );

    it('judges what parseDecimal() makes of a typed text', () => {
        expect(isDecimalText(parseDecimal('1 299,90'))).toBe(true);
        expect(isDecimalText(parseDecimal('12 euros'))).toBe(false);
    });
});

describe('shiftDecimalLeft()', () => {
    it.each([
        ['12.5', 2, '0.125'],
        ['5', 2, '0.05'],
        ['100', 2, '1.00'],
        ['1234.5', 2, '12.345'],
        ['-7.25', 2, '-0.0725'],
        ['0', 2, '0.00'],
    ])('moves the point of %s by %d places: %s', (value, places, shifted) => {
        expect(shiftDecimalLeft(value, places)).toBe(shifted);
    });
});

describe('with the language of the page', () => {
    afterEach(() => {
        document.documentElement.lang = 'en';
    });

    it('shows a number with the decimals it was written with when the field has fewer now: nothing is rounded', () => {
        document.documentElement.lang = 'en';

        expect(formatDecimal('12.50', 0)).toBe('12.50');
        expect(formatDecimal('12.567', 2)).toBe('12.567');
        expect(formatDecimal('12.5', 2)).toBe('12.50');
        expect(formatDecimal('1234', 0)).toBe('1,234');
        expect(formatMoney('12.567', 'EUR', 2)).toBe('€12.567');
        expect(formatMoney('12.50', 'JPY')).toBe('¥12.50');
        expect(formatMoney('12.5', 'EUR', 3)).toBe('€12.500');
        expect(formatMoney('12', 'JPY', 0)).toBe('¥12');
        expect(formatPercentage('12.567', 1)).toBe('12.567%');
        expect(formatPercentage('12.5', 3)).toBe('12.500%');
    });

    it('counts the decimals a stored number really has', () => {
        expect(decimalScale('12.50')).toBe(2);
        expect(decimalScale('7')).toBe(0);
        expect(decimalScale('-0.125')).toBe(3);
    });

    it('reads a comma followed by exactly three digits as a thousands separator where the decimal one is the dot', () => {
        document.documentElement.lang = 'en';

        expect(parseDecimal('1,234')).toBe('1234');
        expect(parseDecimal('-1,234')).toBe('-1234');
        expect(parseDecimal('12,345,678')).toBe('12345678');
        expect(parseDecimal('1,234', 'en')).toBe('1234');
        expect(parseDecimal('1,23')).toBe('1.23');
        expect(parseDecimal('1,2345')).toBe('1.2345');
        expect(parseDecimal('0,500')).toBe('0.500');
        expect(parseDecimal('1,2')).toBe('1.2');
    });

    it('reads that same comma as a decimal separator where the decimal one is the comma', () => {
        document.documentElement.lang = 'fr';

        expect(parseDecimal('1,234')).toBe('1.234');
        expect(parseDecimal('12,345,678')).toBe('12.345,678');
        expect(parseDecimal('1,234', 'fr')).toBe('1.234');
        expect(parseDecimal('1,234', 'en')).toBe('1234');
        expect(parseDecimal('12,5')).toBe('12.5');
    });

    it('knows the decimal separator of a language', () => {
        expect(decimalSeparator('en')).toBe('.');
        expect(decimalSeparator('fr')).toBe(',');
        expect(decimalSeparator('not a language')).toBe('.');
    });

    it('writes a stored number for a box with the separator of the language', () => {
        expect(toDecimalInput('12.50', 'fr')).toBe('12,50');
        expect(toDecimalInput('12.50', 'en')).toBe('12.50');
        expect(toDecimalInput(undefined, 'fr')).toBe('');
    });

    it('formats numbers with exactly the decimals asked for', () => {
        document.documentElement.lang = 'en';
        expect(formatDecimal('1234.50', 2)).toBe('1,234.50');
        expect(formatDecimal('7', 0)).toBe('7');

        document.documentElement.lang = 'fr';
        expect(plain(formatDecimal('1234.50', 2))).toBe('1 234,50');
    });

    it('keeps every digit of a number a floating point could not hold', () => {
        document.documentElement.lang = 'en';

        expect(formatDecimal('123456789012345.123456', 6)).toBe('123,456,789,012,345.123456');
    });

    it('formats an amount with the currency style of the language', () => {
        document.documentElement.lang = 'en';
        expect(formatMoney('1234.5', 'EUR')).toBe('€1,234.50');
        expect(formatMoney('1234', 'JPY')).toBe('¥1,234');

        document.documentElement.lang = 'fr';
        expect(plain(formatMoney('1234.5', 'EUR'))).toBe('1 234,50 €');
    });

    it('falls back to the number and the code for a currency the browser does not know', () => {
        expect(formatMoney('10.00', 'not a currency')).toBe('10.00 not a currency');
    });

    it('formats a percentage with the percent sign of the language, without a floating point error', () => {
        document.documentElement.lang = 'en';
        expect(formatPercentage('12.5', 1)).toBe('12.5%');
        expect(formatPercentage('0.07', 2)).toBe('0.07%');

        document.documentElement.lang = 'fr';
        expect(plain(formatPercentage('12.5', 1))).toBe('12,5 %');

        document.documentElement.lang = 'tr';
        expect(formatPercentage('12.5', 1)).toBe('%12,5');
    });
});
