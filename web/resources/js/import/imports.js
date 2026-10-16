/**
 * What the import dialog knows about imports apart from the dialog itself: what the API calls things, the mapping of the
 * columns of a file to the fields of the inventory (built from the analysis the API gives, checked before the import
 * starts, sent as the body of the request that starts it), and how the end of an import, with its report, is told.
 *
 * Nothing here talks to the server or to the page, so it is all plain functions of their arguments.
 *
 * The vocabulary of the options is the API's: the separators `semicolon`, `comma` and `tab` (as for an export), the
 * encodings `utf-8`, `windows-1252` and `utf-16`, the decimal sign `comma` or `point`, the order of a date `dmy`, `mdy` or `ymd`, and
 * what a wrong row does, `abort` the import or `skip` the row. What the API says in another form (a separator written as
 * the character itself) is read all the same, see {@link normalizeDelimiter} and {@link normalizeEncoding}.
 */
import { defaultDelimiter } from '../export/exports.js';
import { pageLocale } from '../fields/locale.js';
import { t, tn } from '../lib/i18n.js';

/** How long the dialog waits before it asks again while an import is on its way, in milliseconds. */
export const POLL_INTERVAL_MS = 2000;

/** The largest file the dialog accepts when the page does not say, in bytes (the server's own default). */
export const DEFAULT_MAX_SIZE = 10 * 1024 * 1024;

/** The extensions of the files the file chooser lists (a CSV file, in its usual spellings, a spreadsheet). */
export const ACCEPTED_EXTENSIONS = ['.csv', '.tsv', '.txt', '.xlsx', '.ods'];

/** The longest name a field can have (`SaveFieldRequest::NAME_MAX_LENGTH`). */
export const NAME_MAX_LENGTH = 120;

/** The types a new field can be given, in the order they are offered: all but the files, the photos and the multiple choice. */
export const NEW_FIELD_TYPES = [
    'text',
    'textarea',
    'number',
    'money',
    'percentage',
    'rating',
    'boolean',
    'date',
    'datetime',
    'time',
    'select',
    'tags',
    'url',
    'email',
    'phone',
    'color',
    'barcode',
];

/** The types of the existing fields a column cannot go to: there is no way to write a file in a cell. */
const NOT_IMPORTABLE = ['file', 'photo'];

/** The separators of a CSV file, as the API names them. */
export const DELIMITERS = ['semicolon', 'comma', 'tab'];

/** The encodings of a CSV file, as the API names them. */
export const ENCODINGS = ['utf-8', 'windows-1252', 'utf-16'];

/** The decimal signs a number can be written with. */
export const DECIMALS = ['comma', 'point'];

/** The orders a date can be written in: day, month, year; month, day, year; year, month, day. */
export const DATE_ORDERS = ['dmy', 'mdy', 'ymd'];

/** What an import does with a row it cannot read: give up the whole import, or leave the row out. */
export const ON_ERRORS = ['abort', 'skip'];

/** What a column can be: left out, put in an existing field, put in a field to create. */
export const TARGETS = ['ignore', 'field', 'new'];

/** The statuses of an import that is not over: it waits for the mapping, for a worker, or is being written. */
const ACTIVE_STATUSES = ['uploaded', 'pending', 'running'];

/** The statuses of an import that a worker has to carry on: the dialog keeps asking while it is in one. */
const WAITING_STATUSES = ['pending', 'running'];

/** The statuses of an import that is over. */
const FINISHED_STATUSES = ['completed', 'failed'];

/** The longest a sample is shown before it is cut. */
const SAMPLE_LENGTH = 40;

/** What stands between the samples of a column. */
const SAMPLE_SEPARATOR = ' · ';

/** What the separators of a CSV file are called when the API gives the character. */
const DELIMITER_ALIASES = { ';': 'semicolon', ',': 'comma', '\t': 'tab' };

