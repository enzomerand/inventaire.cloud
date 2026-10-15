/**
 * Small pieces of the rows of the table that are not about a field.
 */
import { dateFormat } from '../fields/locale.js';

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
