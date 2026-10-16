/**
 * The field types as the interface uses them: what to show for a value, which control types it, and how a value goes
 * from the API to that control and back.
 *
 * The table of types lives in `types.js` (one entry per type of the server's registry); this module is the way in for
 * the components, and falls back to a plain text for a type it does not know, so that a field written by a newer
 * version of the application never breaks the page.
 */
import { cell, emptyCell } from './cell.js';
import { FALLBACK_TYPE, FIELD_TYPES, choicesOf, isBlank } from './types.js';

export { choicesOf, webAddress } from './types.js';

/**
 * @typedef {import('./types.js').Field} Field
 * @typedef {import('./types.js').Control} Control
 * @typedef {import('./cell.js').Cell} Cell
 */

/**
 * The definition of the type of a field.
 *
 * @param {Field} field
 * @returns {import('./types.js').FieldType}
 */
export function typeOf(field) {
    return FIELD_TYPES[field.type] ?? FALLBACK_TYPE;
}

/**
 * How the server sorts and filters a field (see `QueryKind` in `types.js`): what the API says of it (`query_kind`), so that
 * the interface never has to know the types the server has. Null for a field the server cannot query (a type it does not
 * know, after a rollback): the interface still shows it, as a text, but neither sorts nor filters it, which it would be
 * guessing at.
 *
 * @param {Field} field
 * @returns {import('./types.js').QueryKind | null}
 */
export function queryKindOf(field) {
    return field.query_kind ?? null;
}

/**
 * Whether the list can be sorted by a field: all but the ones that hold several values (several choices, tags) and the ones
 * the server cannot query.
 *
 * @param {Field} field
 * @returns {boolean}
 */
export function isSortable(field) {
    const kind = queryKindOf(field);

    return kind !== null && kind !== 'choices' && kind !== 'tags';
}

/**
 * Whether the list can be filtered by a field: all but the ones the server cannot query.
 *
 * @param {Field} field
 * @returns {boolean}
 */
export function isFilterable(field) {
    return queryKindOf(field) !== null;
}

/**
 * Whether the totals of a list add the values of a field up (numbers and amounts): what the API says of it (`summable`).
 *
 * @param {Field} field
 * @returns {boolean}
 */
export function isSummable(field) {
    return field.summable === true;
}

/**
 * Which control the form shows for a field.
 *
 * @param {Field} field
 * @returns {Control}
 */
export function controlOf(field) {
    return typeOf(field).control;
}

/**
 * The `type` of the `<input>` of a field (`text`, `date`, `url`...).
 *
 * @param {Field} field
 * @returns {string}
 */
export function inputTypeOf(field) {
    return typeOf(field).inputType ?? 'text';
}

/**
 * The `inputmode` of the `<input>` of a field (`decimal` shows a numeric keyboard on a phone), or null for the default one.
 *
 * @param {Field} field
 * @returns {string | null}
 */
export function inputModeOf(field) {
    return typeOf(field).inputMode ?? null;
}

/**
 * The longest text the `<input>` of a field takes, or null when it has no limit of its own.
 *
 * @param {Field} field
 * @returns {number | null}
 */
export function maxLengthOf(field) {
    return field.type === 'text' ? Number(field.options?.max_length ?? 255) : null;
}

/**
 * What a control holds when its field has no value.
 *
 * @param {Field} field
 * @returns {any}
 */
export function emptyInput(field) {
    return typeOf(field).toInput(null, field);
}

/**
 * The value of a control for a value the API gave.
 *
 * @param {Field} field
 * @param {any} value The value of the field in an item (undefined when the item has none).
 * @returns {any}
 */
export function toInput(field, value) {
    return isBlank(value) ? emptyInput(field) : typeOf(field).toInput(value, field);
}

/**
 * The value to send to the API for what a control holds: null for nothing, which clears the field.
 *
 * @param {Field} field
 * @param {any} input What the control holds.
 * @returns {any}
 */
export function fromInput(field, input) {
    return typeOf(field).fromInput(input, field);
}

/**
 * How a value is shown, as a {@link Cell}.
 *
 * @param {Field} field
 * @param {any} value The value of the field in an item (undefined when the item has none).
 * @param {import('./types.js').DescribeOptions} [options] `{ full: true }` for the details of an item: nothing is cut short.
 * @returns {Cell}
 */
export function describeValue(field, value, options = {}) {
    if (isBlank(value)) {
        return emptyCell();
    }

    try {
        return typeOf(field).describe(value, field, options);
    } catch {
        // A value the type cannot make sense of (stored by hand, say) is shown as it is rather than breaking the table.
        return cell('text', { text: String(value) });
    }
}

/**
 * The options of the `<select>` of a field: the two answers of a yes / no, or the choices of a select, after a blank one.
 *
 * @param {Field} field
 * @param {{ blank: string, yes: string, no: string }} labels The texts of the blank option and of the two answers.
 * @returns {{ value: string, label: string }[]}
 */
export function selectOptionsOf(field, labels) {
    const options = [{ value: '', label: labels.blank }];

    if (field.type === 'boolean') {
        options.push({ value: 'true', label: labels.yes }, { value: 'false', label: labels.no });
    } else {
        options.push(...choicesOf(field).map((choice) => ({ value: choice.id, label: choice.label })));
    }

    return options;
}