/**
 * @typedef {object} ImportColumn A column of the file, as `ImportResource` describes it.
 * @property {number} index Position of the column in the file, from 0.
 * @property {string} header Its name in the file (a made-up one, "Column C", when the file has none).
 * @property {string[]} samples Up to three values from the file.
 * @property {string | null} guess The type of field that fits what the column holds.
 *
 * @typedef {object} ImportMapping What becomes of a column, as the API takes and gives it.
 * @property {number} column
 * @property {'ignore' | 'field' | 'new'} target
 * @property {string} [field] The ULID of the existing field (target `field`).
 * @property {string} [type] The type of the field to create (target `new`).
 * @property {string} [name] The name of the field to create (target `new`).
 *
 * @typedef {object} ImportReportRow A row of the file that could not be read.
 * @property {number} row The row, as the spreadsheet numbers it.
 * @property {string} column
 * @property {string} message
 *
 * @typedef {object} ImportRecord An import, as `ImportResource` describes it.
 * @property {string} id
 * @property {'csv' | 'xlsx' | 'ods'} format
 * @property {'uploaded' | 'pending' | 'running' | 'completed' | 'failed'} status
 * @property {string | null} error Why it failed, in the language of the person; null otherwise.
 * @property {string} file_name
 * @property {number} size In bytes.
 * @property {Record<string, any>} options
 * @property {ImportColumn[]} columns
 * @property {ImportMapping[] | null} mapping
 * @property {number | null} rows_total
 * @property {number | null} rows_imported
 * @property {number | null} rows_failed
 * @property {number | null} fields_created
 * @property {ImportReportRow[] | null} errors
 * @property {string} created_at
 * @property {string | null} completed_at
 * @property {string | null} expires_at
 *
 * @typedef {object} ImportRow A column of the file with what the person decided for it: the state of the mapping.
 * @property {number} index
 * @property {string} header
 * @property {string[]} samples
 * @property {string | null} guess
 * @property {'ignore' | 'field' | 'new'} target
 * @property {string} field The ULID of the existing field the column goes to ("" for none yet).
 * @property {string} type The type of the field to create.
 * @property {string} name The name of the field to create.
 *
 * @typedef {object} ImportOptions The choices an import has: the ones that decide how the file is read, and the others.
 * @property {boolean} hasHeader
 * @property {string} delimiter
 * @property {string} encoding
 * @property {string | null} decimal Null when the API does not say.
 * @property {string | null} dateOrder Null when the API does not say.
 * @property {string} onError
 *
 * @typedef {object} Problems What is wrong with a mapping.
 * @property {boolean} valid
 * @property {Record<number, string>} rowErrors What is wrong with a column, by its index.
 * @property {string[]} general What is wrong with the mapping as a whole.
 */

/**
 * Whether an import is not over yet: it waits for the mapping, for a worker, or is being written.
 *
 * @param {{ status: string }} one
 * @returns {boolean}
 */
export function isActive(one) {
    return ACTIVE_STATUSES.includes(one.status);
}

/**
 * Whether an import is in the hands of a worker: the dialog asks again until it is not.
 *
 * @param {{ status: string }} one
 * @returns {boolean}
 */
export function isWaiting(one) {
    return WAITING_STATUSES.includes(one.status);
}

/**
 * Whether an import is over, done or failed.
 *
 * @param {{ status: string }} one
 * @returns {boolean}
 */
export function isFinished(one) {
    return FINISHED_STATUSES.includes(one.status);
}

/**
 * The largest file the page says the server takes.
 *
 * @param {string | number | undefined} raw What `data-import-max-size` holds: bytes.
 * @returns {number} Bytes; the default when the page says nothing sensible.
 */
export function maxSizeFrom(raw) {
    const size = Number(raw);

    return Number.isFinite(size) && size > 0 ? size : DEFAULT_MAX_SIZE;
}

/**
 * Whether a file is larger than the server takes.
 *
 * @param {{ size: number }} file
 * @param {number} max Bytes.
 * @returns {boolean}
 */
export function isTooLarge(file, max) {
    return file.size > max;
}

