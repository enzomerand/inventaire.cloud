/**
 * Small pieces of the rows of the table that are not about a field.
 */
import { dateFormat } from '../fields/locale.js';
import { t } from '../lib/i18n.js';

/**
 * A moment the API gave (an item's `created_at`, `updated_at`) as the person reads it, in the timezone of the browser.
 *
 * @param {unknown} value The moment in ISO 8601, in UTC.
 * @returns {string} "" when there is none, or it is not a moment.
 */
export function momentText(value) {
    const date = typeof value === 'string' ? new Date(value) : null;

    return date === null || Number.isNaN(date.getTime())
        ? ''
        : dateFormat({ dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

/**
 * What identifies the cell of a field in the row of an item, for the markup (`data-cell`) and for finding it again.
 *
 * @param {string} rowId The id of the item.
 * @param {string} fieldId The id of the field.
 * @returns {string}
 */
export function cellKey(rowId, fieldId) {
    return `${rowId}:${fieldId}`;
}

/**
 * What a cell says, as a sentence for assistive technology: the text of a value, the badges one after the other, "Not set"
 * for a cell that holds nothing.
 *
 * @param {import('../fields/cell.js').Cell} cell
 * @returns {string}
 */
export function cellValueText(cell) {
    switch (cell.kind) {
        case 'empty':
            return t('inventories.item.none');
        case 'badges':
            return cell.badges.map((badge) => badge.text).join(', ');
        case 'stars':
            return cell.title ?? cell.text;
        default:
            return cell.text;
    }
}

/**
 * Where the cursor goes when the dialog of an item is opened for one field: the first box, select or check box of the field
 * (not the buttons that remove a tag), else its first button (the stars of a rating, the button of a color).
 *
 * @param {string} fieldId
 * @returns {string[]} CSS selectors, in order of preference.
 */
export function fieldFocusSelectors(fieldId) {
    const field = `[data-field="${fieldId.replace(/["\\]/g, '\\$&')}"]`;

    return [
        `${field} :is(input:not([type="hidden"]):not([type="color"]), select, textarea)`,
        `${field} button:not(.btn-close)`,
    ];
}
