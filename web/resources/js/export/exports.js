/**
 * What the export dialog knows about exports apart from the dialog itself: the formats it offers, what a request for an
 * export carries, and how an export the API describes is told in a row of the list.
 *
 * Nothing here talks to the server or to the page, so it is all plain functions of their arguments.
 */
import { pageLocale } from '../fields/locale.js';
import { formatSize } from '../fields/files.js';
import { momentText } from '../grid/rows.js';
import { toBody } from '../grid/query.js';
import { t, tn } from '../lib/i18n.js';

/** How long the list waits before it asks again while an export is on its way, in milliseconds. */
export const POLL_INTERVAL_MS = 2000;

/** The formats of an export, in the order the dialog offers them (the names the API takes). */
export const FORMAT_IDS = ['xlsx', 'ods', 'csv', 'json', 'pdf', 'zip'];

/** The statuses of an export that is not over: the list keeps asking while it holds one. */
const ACTIVE_STATUSES = ['pending', 'running'];

/** The badge colors of the statuses (Tabler's light variants; the badge always says the status in words as well). */
export const BADGE_CLASSES = {
    pending: 'bg-secondary-lt',
    running: 'bg-blue-lt',
    completed: 'bg-green-lt',
    failed: 'bg-red-lt',
};

/** What stands between the parts of what a row says of an export. */
export const DETAIL_SEPARATOR = ' · ';

/**
 * @typedef {object} ExportRecord An export, as `ExportResource` describes it.
 * @property {string} id
 * @property {string} format
 * @property {'all' | 'view'} scope
 * @property {'pending' | 'running' | 'completed' | 'failed'} status
 * @property {string | null} error Why it failed, in the language of the person; null otherwise.
 * @property {string | null} file_name
 * @property {number | null} size In bytes.
 * @property {number | null} items_count
 * @property {string} created_at
 * @property {string | null} completed_at
 * @property {string | null} expires_at
 * @property {string | null} download_url Where the file is downloaded; null until it is ready.
 *
 * @typedef {object} ExportRow An export as the dialog shows it.
 * @property {string} id
 * @property {string} title The format, in words.
 * @property {string} details When, how many items, how big, until when.
 * @property {string} error Why it failed ("" when it did not).
 * @property {string} statusLabel
 * @property {string} badgeClass
 * @property {boolean} ready Whether the file can be downloaded.
 * @property {string | null} url
 * @property {string} downloadLabel
 * @property {string} deleteLabel
 * @property {boolean} deleting Whether its deletion is on its way.
 */

/**
 * The separator a CSV file gets when the person does not choose: the semicolon in French (a spreadsheet in a language that
 * writes decimals with a comma expects it), the comma otherwise.
 *
 * @returns {'semicolon' | 'comma'}
 */
export function defaultDelimiter() {
    return pageLocale().split('-')[0].toLowerCase() === 'fr' ? 'semicolon' : 'comma';
}

/**
 * The formats the dialog offers, each with its name and what it is good for in the language of the page.
 *
 * @returns {{ id: string, label: string, hint: string, inputId: string }[]}
 */
export function formatChoices() {
    return FORMAT_IDS.map((id) => ({
        id,
        label: t(`exports.format.${id}`),
        hint: t(`exports.hint.${id}`),
        inputId: `export-format-${id}`,
    }));
}

/**
 * Whether an export is not over yet: it waits for a worker or is being written.
 *
 * @param {{ status: string }} one
 * @returns {boolean}
 */
export function isActive(one) {
    return ACTIVE_STATUSES.includes(one.status);
}

/**
 * The body of the request that starts an export. Only what the chosen format and scope make use of is sent: the view only
 * with the scope "view", the separator only for a CSV file, the choice of files only for a ZIP.
 *
 * @param {object} choice What the person chose.
 * @param {string} choice.format
 * @param {'all' | 'view'} choice.scope
 * @param {import('../grid/query.js').Query} choice.query What the list is asked for now: the view, for the scope "view".
 * @param {string} choice.delimiter
 * @param {string} choice.files
 * @returns {Record<string, any>}
 */
export function requestBody({ format, scope, query, delimiter, files }) {
    /** @type {Record<string, any>} */
    const body = { format, scope };

    if (scope === 'view') {
        body.query = toBody(query);
    }

    if (format === 'csv') {
        body.delimiter = delimiter;
    }

    if (format === 'zip') {
        body.files = files;
    }

    return body;
}

/**
 * The words that say what an export holds, for a row of the list: which items, when it was asked for, how many items it
 * holds, how big the file is, and until when it can be downloaded. What is not known is left out.
 *
 * @param {ExportRecord} one
 * @returns {string}
 */
function detailsOf(one) {
    const parts = [
        t(`exports.scope.${one.scope}`),
        momentText(one.created_at),
        typeof one.items_count === 'number' ? tn('inventories.items', one.items_count) : '',
        typeof one.size === 'number' ? formatSize(one.size) : '',
        one.status === 'completed' && one.expires_at
            ? t('exports.row.expires', { date: momentText(one.expires_at) })
            : '',
    ];

    return parts.filter((part) => part !== '').join(DETAIL_SEPARATOR);
}

/**
 * An export told for a row of the list.
 *
 * @param {ExportRecord} one
 * @param {{ deleting?: boolean }} [options] Whether the deletion of the export is on its way.
 * @returns {ExportRow}
 */
export function rowOf(one, { deleting = false } = {}) {
    const title = t(`exports.format.${one.format}`);
    const ready = one.status === 'completed' && typeof one.download_url === 'string' && one.download_url !== '';

    return {
        id: one.id,
        title,
        details: detailsOf(one),
        error: one.status === 'failed' ? (one.error ?? '') : '',
        statusLabel: t(`exports.status.${one.status}`),
        badgeClass: BADGE_CLASSES[one.status] ?? BADGE_CLASSES.pending,
        ready,
        url: ready ? one.download_url : null,
        downloadLabel: t('files.download_named', { name: one.file_name ?? title }),
        deleteLabel: t('exports.delete_named', { format: title, date: momentText(one.created_at) }),
        deleting,
    };
}
