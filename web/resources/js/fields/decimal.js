/**
 * Decimal numbers, kept as text.
 *
 * The API stores a number, an amount and a percentage as a string with a fixed count of decimals (`"12.50"`): a
 * floating point number would round `0.1 + 0.2`. This module never turns them into a `Number` either: it reads what a
 * person types (a comma or a dot, spaces between groups of digits) into the text the API accepts, shows a stored text
 * with the decimal separator of the language, and moves the decimal point for percentages, all on the digits.
 */
import { numberFormat, pageLocale } from './locale.js';

/** Characters people put between groups of digits: spaces (also the non-breaking ones) and the Swiss apostrophe. */
const GROUPING = /[\s\u00a0\u202f'\u2019]/g;

/**
 * Reads what was typed in a number box into the text the API accepts: a dot for the decimal separator, no grouping.
 *
 * A comma or a dot is the decimal separator; when both are typed (`1,234.50` or `1.234,50`) the last one is, and the
 * other one groups thousands. A comma alone is a decimal separator too, except in a language whose own separator is the
 * dot, where commas that only ever separate groups of exactly three digits (`1,234`, `12,345,678`; not `0,500`) group
 * thousands: `1,234` is one thousand two hundred and thirty-four for an English reader, and a little over one for a French one.
 * Text that is not a number is returned as it is (cleaned of spaces): the server judges it and answers with a message
 * the person can read.
 *
 * @param {unknown} input What was typed.
 * @param {string} [locale] The language of the person, which tells what a lone comma means; the one of the page by default.
 * @returns {string | null} The text to send, or null when nothing was typed.
 */
export function parseDecimal(input, locale = pageLocale()) {
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
        text =
            decimalSeparator(locale) === '.' && /^-?[1-9]\d{0,2}(,\d{3})+$/.test(text)
                ? text.replaceAll(',', '')
                : text.replace(',', '.');
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
 * How many decimals a stored number really has: `"12.50"` has two, `"7"` none.
 *
 * @param {unknown} value The stored text.
 * @returns {number}
 */
export function decimalScale(value) {
    return String(value).split('.')[1]?.length ?? 0;
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
 * Formats a stored number with the decimals of its field in the language of the page, grouping thousands.
 *
 * A value keeps the decimals it was written with when the field's option is lowered afterwards (the API never rounds a
 * stored value): it is shown with the most of the two, never rounded.
 *
 * @param {string} value The stored text.
 * @param {number} decimals Count of decimals the field has now.
 * @returns {string}
 */
export function formatDecimal(value, decimals) {
    const digits = Math.max(decimals, decimalScale(value));

    return numberFormat({ minimumFractionDigits: digits, maximumFractionDigits: digits }).format(
        /** @type {any} */ (value),
    );
}

/**
 * Formats a stored amount as money in a currency, in the language of the page (`1 234,50 €`).
 *
 * The decimals are those of the field (or the currency's own when the field does not say), and a value keeps the decimals
 * it was written with if the field has fewer now: it is never rounded.
 *
 * @param {string} value The stored text.
 * @param {string} currency ISO 4217 code.
 * @param {number | null} [decimals] Count of decimals the field has now; the currency's own when null.
 * @returns {string} The formatted amount, or the number and the code when the browser does not know the currency.
 */
export function formatMoney(value, currency, decimals = null) {
    try {
        const own = numberFormat({ style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
        const digits = Math.max(decimals ?? own, decimalScale(value));

        return numberFormat({
            style: 'currency',
            currency,
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(/** @type {any} */ (value));
    } catch {
        return value + ' ' + currency;
    }
}

/**
 * Formats a stored percentage (the number before the sign: `"12.5"` is 12.5 %) with the percent sign of the language
 * (`12,5 %` in French, `%12,5` in Turkish).
 *
 * @param {string} value The stored text.
 * @param {number} decimals Count of decimals the field has now; a value keeps the decimals it was written with if it has fewer.
 * @returns {string}
 */
export function formatPercentage(value, decimals) {
    const digits = Math.max(decimals, decimalScale(value));

    return numberFormat({ style: 'percent', minimumFractionDigits: digits, maximumFractionDigits: digits }).format(
        /** @type {any} */ (shiftDecimalLeft(value, 2)),
    );
}
