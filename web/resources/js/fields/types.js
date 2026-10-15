/**
 * The field types of the interface: one entry per type of the server's registry, saying how a value of the type is
 * shown, typed in and sent.
 *
 * The API speaks in canonical values (`"12.50"` for a number, `"2026-10-15"` for a date, `"2026-10-15T12:30:00Z"` for a
 * moment, the id of a choice); a form speaks in what an `<input>` holds. Each entry converts both ways:
 *
 * - `control` says which kind of control the form shows (see {@link Control});
 * - `queryKind` says how the server sorts and filters the type (see {@link QueryKind}), and `summable` that the totals
 *   of a list add its values up. Both mirror the server (`queryKind()` and `summable()` of each type in
 *   `App\Inventories\Fields`): keep them in sync, a test reads the PHP types and fails when they drift apart;
 * - `inputType` and `inputMode` shape the `<input>` of the controls that are one;
 * - `toInput(value, field)` is the value of the control for a stored value, `fromInput(input, field)` is the value to
 *   send for what the control holds (null for nothing, which clears the field);
 * - `describe(value, field, options)` is the {@link Cell} that shows a stored value; with `{ full: true }` (the details of an
 *   item) a long text is shown whole, with its line breaks, instead of cut to one line for a table cell.
 *
 * Everything shown is data for `x-text` and attribute bindings, never markup.
 */
import { cell, emptyCell } from './cell.js';
import { formatDecimal, formatMoney, formatPercentage, parseDecimal, toDecimalInput } from './decimal.js';
import { dateFormat, pageLocale } from './locale.js';
import { badgeClass } from './palette.js';
import { t } from '../lib/i18n.js';

/**
 * @typedef {'input' | 'textarea' | 'select' | 'checks' | 'tags' | 'rating' | 'color'} Control
 * The control of a form: a one-line `<input>` (text, number, date...), a `<textarea>`, a `<select>`, a list of check
 * boxes, a box of tags, a row of stars, a color picker.
 *
 * @typedef {'text' | 'number' | 'temporal' | 'boolean' | 'choice' | 'choices' | 'tags'} QueryKind
 * How the server sorts and filters a type: text by collation, numbers as numbers, dates, moments and times in their stored
 * form, a yes / no, one choice, several choices (cannot be sorted), tags (cannot be sorted).
 *
 * @typedef {object} Choice
 * @property {string} id
 * @property {string} label
 * @property {string} [color]
 *
 * @typedef {object} Field A field of an inventory, as the API describes it.
 * @property {string} id
 * @property {string} name
 * @property {string} type
 * @property {Record<string, any>} options
 * @property {boolean} required
 * @property {number} position
 *
 * @typedef {object} DescribeOptions
 * @property {boolean} [full] Whether to show the whole value (the details of an item) rather than what fits a table cell.
 *
 * @typedef {object} FieldType
 * @property {QueryKind} queryKind How the server sorts and filters the type (mirrors `QueryKind` of the server).
 * @property {boolean} [summable] Whether the totals of a list add the values of the type up (mirrors `FieldType::summable()`).
 * @property {Control} control
 * @property {string} [inputType] The `type` of the `<input>`; `text` when not given.
 * @property {string} [inputMode] The `inputmode` of the `<input>`.
 * @property {(value: any, field: Field) => any} toInput
 * @property {(input: any, field: Field) => any} fromInput
 * @property {(value: any, field: Field, options?: DescribeOptions) => import('./cell.js').Cell} describe
 */

/** How many characters of a long text the table shows. */
export const TEXT_PREVIEW_LENGTH = 80;

/** The favourite shape of a color: six hexadecimal digits after a `#`. */
const HEX_COLOR = /^#[0-9a-fA-F]{6}$/;

/** A day as the API writes it. */
const DAY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Whether a stored value stands for nothing.
 *
 * @param {unknown} value
 * @returns {boolean}
 */
export function isBlank(value) {
    return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
}

/**
 * A text box's content for the API: trimmed, and null when nothing is left.
 *
 * @param {unknown} input
 * @returns {string | null}
 */
function textOrNull(input) {
    const text = typeof input === 'string' ? input.trim() : '';

    return text === '' ? null : text;
}

/**
 * Shortens a long text to one line for a table cell.
 *
 * @param {string} text
 * @returns {{ text: string, shortened: boolean }}
 */
function preview(text) {
    const line = text.replace(/\s+/g, ' ').trim();

    return line.length > TEXT_PREVIEW_LENGTH
        ? { text: line.slice(0, TEXT_PREVIEW_LENGTH).trimEnd() + '…', shortened: true }
        : { text: line, shortened: false };
}