/**
 * Picks the import the dialog goes on with when it opens: the most recent one that is not over. When there is none, the one
 * the person was waiting for when they closed the dialog (it has ended since, and they have not been told how).
 *
 * @param {ImportRecord[]} list The imports the API lists.
 * @param {string | null} [unseen] The id of the import whose end the person has not seen.
 * @returns {ImportRecord | null}
 */
export function pickResumable(list, unseen = null) {
    const records = Array.isArray(list) ? list : [];
    const moment = (/** @type {ImportRecord} */ one) => Date.parse(one.created_at) || 0;
    const active = records.filter(isActive).sort((a, b) => moment(b) - moment(a));

    if (active.length > 0) {
        return active[0];
    }

    return unseen === null ? null : (records.find((one) => one.id === unseen && isFinished(one)) ?? null);
}

/**
 * The separator of a CSV file in the words of the API, whichever way the API wrote it.
 *
 * @param {unknown} value `semicolon`, `comma`, `tab`, or the character itself.
 * @returns {string | null} Null for a separator the dialog does not know.
 */
export function normalizeDelimiter(value) {
    if (typeof value !== 'string') {
        return null;
    }

    if (DELIMITERS.includes(value.toLowerCase())) {
        return value.toLowerCase();
    }

    return DELIMITER_ALIASES[value] ?? null;
}

/**
 * The encoding of a CSV file in the words of the API, whichever way the API wrote it.
 *
 * @param {unknown} value `utf-8`, `windows-1252`, `utf-16`, or a common spelling of one of them (`UTF8`, `cp1252`...).
 * @returns {string | null} Null for an encoding the dialog does not know.
 */
export function normalizeEncoding(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const key = value.toLowerCase().replace(/[^a-z0-9]/g, '');

    if (key === 'utf8') {
        return 'utf-8';
    }

    if (['utf16', 'utf16le', 'utf16be'].includes(key)) {
        return 'utf-16';
    }

    return ['windows1252', 'cp1252', 'iso88591', 'latin1'].includes(key) ? 'windows-1252' : null;
}

/**
 * The decimal sign a file is expected to use when the person does not choose: the comma with a semicolon (a spreadsheet in a
 * language that writes decimals with a comma) and in French, the point otherwise.
 *
 * @param {string} delimiter The separator of the file (only a CSV file has one).
 * @returns {'comma' | 'point'}
 */
export function defaultDecimal(delimiter) {
    return delimiter === 'semicolon' || pageLocale().split('-')[0].toLowerCase() === 'fr' ? 'comma' : 'point';
}

/**
 * The order of the dates of a file when the person does not choose: day first, except in English.
 *
 * @returns {'dmy' | 'mdy'}
 */
export function defaultDateOrder() {
    return pageLocale().split('-')[0].toLowerCase() === 'en' ? 'mdy' : 'dmy';
}

/**
 * The choices an import holds, as the controls of the dialog show them. What the API does not say is `null` (the decimal sign
 * and the order of the dates), for the dialog to work out.
 *
 * @param {ImportRecord | null} record
 * @returns {ImportOptions}
 */
export function optionsOf(record) {
    const options = record?.options ?? {};

    return {
        hasHeader: options.has_header !== false,
        delimiter: normalizeDelimiter(options.delimiter) ?? defaultDelimiter(),
        encoding: normalizeEncoding(options.encoding) ?? 'utf-8',
        decimal: DECIMALS.includes(options.decimal) ? options.decimal : null,
        dateOrder: DATE_ORDERS.includes(options.date_order) ? options.date_order : null,
        onError: ON_ERRORS.includes(options.on_error) ? options.on_error : 'abort',
    };
}

/**
 * The body of the request that reads the file again with other options (the row of the headers, the separator and the
 * encoding of a CSV file; a spreadsheet has neither).
 *
 * @param {{ hasHeader: boolean, delimiter: string, encoding: string }} choice
 * @param {boolean} csv Whether the file is a CSV file.
 * @returns {Record<string, any>}
 */
