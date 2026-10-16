/**
 * How a value is shown in a table cell or in the details of an item.
 *
 * A cell is plain data, never markup: the templates choose the element from `kind` and bind the rest with `x-text` and
 * attribute bindings, so that nothing a person typed is ever interpreted as HTML. (A link is opened by the template with
 * `rel="noopener noreferrer nofollow"`: no access to the page that opened it, no referrer, no weight for search engines.)
 *
 * @typedef {object} Badge
 * @property {string} text What the badge says.
 * @property {string} class Its classes.
 *
 * @typedef {object} FileEntry A file attached to an item, as a cell shows it (see `files.js`).
 * @property {string} id The id of the attachment.
 * @property {string} name Its name, which is also the alternative text of its thumbnail.
 * @property {string} mime
 * @property {number} size In bytes.
 * @property {string} sizeText The size as a person reads it ("1.2 MB").
 * @property {'image' | 'pdf' | 'file'} icon Which icon stands for it when it has no thumbnail.
 * @property {string} download The address that downloads it.
 * @property {string | null} thumb The address of its thumbnail, null when it has none.
 * @property {string | null} href Where a click on it leads: null where it is not a link.
 * @property {string | null} target Where that link opens (`_blank` for a file the browser displays, nothing for a download).
 *
 * @typedef {object} Cell
 * @property {'empty' | 'text' | 'mono' | 'badges' | 'link' | 'stars' | 'swatch' | 'photos' | 'files'} kind Which element shows it.
 * @property {string} text The text of the cell (the label of a link, the stars of a rating, the code of a color...).
 * @property {string | null} href Where a link leads.
 * @property {string | null} target Where a link opens (`_blank` for a web page, nothing for a mail or a call).
 * @property {boolean} multiline Whether the text keeps its line breaks (a long text shown whole).
 * @property {string | null} title A tooltip: the full text of a shortened one, the grade of a rating.
 * @property {Badge[]} badges The badges of a choice or of tags.
 * @property {Record<string, string> | null} style Style of the swatch of a color (an object: Alpine sets it without an inline style attribute).
 * @property {FileEntry[]} files The files a photo or file field shows: the first one in a table cell, all of them in the details.
 * @property {number} more How many more files the field holds than it shows.
 */

/**
 * Builds a cell, with every property present so that a template can read any of them.
 *
 * @param {Cell['kind']} kind
 * @param {Partial<Cell>} [properties]
 * @returns {Cell}
 */
export function cell(kind, properties = {}) {
    return {
        kind,
        text: '',
        multiline: false,
        href: null,
        target: null,
        title: null,
        badges: [],
        style: null,
        files: [],
        more: 0,
        ...properties,
    };
}

/**
 * The cell of a value that is not there.
 *
 * @returns {Cell}
 */
export function emptyCell() {
    return cell('empty', { text: '—' });
}