/**
 * The choices a select, a multiple choice offers.
 *
 * @param {Field} field
 * @returns {Choice[]}
 */
export function choicesOf(field) {
    return Array.isArray(field.options?.choices) ? field.options.choices : [];
}

/**
 * Whether a text is an address a link may lead to: a web page, `http` or `https` only, so that a stored `javascript:`
 * address (the API refuses them, but the data may come from anywhere) can never run a script from a click.
 *
 * @param {string} text
 * @returns {string | null} The address in canonical form, or null when it is not one the interface links to.
 */
export function webAddress(text) {
    try {
        const url = new URL(text);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}

/**
 * Adds a zero in front of a number below ten.
 *
 * @param {number} number
 * @returns {string}
 */
function pad(number) {
    return String(number).padStart(2, '0');
}

/**
 * Writes a moment for a `datetime-local` box, in the timezone of the browser (`2026-10-15T14:30`).
 *
 * @param {Date} date
 * @returns {string}
 */
function localInput(date) {
    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    );
}

/** What the types typed in as plain text share: the box holds the stored text, and what it holds is sent trimmed. */
const TEXT_INPUT = {
    toInput: (value) => value ?? '',
    fromInput: textOrNull,
};

/** What the numbers, amounts and percentages share: a box for a decimal number, typed in the language of the page. */
const DECIMAL_INPUT = {
    control: 'input',
    inputMode: 'decimal',
    toInput: (value) => toDecimalInput(value, pageLocale()),
    fromInput: (input) => parseDecimal(input),
};