export function analysisBody({ hasHeader, delimiter, encoding }, csv) {
    /** @type {Record<string, any>} */
    const body = { has_header: hasHeader };

    if (csv) {
        body.delimiter = delimiter;
        body.encoding = encoding;
    }

    return body;
}

/**
 * The fields of the inventory a column can go to.
 *
 * @param {import('../fields/types.js').Field[]} fields
 * @returns {import('../fields/types.js').Field[]}
 */
export function importableFields(fields) {
    return fields.filter((field) => !NOT_IMPORTABLE.includes(field.type));
}

/**
 * The type a new field gets for a type the analysis guessed: the same, unless it cannot be created from a file.
 *
 * @param {unknown} guess
 * @returns {string}
 */
export function newFieldType(guess) {
    return typeof guess === 'string' && NEW_FIELD_TYPES.includes(guess) ? guess : 'text';
}

/**
 * The name a new field gets from the header of its column: on one line, and no longer than a name can be.
 *
 * @param {string} header
 * @returns {string}
 */
export function defaultName(header) {
    return header.replace(/\s+/g, ' ').trim().slice(0, NAME_MAX_LENGTH);
}

/**
 * A name as the server keeps it (trimmed, in Unicode form C).
 *
 * @param {string} name
 * @returns {string}
 */
export function cleanName(name) {
    return String(name ?? '')
        .trim()
        .normalize('NFC');
}

/**
 * A name as it is compared to find a duplicate: the case does not count, the accents do.
 *
 * @param {string} name
 * @returns {string}
 */
function fold(name) {
    return cleanName(name).toLowerCase();
}

/**
 * The state of the mapping from the columns the API found and the mapping it proposes (or that was kept): one row per
 * column, each with what the person decided and, kept aside for when they change their mind, the type and the name a
 * new field would have.
 *
 * A column that goes to a field that is not in the inventory any more, or to a new field when the person may not create
 * fields, is left out.
 *
 * @param {ImportColumn[]} columns
 * @param {ImportMapping[] | null} mapping
 * @param {{ fields: import('../fields/types.js').Field[], canManageFields: boolean }} context
 * @returns {ImportRow[]}
 */
export function mappingRows(columns, mapping, { fields, canManageFields }) {
    const chosen = new Map((Array.isArray(mapping) ? mapping : []).map((entry) => [entry.column, entry]));
    const targets = new Map(importableFields(fields).map((field) => [field.id.toLowerCase(), field.id]));

    return (Array.isArray(columns) ? columns : []).map((column) => {
        const entry = chosen.get(column.index) ?? { column: column.index, target: 'ignore' };
        const header = typeof column.header === 'string' ? column.header : '';
        const field = typeof entry.field === 'string' ? (targets.get(entry.field.toLowerCase()) ?? '') : '';
        let target = TARGETS.includes(entry.target) ? entry.target : 'ignore';

        if ((target === 'field' && field === '') || (target === 'new' && !canManageFields)) {
            target = 'ignore';
        }

        return {
            index: column.index,
            header,
            samples: (Array.isArray(column.samples) ? column.samples : []).filter((one) => typeof one === 'string'),
            guess: typeof column.guess === 'string' ? column.guess : null,
            target,
            field,
            type: newFieldType(entry.type ?? column.guess),
            name: typeof entry.name === 'string' && entry.name !== '' ? entry.name : defaultName(header),
        };
    });
}

/**
 * What the select of a column holds for what the person decided: `ignore`, `new`, or `field:` and the ULID of the field.
 *
 * @param {{ target: string, field: string }} row
 * @returns {string}
 */
export function destinationValue(row) {
    return row.target === 'field' ? `field:${row.field}` : row.target;
}

/**
 * What a value of the select of a column means: the inverse of {@link destinationValue}. A field that is not one of the
 * fields, and a new field when the person may not create one, mean nothing.
 *
 * @param {string} value
 * @param {{ fields: import('../fields/types.js').Field[], canManageFields: boolean }} context
 * @returns {{ target: 'ignore' | 'field' | 'new', field: string } | null} Null when the value is not one the select offers.
 */
