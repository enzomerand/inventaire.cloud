/**
 * The options of the types of field, as the field editor handles them.
 *
 * The API wants every option of a type in canonical form (numbers as numbers, bounds as decimal text, nothing for
 * "default") and refuses an option the type does not have; the editor works on text boxes. This module says which
 * options each type has, writes the options of a field into the editor's form state, and turns that state back into
 * what the API takes. An option is always sent, as null when it is empty: the API merges the options it receives over
 * those the field has, and null is how one goes back to its default.
 */
import { choicesOf } from './types.js';
import { parseDecimal, toDecimalInput } from './decimal.js';
import { pageLocale } from './locale.js';
import { t } from '../lib/i18n.js';

/** @type {Record<string, string[]>} The options of each type that has some, in the order the form shows them. */
const OPTION_NAMES = {
    text: ['max_length'],
    number: ['decimals', 'min', 'max', 'unit'],
    money: ['currency'],
    percentage: ['decimals'],
    select: ['choices'],
    multiselect: ['choices'],
    rating: ['max'],
    barcode: ['format'],
};

/** The formats of a barcode, as the API names them. */
export const BARCODE_FORMATS = ['any', 'ean13', 'ean8', 'upca', 'isbn10', 'isbn13'];

/** The names of the formats that are not translated (`any` is: see `fields.barcode.any`). */
const BARCODE_NAMES = { ean13: 'EAN-13', ean8: 'EAN-8', upca: 'UPC-A', isbn10: 'ISBN-10', isbn13: 'ISBN-13' };

/**
 * @typedef {object} ChoiceForm A choice of a select, as the editor has it.
 * @property {string | null} id The id the server gave it; null for a choice that was just added.
 * @property {string} label
 * @property {string} color A name of the palette, or "" for none.
 *
 * @typedef {object} OptionsForm The options of a field, as text boxes hold them.
 * @property {string} max_length
 * @property {string} decimals
 * @property {string} min
 * @property {string} max
 * @property {string} unit
 * @property {string} currency
 * @property {string} format
 * @property {ChoiceForm[]} choices
 */

/**
 * The names of the options a type has.
 *
 * @param {string} type
 * @returns {string[]}
 */
export function optionNames(type) {
    return OPTION_NAMES[type] ?? [];
}

/**
 * A choice that has no label yet, for the editor to fill in.
 *
 * @returns {ChoiceForm}
 */
export function blankChoice() {
    return { id: null, label: '', color: '' };
}

/**
 * The options of a field as the editor's form holds them. For a field that is being created pass no options: the form
 * starts from the defaults of the type (the API fills in the ones left empty).
 *
 * @param {string} type
 * @param {Record<string, any> | null} [options] The options of an existing field.
 * @returns {OptionsForm}
 */
export function optionsToForm(type, options = null) {
    const stored = options ?? {};
    const choices = choicesOf({ options: stored });

    return {
        max_length: options === null ? '' : String(stored.max_length ?? ''),
        decimals: String(stored.decimals ?? (type === 'percentage' ? 2 : 0)),
        min: toDecimalInput(stored.min ?? '', pageLocale()),
        max: type === 'rating' ? String(stored.max ?? 5) : toDecimalInput(stored.max ?? '', pageLocale()),
        unit: String(stored.unit ?? ''),
        currency: String(stored.currency ?? 'EUR'),
        format: String(stored.format ?? 'any'),
        choices:
            choices.length > 0
                ? choices.map((choice) => ({ id: choice.id, label: choice.label, color: choice.color ?? '' }))
                : [blankChoice()],
    };
}

/**
 * A whole-number box for the API: null when empty, else the number (text that is not one is sent as it is, for the
 * server to refuse with a message).
 *
 * @param {string} text
 * @returns {number | string | null}
 */
function wholeNumber(text) {
    const trimmed = String(text).trim();

    return trimmed === '' ? null : /^\d+$/.test(trimmed) ? Number(trimmed) : trimmed;
}