/** @type {Record<string, FieldType>} */
export const FIELD_TYPES = {
    text: {
        queryKind: 'text',
        control: 'input',
        ...TEXT_INPUT,
        describe: (value) => cell('text', { text: String(value) }),
    },

    textarea: {
        queryKind: 'text',
        control: 'textarea',
        // Line breaks are kept: only the ends are trimmed, as the server does.
        ...TEXT_INPUT,
        describe: (value, _field, options) => {
            if (options?.full) {
                return cell('text', { text: String(value), multiline: true });
            }

            const short = preview(String(value));

            return cell('text', { text: short.text, title: short.shortened ? String(value) : null });
        },
    },

    number: {
        queryKind: 'number',
        summable: true,
        ...DECIMAL_INPUT,
        describe: (value, field) => {
            const unit = field.options?.unit ? ' ' + field.options.unit : '';

            return cell('text', { text: formatDecimal(String(value), Number(field.options?.decimals ?? 0)) + unit });
        },
    },

    money: {
        queryKind: 'number',
        summable: true,
        ...DECIMAL_INPUT,
        describe: (value, field) =>
            cell('text', {
                text: formatMoney(String(value), field.options?.currency ?? 'EUR', field.options?.decimals ?? null),
            }),
    },

    percentage: {
        queryKind: 'number',
        ...DECIMAL_INPUT,
        describe: (value, field) =>
            cell('text', { text: formatPercentage(String(value), Number(field.options?.decimals ?? 2)) }),
    },

    boolean: {
        queryKind: 'boolean',
        control: 'select',
        toInput: (value) => (value === true ? 'true' : value === false ? 'false' : ''),
        fromInput: (input) => (input === 'true' ? true : input === 'false' ? false : null),
        describe: (value) =>
            cell('badges', {
                badges: [
                    value === true
                        ? { text: t('fields.boolean.yes'), class: badgeClass('green') }
                        : { text: t('fields.boolean.no'), class: badgeClass('gray') },
                ],
            }),
    },

    date: {
        queryKind: 'temporal',
        control: 'input',
        inputType: 'date',
        ...TEXT_INPUT,
        // A day has no timezone: it is read and shown as UTC, or it would slip to the day before for the readers west of Greenwich.
        describe: (value) => {
            const date = new Date(value + 'T00:00:00Z');

            return DAY.test(String(value)) && !Number.isNaN(date.getTime())
                ? cell('text', { text: dateFormat({ dateStyle: 'medium', timeZone: 'UTC' }).format(date) })
                : cell('text', { text: String(value) });
        },
    },

    datetime: {
        queryKind: 'temporal',
        control: 'input',
        inputType: 'datetime-local',
        // A moment is stored in UTC, and typed and shown in the timezone of the browser.
        toInput: (value) => {
            const date = new Date(value);

            return typeof value === 'string' && !Number.isNaN(date.getTime()) ? localInput(date) : '';
        },
        fromInput: (input) => {
            const text = textOrNull(input);

            if (text === null) {
                return null;
            }

            const date = new Date(text);

            // Without the milliseconds the API does not need. Text that is no moment is sent as it is, for the server to refuse.
            return Number.isNaN(date.getTime()) ? text : date.toISOString().replace(/\.\d{3}Z$/, 'Z');
        },
        describe: (value) => {
            const date = new Date(value);

            return Number.isNaN(date.getTime())
                ? cell('text', { text: String(value) })
                : cell('text', { text: dateFormat({ dateStyle: 'medium', timeStyle: 'short' }).format(date) });
        },
    },

    time: {
        queryKind: 'temporal',
        control: 'input',
        inputType: 'time',
        toInput: (value) => value ?? '',
        fromInput: (input) => textOrNull(input)?.slice(0, 5) ?? null,
        // A time of day has no timezone either.
        describe: (value) => {
            const date = new Date(`1970-01-01T${value}:00Z`);

            return Number.isNaN(date.getTime())
                ? cell('text', { text: String(value) })
                : cell('text', { text: dateFormat({ timeStyle: 'short', timeZone: 'UTC' }).format(date) });
        },
    },

    select: {
        queryKind: 'choice',
        control: 'select',
        toInput: (value) => value ?? '',
        fromInput: (input) => (input === '' || input === null || input === undefined ? null : input),
        describe: (value, field) => {
            const choice = choicesOf(field).find((candidate) => candidate.id === value);

            return choice
                ? cell('badges', { badges: [{ text: choice.label, class: badgeClass(choice.color) }] })
                : emptyCell();
        },
    },

    multiselect: {
        queryKind: 'choices',
        control: 'checks',
        toInput: (value) => (Array.isArray(value) ? [...value] : []),
        fromInput: (input) => (Array.isArray(input) && input.length > 0 ? input : null),
        describe: (value, field) => {
            const chosen = choicesOf(field).filter((choice) => Array.isArray(value) && value.includes(choice.id));

            return chosen.length === 0
                ? emptyCell()
                : cell('badges', {
                      badges: chosen.map((choice) => ({ text: choice.label, class: badgeClass(choice.color) })),
                  });
        },
    },

    tags: {
        queryKind: 'tags',
        control: 'tags',
        toInput: (value) => (Array.isArray(value) ? [...value] : []),
        fromInput: (input) => {
            const tags = Array.isArray(input) ? input.map((tag) => String(tag).trim()).filter((tag) => tag !== '') : [];

            return tags.length > 0 ? tags : null;
        },
        describe: (value) =>
            cell('badges', {
                badges: (Array.isArray(value) ? value : []).map((tag) => ({
                    text: String(tag),
                    class: badgeClass(null),
                })),
            }),
    },

    url: {
        queryKind: 'text',
        control: 'input',
        inputType: 'url',
        ...TEXT_INPUT,
        describe: (value) => {
            const href = webAddress(String(value));

            // Anything that is not a web address is shown, not linked.
            return href === null
                ? cell('text', { text: String(value) })
                : cell('link', { text: String(value), href, target: '_blank' });
        },
    },

    email: {
        queryKind: 'text',
        control: 'input',
        inputType: 'email',
        ...TEXT_INPUT,
        // `#`, `?` and `&` in the local part of an address (`a&b@example.com`) must not end the address in the link; the `@` stays.
        describe: (value) =>
            cell('link', {
                text: String(value),
                href: 'mailto:' + encodeURIComponent(String(value)).replace(/%40/g, '@'),
            }),
    },

    phone: {
        queryKind: 'text',
        control: 'input',
        inputType: 'tel',
        ...TEXT_INPUT,
        describe: (value) => cell('link', { text: String(value), href: 'tel:' + String(value).replace(/[^\d+]/g, '') }),
    },

    rating: {
        queryKind: 'number',
        control: 'rating',
        toInput: (value) => (typeof value === 'number' ? value : null),
        fromInput: (input) => (typeof input === 'number' && input > 0 ? input : null),
        describe: (value, field) => {
            const max = Number(field.options?.max ?? 5);
            const grade = Math.max(0, Math.min(Number(value), max));

            return cell('stars', {
                text: '★'.repeat(grade) + '☆'.repeat(max - grade),
                title: t('fields.rating', { value: grade, max }),
            });
        },
    },

    color: {
        queryKind: 'text',
        control: 'color',
        inputType: 'color',
        ...TEXT_INPUT,
        describe: (value) =>
            HEX_COLOR.test(String(value))
                ? cell('swatch', { text: String(value), style: { backgroundColor: String(value) } })
                : cell('text', { text: String(value) }),
    },

    barcode: {
        queryKind: 'text',
        control: 'input',
        ...TEXT_INPUT,
        describe: (value) => cell('mono', { text: String(value) }),
    },
};

/** What an unknown type (a field of a newer version, after a rollback) behaves like: a text, shown as it is. */
export const FALLBACK_TYPE = FIELD_TYPES.text;
