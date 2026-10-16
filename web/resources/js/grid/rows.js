/**
 * Small pieces of the rows of the table that are not about a field.
 */
import { describeValue } from '../fields/index.js';
import { dateFormat } from '../fields/locale.js';
import { isBlank } from '../fields/types.js';
import { t } from '../lib/i18n.js';

/** How many fields besides its title a card of the gallery shows. */
export const CARD_FIELDS = 5;

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

/**
 * @typedef {object} Card An item as the gallery shows it.
 * @property {string} id The id of the item.
 * @property {string} title The first text it holds, or "Untitled item".
 * @property {boolean} untitled Whether it holds no text to be called by.
 * @property {Record<string, any>[]} cells Up to {@link CARD_FIELDS} other fields that hold something, each with the cell of its value.
 */

/**
 * An item as a card of the gallery: its name (the first field of the text type that holds something) and a few other values
 * with their labels, in the order of the fields.
 *
 * @param {import('../fields/types.js').Field[]} fields
 * @param {{ id: string, values?: Record<string, any> }} item
 * @returns {Card}
 */
export function cardOf(fields, item) {
    const values = item.values ?? {};
    const named = fields.find((field) => field.type === 'text' && !isBlank(values[field.id]));
    const cells = fields
        .filter((field) => field !== named && !isBlank(values[field.id]))
        .slice(0, CARD_FIELDS)
        .map((field) => ({
            key: field.id,
            name: field.name,
            plain: false,
            ...describeValue(field, values[field.id]),
        }));

    return {
        id: item.id,
        title: named === undefined ? t('grid.card.untitled') : String(values[named.id]),
        untitled: named === undefined,
        cells,
    };
}