/**
 * The `options` to send to the API for the options of the editor's form: only those of the type, each one present.
 *
 * Choices that were just added and have no label are left out (an empty row is not a choice); one that already exists
 * is always sent, so that emptying its label is refused instead of silently deleting the choice.
 *
 * When the options of an existing field are sent, pass the form as it was when the dialog opened: the choices are then
 * sent only if the person changed them. A dialog can be older than the field (another editor added choices meanwhile),
 * and sending the whole list back would delete the choices it never heard of, and their values from every item.
 *
 * @param {string} type
 * @param {OptionsForm} form
 * @param {OptionsForm | null} [opened] The form as it was when the dialog opened on an existing field; null for a new one.
 * @returns {Record<string, any>}
 */
export function formToOptions(type, form, opened = null) {
    /** @type {Record<string, () => any>} */
    const readers = {
        max_length: () => wholeNumber(form.max_length),
        decimals: () => wholeNumber(form.decimals),
        min: () => parseDecimal(form.min),
        max: () => (type === 'rating' ? wholeNumber(form.max) : parseDecimal(form.max)),
        unit: () => (form.unit.trim() === '' ? null : form.unit.trim()),
        currency: () => form.currency || null,
        format: () => form.format || null,
        choices: () =>
            opened !== null && JSON.stringify(form.choices) === JSON.stringify(opened.choices)
                ? undefined
                : form.choices
                      .filter((choice) => choice.id !== null || choice.label.trim() !== '')
                      .map((choice) => ({
                          ...(choice.id === null ? {} : { id: choice.id }),
                          label: choice.label.trim(),
                          color: choice.color || null,
                      })),
    };

    return Object.fromEntries(
        optionNames(type)
            .map((name) => [name, readers[name]()])
            .filter(([, value]) => value !== undefined),
    );
}

/**
 * A short account of the options of a field, for the list of fields ("Decimals: 2 · Unit: kg").
 *
 * @param {{ type: string, options: Record<string, any> }} field
 * @param {(code: string) => string} currencyName The name of a currency, for its code.
 * @returns {string}
 */
export function summarizeOptions(field, currencyName) {
    const options = field.options ?? {};
    /** @type {string[]} */
    const parts = [];

    switch (field.type) {
        case 'text':
            parts.push(t('fields.summary.max_length', { count: options.max_length ?? 255 }));
            break;
        case 'number':
            parts.push(t('fields.summary.decimals', { count: options.decimals ?? 0 }));

            if (options.unit) {
                parts.push(t('fields.summary.unit', { unit: options.unit }));
            }

            if (options.min !== undefined) {
                parts.push(t('fields.summary.min', { value: options.min }));
            }

            if (options.max !== undefined) {
                parts.push(t('fields.summary.max', { value: options.max }));
            }

            break;
        case 'money':
            parts.push(t('fields.summary.currency', { currency: currencyName(options.currency ?? 'EUR') }));
            break;
        case 'percentage':
            parts.push(t('fields.summary.decimals', { count: options.decimals ?? 2 }));
            break;
        case 'select':
        case 'multiselect':
            parts.push(
                t('fields.summary.choices', {
                    list: choicesOf(field)
                        .map((choice) => choice.label)
                        .join(', '),
                }),
            );
            break;
        case 'rating':
            parts.push(t('fields.summary.max', { value: options.max ?? 5 }));
            break;
        case 'barcode':
            parts.push(t('fields.summary.format', { format: barcodeFormatName(options.format ?? 'any') }));
            break;
        default:
    }

    return parts.join(' · ');
}

/**
 * The name of a barcode format, for a person.
 *
 * @param {string} format One of {@link BARCODE_FORMATS}.
 * @returns {string}
 */
export function barcodeFormatName(format) {
    return format === 'any' ? t('fields.barcode.any') : (BARCODE_NAMES[format] ?? format);
}