export function parseDestination(value, { fields, canManageFields }) {
    if (value === 'ignore') {
        return { target: 'ignore', field: '' };
    }

    if (value === 'new') {
        return canManageFields ? { target: 'new', field: '' } : null;
    }

    if (value.startsWith('field:')) {
        const id = value.slice('field:'.length);

        return importableFields(fields).some((field) => field.id === id) ? { target: 'field', field: id } : null;
    }

    return null;
}

/**
 * What stands between the samples of a column, each cut when it is long.
 *
 * @param {string[]} samples
 * @returns {string} "" for a column with nothing in it.
 */
export function samplesText(samples) {
    return samples
        .filter((sample) => sample !== '')
        .map((sample) => (sample.length > SAMPLE_LENGTH ? sample.slice(0, SAMPLE_LENGTH) + '…' : sample))
        .join(SAMPLE_SEPARATOR);
}

/**
 * A column as the dialog shows it: the state of the mapping with what the template needs to name its controls and to say
 * what is wrong with it.
 *
 * @param {ImportRow} row
 * @param {string} [error] What is wrong with the column ("" when nothing is).
 * @returns {Record<string, any>}
 */
export function rowView(row, error = '') {
    const labels = { column: row.header };

    return {
        index: row.index,
        header: row.header,
        samples: samplesText(row.samples),
        value: destinationValue(row),
        type: row.type,
        name: row.name,
        isNew: row.target === 'new',
        isSelect: row.target === 'new' && row.type === 'select',
        error,
        // The error is about the name when a field is created, else about the destination.
        destinationInvalid: error !== '' && row.target !== 'new' ? 'true' : 'false',
        nameInvalid: error !== '' && row.target === 'new' ? 'true' : 'false',
        destinationId: `import-destination-${row.index}`,
        typeId: `import-type-${row.index}`,
        nameId: `import-name-${row.index}`,
        errorId: `import-error-${row.index}`,
        destinationLabel: t('imports.label.destination', labels),
        typeLabel: t('imports.label.type', labels),
        nameLabel: t('imports.label.name', labels),
    };
}

/**
 * What the mapping says in one line: how many columns will be imported, and how many fields will be created.
 *
 * @param {ImportRow[]} rows
 * @returns {string}
 */
export function mappingSummary(rows) {
    const imported = rows.filter((row) => row.target !== 'ignore').length;
    const created = rows.filter((row) => row.target === 'new').length;
    const parts = [tn('imports.mapping.imported', imported)];

    if (created > 0) {
        parts.push(tn('imports.mapping.created', created));
    }

    return parts.join(', ');
}

/**
 * What is wrong with a mapping, before the API is asked to start the import: a column with no field chosen, two columns for
 * one field, a new field with no name, a name that is taken, a field that is required and that no column goes to, no column
 * at all. The API checks the same things; this tells the person without a request.
 *
 * @param {ImportRow[]} rows
 * @param {import('../fields/types.js').Field[]} fields The fields of the inventory.
 * @param {{ canManageFields: boolean }} context
 * @returns {Problems}
 */
