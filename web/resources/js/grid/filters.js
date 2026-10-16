/**
 * The filters of the list of items, between what the panel holds and what the API takes.
 *
 * The panel works on a {@link Draft} for each field: the text of the boxes, the answer of a select, the ticked choices.
 * Applying it turns every draft into a {@link import('./query.js').Filter} in canonical form (a number with a dot, a moment
 * in UTC), or says what is wrong with a box; opening the panel does the opposite. The filters are also shown as chips.
 *
 * Which conditions a field takes follows the kind the server sorts and filters it as (`queryKind`, see `fields/types.js`):
 * a number or a date takes a lowest and a highest value, a yes / no an answer, a choice a list of choices, and every kind
 * can be asked for its items that hold nothing, or something.
 */
import {
    choicesOf,
    describeValue,
    fromInput,
    inputModeOf,
    inputTypeOf,
    queryKindOf,
    toInput,
} from '../fields/index.js';
import { parseDecimal, toDecimalInput } from '../fields/decimal.js';
import { pageLocale } from '../fields/locale.js';
import { t } from '../lib/i18n.js';
import { NUMBER_BOUND, TEMPORAL_BOUND } from './query.js';

/** A number written with more than 15 digits before the decimal sign (the server takes 15 at most). */
const TOO_LARGE_NUMBER = /^-?\d{16,}(\.\d+)?$/;

/**
 * @typedef {import('../fields/types.js').Field} Field
 * @typedef {import('./query.js').Filter} Filter
 *
 * @typedef {object} Draft What the panel holds for a field, as text.
 * @property {'' | 'empty' | 'filled'} presence Any value, no value, or one.
 * @property {string} min What the box of the lowest value holds.
 * @property {string} max What the box of the highest value holds.
 * @property {'' | 'true' | 'false'} is The answer asked of a yes / no field.
 * @property {string[]} in The ids of the ticked choices.
 *
 * @typedef {object} Chip A filter, shown as a removable badge.
 * @property {string} key
 * @property {string} fieldId The field it filters.
 * @property {string} label What it says ("Price: €10.00 – €50.00").
 * @property {string} removeLabel The accessible name of its remove button.
 */

/**
 * A draft that holds nothing.
 *
 * @returns {Draft}
 */
export function blankDraft() {
    return { presence: '', min: '', max: '', is: '', in: [] };
}

/**
 * Whether a field is filtered by a lowest and a highest value (a number, a date, a moment, a time).
 *
 * @param {Field} field
 * @returns {boolean}
 */
export function hasRange(field) {
    const kind = queryKindOf(field);

    return kind === 'number' || kind === 'temporal';
}

/**
 * Whether a field is filtered by the answer of a yes / no.
 *
 * @param {Field} field
 * @returns {boolean}
 */
export function hasAnswer(field) {
    return queryKindOf(field) === 'boolean';
}

/**
 * Whether a field is filtered by a list of choices (one choice or several).
 *
 * @param {Field} field
 * @returns {boolean}
 */
export function hasChoiceList(field) {
    const kind = queryKindOf(field);

    return kind === 'choice' || kind === 'choices';
}

/**
 * The `type` of the boxes of the lowest and the highest value of a field: a date box for a date, a plain box for a number
 * (it is typed in the language of the person, `89,90` or `89.90`, which a number box would refuse).
 *
 * @param {Field} field
 * @returns {string}
 */
export function rangeInputType(field) {
    return queryKindOf(field) === 'number' ? 'text' : inputTypeOf(field);
}

/**
 * The `inputmode` of the boxes of the lowest and the highest value of a field (the numeric keyboard of a phone for a number).
 *
 * @param {Field} field
 * @returns {string | null}
 */
export function rangeInputMode(field) {
    return queryKindOf(field) === 'number' ? 'decimal' : inputModeOf(field);
}

/**
 * The draft that holds a filter, for the panel to show.
 *
 * @param {Field} field
 * @param {Filter | undefined} filter
 * @returns {Draft}
 */
export function draftOf(field, filter) {
    const draft = blankDraft();

    if (filter === undefined) {
        return draft;
    }

    if (filter.empty !== undefined) {
        draft.presence = filter.empty ? 'empty' : 'filled';
    }

    draft.min = boundToInput(field, filter.min);
    draft.max = boundToInput(field, filter.max);

    if (filter.is !== undefined) {
        draft.is = filter.is ? 'true' : 'false';
    }

    draft.in = [...(filter.in ?? [])];

    return draft;
}

/**
 * Turns what the panel holds for a field into its filter, or says what is wrong with a box.
 *
 * Asking for the items with no value leaves nothing else to ask. Asking for the ones with a value is said only when
 * nothing else is asked, since any other condition needs a value already.
 *
 * @param {Field} field
 * @param {Draft} draft
 * @returns {{ filter: Filter | null, errors: Record<string, string> }} The filter (null for none), and the message for
 *   each of `min` and `max` that was refused.
 */
