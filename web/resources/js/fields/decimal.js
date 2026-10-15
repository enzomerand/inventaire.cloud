/**
 * Decimal numbers, kept as text.
 *
 * The API stores a number, an amount and a percentage as a string with a fixed count of decimals (`"12.50"`): a
 * floating point number would round `0.1 + 0.2`. This module never turns them into a `Number` either: it reads what a
 * person types (a comma or a dot, spaces between groups of digits) into the text the API accepts, shows a stored text
 * with the decimal separator of the language, and moves the decimal point for percentages, all on the digits.
 */
import { numberFormat } from './locale.js';

/** Characters people put between groups of digits: spaces (also the non-breaking ones) and the Swiss apostrophe. */
const GROUPING = /[\s\u00a0\u202f'\u2019]/g;

/**
 * Reads what was typed in a number box into the text the API accepts: a dot for the decimal separator, no grouping.
 *
 * A comma or a dot is the decimal separator; when both are typed (`1,234.50` or `1.234,50`) the last one is, and the
 * other one groups thousands. Text that is not a number is returned as it is (cleaned of spaces): the server judges it
 * and answers with a message the person can read.
 *
 * @param {unknown} input What was typed.
 * @returns {string | null} The text to send, or null when nothing was typed.
 */
export function parseDecimal(input) {
    if (typeof input !== 'string') {
        return null;
    }

    let text = input.replace(GROUPING, '');

    if (text === '') {
        return null;
    }

    const comma = text.lastIndexOf(',');
    const dot = text.lastIndexOf('.');

    if (comma !== -1 && dot !== -1) {
        // The separator typed last is the decimal one; the other groups the thousands.
        text = comma > dot ? text.replaceAll('.', '').replace(',', '.') : text.replaceAll(',', '');
    } else if (comma !== -1) {
        text = text.replace(',', '.');
    }

    return text;
}

/**
 * The decimal separator of a language ("," in French, "." in English).
 *
 * @param {string} locale
 * @returns {string}
 */
export function decimalSeparator(locale) {
    try {
        return new Intl.NumberFormat(locale).formatToParts(1.1).find((part) => part.type === 'decimal')?.value ?? '.';
    } catch {
        return '.';
    }
}

/**
 * Writes a stored number for a number box, with the decimal separator of the language (the box accepts both).
 *
 * @param {unknown} value The stored text (`"12.50"`).
 * @param {string} locale
 * @returns {string}
 */
export function toDecimalInput(value, locale) {
    return typeof value === 'string' ? value.replace('.', decimalSeparator(locale)) : '';
}

/**
 * Moves the decimal point of a number written as text, to the left, without ever leaving the digits: `"12.5"` shifted by
 * two places is `"0.125"`.
 *
 * @param {string} value A number written with a dot.
 * @param {number} places How many places to the left.
 * @returns {string}
 */
export function shiftDecimalLeft(value, places) {
    const negative = value.startsWith('-');
    const [integer, fraction = ''] = (negative ? value.slice(1) : value).split('.');
    const digits = integer + fraction;
    const point = integer.length - places;
    const shifted =
        point <= 0 ? '0.' + '0'.repeat(-point) + digits : digits.slice(0, point) + '.' + digits.slice(point);

    return (negative ? '-' : '') + shifted;
}

/**
 * Formats a stored number with the given count of decimals in the language of the page, grouping thousands.
 *
 * @param {string} value The stored text.
 * @param {number} decimals Count of decimals to show.
 * @returns {string}
 */
export function formatDecimal(value, decimals) {
    return numberFormat({ minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(
        /** @type {any} */ (value),
    );
}

/**
 * Formats a stored amount as money in a currency, in the language of the page (`1 234,50 €`).
 *
 * @param {string} value The stored text.
 * @param {string} currency ISO 4217 code.
 * @returns {string} The formatted amount, or the number and the code when the browser does not know the currency.
 */
export function formatMoney(value, currency) {
    try {
        return numberFormat({ style: 'currency', currency }).format(/** @type {any} */ (value));
    } catch {
        return value + ' ' + currency;
    }
}

/**
 * Formats a stored percentage (the number before the sign: `"12.5"` is 12.5 %) with the percent sign of the language
 * (`12,5 %` in French, `%12,5` in Turkish).
 *
 * @param {string} value The stored text.
 * @param {number} decimals Count of decimals to show.
 * @returns {string}
 */
export function formatPercentage(value, decimals) {
    return numberFormat({ style: 'percent', minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(
        /** @type {any} */ (shiftDecimalLeft(value, 2)),
    );
}