export function validate(rows, fields, { canManageFields }) {
    /** @type {Record<number, string>} */
    const rowErrors = {};
    const general = [];
    const used = new Set();
    const created = new Set();
    const taken = new Set(fields.map((field) => fold(field.name)));

    for (const row of rows) {
        if (row.target === 'field') {
            if (row.field === '') {
                rowErrors[row.index] = t('imports.error.choose_field');
            } else if (used.has(row.field)) {
                rowErrors[row.index] = t('imports.error.field_twice');
            } else {
                used.add(row.field);
            }
        } else if (row.target === 'new') {
            const name = cleanName(row.name);

            if (!canManageFields) {
                rowErrors[row.index] = t('imports.error.cannot_create');
            } else if (name === '') {
                rowErrors[row.index] = t('imports.error.name_empty');
            } else if (name.length > NAME_MAX_LENGTH) {
                rowErrors[row.index] = t('imports.error.name_long', { max: NAME_MAX_LENGTH });
            } else if (taken.has(fold(name))) {
                rowErrors[row.index] = t('imports.error.name_taken');
            } else if (created.has(fold(name))) {
                rowErrors[row.index] = t('imports.error.name_twice');
            } else {
                created.add(fold(name));
            }
        }
    }

    if (!rows.some((row) => row.target !== 'ignore')) {
        general.push(t('imports.error.nothing_mapped'));
    }

    for (const field of importableFields(fields)) {
        if (field.required && !used.has(field.id)) {
            general.push(t('imports.error.required_unmapped', { name: field.name }));
        }
    }

    return { valid: Object.keys(rowErrors).length === 0 && general.length === 0, rowErrors, general };
}

/**
 * The body of the request that starts the import: what becomes of each column, and how the cells are read.
 *
 * @param {ImportRow[]} rows
 * @param {{ decimal: string, dateOrder: string, onError: string }} choice
 * @returns {Record<string, any>}
 */
export function startBody(rows, { decimal, dateOrder, onError }) {
    return {
        mapping: rows.map((row) => {
            if (row.target === 'field') {
                return { column: row.index, target: 'field', field: row.field };
            }

            if (row.target === 'new') {
                return { column: row.index, target: 'new', type: row.type, name: cleanName(row.name) };
            }

            return { column: row.index, target: 'ignore' };
        }),
        decimal,
        date_order: dateOrder,
        on_error: onError,
    };
}

/**
 * The title of an import that is over or on its way.
 *
 * @param {ImportRecord} record
 * @returns {string}
 */
export function statusTitle(record) {
    return t(`imports.status.${record.status}`);
}

/**
 * What a finished import did, one sentence for each thing that is not nothing: the items added, the fields created, the
 * rows left out.
 *
 * @param {ImportRecord} record
 * @returns {string[]}
 */
export function resultLines(record) {
    const added = record.rows_imported ?? 0;
    const created = record.fields_created ?? 0;
    const left = record.rows_failed ?? 0;
    const lines = [];

    if (record.status === 'completed') {
        lines.push(tn('imports.result.items', added));

        if (created > 0) {
            lines.push(tn('imports.result.fields', created));
        }

        if (left > 0) {
            lines.push(tn('imports.result.skipped', left));
        }
    } else if (left > 0) {
        lines.push(tn('imports.result.invalid', left));
    }

    return lines;
}

/**
 * Why an import failed, in the language of the person.
 *
 * @param {ImportRecord} record
 * @returns {string}
 */
export function failureText(record) {
    return typeof record.error === 'string' && record.error !== '' ? record.error : t('imports.error.failed');
}

/**
 * The rows of the file that could not be read, for the table of the report.
 *
 * @param {ImportRecord} record
 * @returns {(ImportReportRow & { key: string })[]}
 */
export function reportRows(record) {
    return (Array.isArray(record.errors) ? record.errors : []).map((one, position) => ({
        key: `${position}-${one.row}`,
        row: one.row,
        column: one.column === null || one.column === undefined ? '' : String(one.column),
        message: String(one.message ?? ''),
    }));
}

/**
 * What the report says when it does not hold every row at fault (the API keeps the first hundred).
 *
 * @param {ImportRecord} record
 * @returns {string} "" when the report is whole.
 */
export function reportNote(record) {
    const shown = Array.isArray(record.errors) ? record.errors.length : 0;
    const total = record.rows_failed ?? 0;

    return total > shown && shown > 0 ? t('imports.report.truncated', { shown, total }) : '';
}

/**
 * What is said (to a screen reader) when an import ends.
 *
 * @param {ImportRecord} record
 * @returns {string}
 */
export function endNotice(record) {
    return record.status === 'completed'
        ? tn('imports.notice.completed', record.rows_imported ?? 0)
        : t('imports.notice.failed');
}