export function filterFromDraft(field, draft) {
    if (draft.presence === 'empty') {
        return { filter: { empty: true }, errors: {} };
    }

    /** @type {Filter} */
    const filter = {};
    /** @type {Record<string, string>} */
    const errors = {};

    if (hasRange(field)) {
        for (const edge of /** @type {const} */ (['min', 'max'])) {
            const text = String(draft[edge] ?? '').trim();

            if (text === '') {
                continue;
            }

            const bound = boundFromInput(field, text, edge);

            if (bound === null) {
                errors[edge] = t(refusalOf(field, text));
            } else {
                filter[edge] = bound;
            }
        }
    } else if (hasAnswer(field)) {
        if (draft.is === 'true' || draft.is === 'false') {
            filter.is = draft.is === 'true';
        }
    } else if (hasChoiceList(field)) {
        // In the order of the choices, and only the ones the field still has.
        const ticked = choicesOf(field)
            .map((choice) => choice.id)
            .filter((id) => draft.in.includes(id));

        if (ticked.length > 0) {
            filter.in = ticked;
        }
    }

    if (Object.keys(filter).length === 0 && draft.presence === 'filled') {
        filter.empty = false;
    }

    return { filter: Object.keys(filter).length > 0 ? filter : null, errors };
}

/**
 * How many fields are filtered.
 *
 * @param {Record<string, Filter>} filters
 * @returns {number}
 */
export function filterCount(filters) {
    return Object.keys(filters).length;
}

/**
 * The filters as chips, one per filtered field, in the order of the fields. A filter on a field the inventory no longer
 * has shows no chip (the server refuses it anyway).
 *
 * @param {Field[]} fields
 * @param {Record<string, Filter>} filters
 * @returns {Chip[]}
 */
export function chipsOf(fields, filters) {
    /** @type {Chip[]} */
    const chips = [];

    for (const field of fields) {
        const filter = filters[field.id];
        const label = filter === undefined ? '' : labelOf(field, filter);

        if (label !== '') {
            chips.push({
                key: field.id,
                fieldId: field.id,
                label,
                removeLabel: t('grid.chip.remove', { label }),
            });
        }
    }

    return chips;
}

/**
 * What a filter says, for its chip.
 *
 * @param {Field} field
 * @param {Filter} filter
 * @returns {string} "" when the filter asks for nothing.
 */
export function labelOf(field, filter) {
    const name = field.name;

    if (filter.empty === true) {
        return t('grid.chip.empty', { name });
    }

    if (filter.min !== undefined && filter.max !== undefined) {
        return t('grid.chip.between', {
            name,
            min: describeBound(field, filter.min),
            max: describeBound(field, filter.max),
        });
    }

    if (filter.min !== undefined) {
        return t('grid.chip.min', { name, value: describeBound(field, filter.min) });
    }

    if (filter.max !== undefined) {
        return t('grid.chip.max', { name, value: describeBound(field, filter.max) });
    }

    if (filter.is !== undefined) {
        return t('grid.chip.value', { name, value: t(filter.is ? 'fields.boolean.yes' : 'fields.boolean.no') });
    }

    if (filter.in !== undefined && filter.in.length > 0) {
        // A choice that was deleted since is no more than a choice that was: its id means nothing to the person.
        const labels = filter.in.map(
            (id) => choicesOf(field).find((choice) => choice.id === id)?.label ?? t('grid.chip.deleted_choice'),
        );

        return t('grid.chip.value', { name, value: labels.join(', ') });
    }

    return filter.empty === false ? t('grid.chip.not_empty', { name }) : '';
}

/**
 * A bound as the person reads it: formatted as the values of the field are (`€10.00`, `Oct 15, 2026`).
 *
 * @param {Field} field
 * @param {string} value The bound, canonical.
 * @returns {string}
 */
function describeBound(field, value) {
    // The grade of a rating is the number it is filtered by, not its stars.
    return queryKindOf(field) === 'number' && field.type === 'rating' ? value : describeValue(field, value).text;
}

/**
 * What a box of the panel holds for a bound.
 *
 * @param {Field} field
 * @param {string | undefined} value The bound, canonical.
 * @returns {string}
 */
function boundToInput(field, value) {
    if (value === undefined) {
        return '';
    }

    return queryKindOf(field) === 'number' ? toDecimalInput(value, pageLocale()) : String(toInput(field, value) ?? '');
}

/**
 * Why a box of the panel holds no bound: the message to show under it.
 *
 * @param {Field} field
 * @param {string} text What the box holds, not empty.
 * @returns {string} The key of the message: a number too large is told apart from one that is no number or has too many decimals.
 */
function refusalOf(field, text) {
    if (queryKindOf(field) !== 'number') {
        return 'grid.filter.invalid_value';
    }

    return TOO_LARGE_NUMBER.test(parseDecimal(text) ?? '') ? 'grid.filter.too_large' : 'grid.filter.invalid_number';
}

/**
 * A bound in the form the API takes, from what was typed in a box.
 *
 * A moment is typed to the minute but stored to the second: the highest value runs to the end of its minute, so that
 * "until 14:30" keeps the items changed at 14:30:20.
 *
 * @param {Field} field
 * @param {string} text What the box holds, not empty.
 * @param {'min' | 'max'} edge
 * @returns {string | null} Null when it is no value the field can be filtered by.
 */
function boundFromInput(field, text, edge) {
    if (queryKindOf(field) === 'number') {
        const number = parseDecimal(text);

        return number !== null && NUMBER_BOUND.test(number) ? number : null;
    }

    const value = fromInput(field, text);
    const form = TEMPORAL_BOUND[/** @type {keyof typeof TEMPORAL_BOUND} */ (field.type)];

    if (typeof value !== 'string' || (form !== undefined && !form.test(value))) {
        return null;
    }

    return field.type === 'datetime' && edge === 'max' ? value.replace(/:00Z$/, ':59Z') : value;
}
