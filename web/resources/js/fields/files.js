/**
 * The files attached to an item, as the interface shows them.
 *
 * An item carries its attachments (`attachments` of `ItemResource`); the value of a `file` or `photo` field is the ordered
 * list of the ids of the attachments that belong to it. This module turns both into what the templates need: a
 * {@link import('./cell.js').FileEntry} for each file (its name, its size as a person reads it, its thumbnail, where a click
 * on it leads) and the {@link import('./cell.js').Cell} of a field. Names are data for `x-text` and attribute bindings,
 * never markup, and a thumbnail always has its file name as alternative text.
 */
import { cell, emptyCell } from './cell.js';
import { numberFormat } from './locale.js';

/** The images a photo field takes: the ones the server accepts, and every browser shows. */
export const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

/** What the server lets a browser display in the page (`?inline=1`): these images and PDF documents. */
const DISPLAYABLE_TYPES = [...PHOTO_TYPES, 'application/pdf'];

/** The size of the largest file the server takes when the page does not say (20 MiB). */
export const DEFAULT_MAX_FILE_SIZE = 20 * 1024 * 1024;

/** How many files a cell of the table names; the others are counted ("+2"). */
export const CELL_FILES = 1;

/** The most files a field can hold (the server refuses more). */
export const MAX_FILES_PER_FIELD = 10;

/** The units a size is written in, as `Intl` names them. */
const SIZE_UNITS = ['byte', 'kilobyte', 'megabyte', 'gigabyte'];

/**
 * @typedef {import('./types.js').Field} Field
 * @typedef {import('./cell.js').FileEntry} FileEntry
 *
 * @typedef {object} Attachment A file attached to an item, as `ItemResource` describes it.
 * @property {string} id
 * @property {string | null} field The id of the field it belongs to; null for a file attached to the item itself.
 * @property {string} name
 * @property {string} mime
 * @property {number} size In bytes.
 * @property {number | null} [width]
 * @property {number | null} [height]
 * @property {boolean} has_thumbnail
 * @property {string} uploaded_at
 * @property {{ name: string } | null} uploaded_by Who added it (only their name is given); null once their account is gone.
 * @property {string} url Where it is downloaded; with `?inline=1` it is displayed, when it is an image or a PDF.
 * @property {string | null} thumbnail_url Its JPEG thumbnail, when it has one.
 */

/**
 * A size in bytes as a person reads it, in the language of the page ("512 B", "1.2 MB", "3,4 Mo"). Units go by 1024, so
 * that the limit of the server (20 MiB) reads "20 MB".
 *
 * @param {number} bytes
 * @returns {string}
 */
export function formatSize(bytes) {
    let value = Number.isFinite(bytes) && bytes > 0 ? bytes : 0;
    let unit = 0;

    while (value >= 1024 && unit < SIZE_UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return numberFormat({
        style: 'unit',
        unit: SIZE_UNITS[unit],
        unitDisplay: 'short',
        maximumFractionDigits: unit === 0 ? 0 : 1,
    }).format(value);
}

/**
 * How many files a field can hold: its `max_files` option (1 to 10, one when it says nothing).
 *
 * @param {Field} field
 * @returns {number}
 */
export function maxFilesOf(field) {
    const wanted = Math.trunc(Number(field.options?.max_files ?? 1));

    return Number.isFinite(wanted) ? Math.min(Math.max(wanted, 1), MAX_FILES_PER_FIELD) : 1;
}

/**
 * The `accept` of the file chooser of a field: the images a photo field takes, anything for a file field.
 *
 * @param {Field} field
 * @returns {string}
 */
export function acceptOf(field) {
    return field.type === 'photo' ? PHOTO_TYPES.join(',') : '';
}

/**
 * Whether a browser can display a file in the page (the server serves only these inline).
 *
 * @param {string} mime
 * @returns {boolean}
 */
export function isDisplayable(mime) {
    return DISPLAYABLE_TYPES.includes(mime);
}

/**
 * A file as a template shows it.
 *
 * @param {Attachment} attachment
 * @param {boolean} [linked] Whether a click on it leads somewhere: the file is displayed in a new tab when the browser can
 *   (an image, a PDF), downloaded otherwise.
 * @returns {FileEntry}
 */
export function fileEntry(attachment, linked = false) {
    const displayable = isDisplayable(attachment.mime);

    return {
        id: attachment.id,
        name: attachment.name,
        mime: attachment.mime,
        size: attachment.size,
        sizeText: formatSize(attachment.size),
        icon: PHOTO_TYPES.includes(attachment.mime) ? 'image' : attachment.mime === 'application/pdf' ? 'pdf' : 'file',
        download: attachment.url,
        thumb: attachment.has_thumbnail && attachment.thumbnail_url ? attachment.thumbnail_url : null,
        href: !linked ? null : displayable ? `${attachment.url}?inline=1` : attachment.url,
        target: linked && displayable ? '_blank' : null,
    };
}

/**
 * The attachments of a field, in the order of its value (the ids of the attachments). An id nobody knows is skipped.
 *
 * @param {Field} field
 * @param {any} value The value of the field in the item.
 * @param {Attachment[]} attachments The attachments of the item.
 * @returns {Attachment[]}
 */
export function attachmentsOfField(field, value, attachments) {
    const ids = Array.isArray(value) ? value : [];

    return ids
        .map((id) => attachments.find((attachment) => attachment.id === id && attachment.field === field.id))
        .filter((attachment) => attachment !== undefined);
}

/**
 * The cell of a photo or file field.
 *
 * @param {'photos' | 'files'} kind
 * @param {any} value The value of the field in the item (the ids of its attachments).
 * @param {Field} field
 * @param {import('./types.js').DescribeOptions} [options] `attachments`: the attachments of the item; `full`: the details of
 *   an item, where every file is shown and linked, rather than the first one of a table cell.
 * @returns {import('./cell.js').Cell}
 */
export function filesCell(kind, value, field, options = {}) {
    const held = attachmentsOfField(field, value, options.attachments ?? []);

    if (held.length === 0) {
        return emptyCell();
    }

    const shown = options.full ? held : held.slice(0, CELL_FILES);

    return cell(kind, {
        text: held.map((attachment) => attachment.name).join(', '),
        files: shown.map((attachment) => fileEntry(attachment, options.full === true)),
        more: held.length - shown.length,
    });
}

/**
 * The picture that stands for an item on a card of the gallery: the first photo of its first photo field that has one, with
 * its thumbnail.
 *
 * @param {Field[]} fields The fields of the inventory, in order.
 * @param {{ values?: Record<string, any>, attachments?: Attachment[] }} item
 * @returns {{ fieldId: string, src: string, alt: string, more: number } | null} Null when no photo of the item has a thumbnail.
 */
export function coverOf(fields, item) {
    for (const field of fields.filter((one) => one.type === 'photo')) {
        const photos = attachmentsOfField(field, item.values?.[field.id], item.attachments ?? []);
        const first = photos.find((photo) => photo.has_thumbnail && photo.thumbnail_url);

        if (first !== undefined) {
            return { fieldId: field.id, src: first.thumbnail_url ?? '', alt: first.name, more: photos.length - 1 };
        }
    }

    return null;
}
