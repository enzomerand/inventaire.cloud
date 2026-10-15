/**
 * What the list of items is asked for: a search, a sort and filters, kept apart from the page and from the interface.
 *
 * The same description serves three purposes. It is held by the component (a {@link Query}); it is sent to the API as
 * the parameters of `GET /api/v1/inventories/{inventory}/items` ({@link toParams}); and it is written in the address of the
 * page ({@link toSearch}) and read back from it ({@link fromSearch}), so that a reload, or the Back button, brings the
 * same view back. The address uses the very names of the API (`q`, `sort`, `direction`, `filter[<field>][<condition>]`)
 * plus `page`, so there is one format to know.
 *
 * Values are the canonical ones of the API (`"89.90"`, `"2026-10-15"`, `"2026-10-15T12:30:00Z"`); what a person types in a
 * box is converted to them by `filters.js`. Nothing here talks to the server: whether a field still exists, or can be sorted,
 * is for the server to say (it answers `422`), and the component starts again from {@link emptyQuery}.
 */

/** Longest search the API takes (`ListItemsRequest::SEARCH_MAX_LENGTH`). */
export const SEARCH_MAX_LENGTH = 200;

/** Longest sort the API takes (`ListItemsRequest::SORT_MAX_LENGTH`). */
const SORT_MAX_LENGTH = 64;

/** Highest page the API takes. */
const MAX_PAGE = 1_000_000;

/** A condition of a filter in the address or in the parameters: `filter[<field>][<condition>]`, with `[<index>]` after `in`. */
const FILTER_KEY = /^filter\[([^\]]+)\]\[(empty|min|max|is|in)\](?:\[\d*\])?$/;

/**
 * @typedef {object} Filter The conditions on one field, as the API takes them. All of them have to hold.
 * @property {boolean} [empty] True for the items with no value in the field, false for the ones with one.
 * @property {string} [min] Lowest value, included (a number, a date, a moment in UTC or a time).
 * @property {string} [max] Highest value, included.
 * @property {boolean} [is] The answer of a yes / no field.
 * @property {string[]} [in] The ids of the choices an item holds one of.
 *
 * @typedef {object} Query
 * @property {string} q The words to look for, "" for no search.
 * @property {string} sort What to sort by: "" for the newest first, `created_at`, `updated_at` or the id of a field.
 * @property {'asc' | 'desc'} direction The direction of the sort (nothing without `sort`).
 * @property {Record<string, Filter>} filters The filters, by the id of their field.
 */

/**
 * A query that asks for everything, newest first.
 *
 * @returns {Query}
 */
export function emptyQuery() {
    return { q: '', sort: '', direction: 'asc', filters: {} };
}

/**
 * Whether a query narrows the list down (a search or a filter; a sort does not).
 *
 * @param {Query} query
 * @returns {boolean}
 */
export function hasCriteria(query) {
    return query.q !== '' || Object.keys(query.filters).length > 0;
}

/**
 * Whether a query is the one that asks for everything, newest first.
 *
 * @param {Query} query
 * @returns {boolean}
 */
export function isEmptyQuery(query) {
    return !hasCriteria(query) && query.sort === '';
}

/**
 * The parameters that ask the API for a page of the list, or that write the same view in the address. The names are
 * flat and carry the brackets (`filter[<field>][min]`), because the HTTP helper takes one value per name; the choices
 * of a filter are numbered (`filter[<field>][in][0]`), which PHP reads as it reads `[]`.
 *
 * @param {Query} query
 * @param {{ page?: number | null }} [options] The page to ask for; left out of the parameters when null.
 * @returns {Record<string, string | number>}
 */
export function toParams(query, { page = null } = {}) {
    /** @type {Record<string, string | number>} */
    const params = {};

    if (page !== null) {
        params.page = page;
    }

    if (query.q !== '') {
        params.q = query.q;
    }

    if (query.sort !== '') {
        params.sort = query.sort;

        // Ascending is what the API does without a direction.
        if (query.direction === 'desc') {
            params.direction = 'desc';
        }
    }

    for (const [id, filter] of Object.entries(query.filters)) {
        const at = `filter[${id}]`;

        if (filter.empty !== undefined) {
            params[`${at}[empty]`] = String(filter.empty);
        }

        if (filter.min !== undefined) {
            params[`${at}[min]`] = filter.min;
        }

        if (filter.max !== undefined) {
            params[`${at}[max]`] = filter.max;
        }

        if (filter.is !== undefined) {
            params[`${at}[is]`] = String(filter.is);
        }

        (filter.in ?? []).forEach((choice, index) => {
            params[`${at}[in][${index}]`] = choice;
        });
    }

    return params;
}

/**
 * The query string that writes a view in the address of the page: the parameters of the API, and the page unless it is
 * the first one.
 *
 * @param {Query} query
 * @param {{ page?: number }} [options]
 * @returns {string} Without the leading `?`; empty for the default view.
 */
export function toSearch(query, { page = 1 } = {}) {
    return new URLSearchParams(
        /** @type {Record<string, string>} */ (toParams(query, { page: page > 1 ? page : null })),
    ).toString();
}

/**
 * Reads a view back from the query string of the address of the page. Anything that makes no sense (a direction that is
 * neither `asc` nor `desc`, a page that is no number, a condition nobody knows) is left out, so that a hand-edited
 * address never breaks the page; whether the fields and the values it mentions are right is for the server to say.
 *
 * @param {string} search The query string, with or without its leading `?`.
 * @returns {{ query: Query, page: number }}
 */
export function fromSearch(search) {
    const params = new URLSearchParams(search);
    const query = emptyQuery();

    query.q = (params.get('q') ?? '').trim().slice(0, SEARCH_MAX_LENGTH);
    query.sort = (params.get('sort') ?? '').trim().slice(0, SORT_MAX_LENGTH);
    query.direction = query.sort !== '' && params.get('direction') === 'desc' ? 'desc' : 'asc';

    for (const [key, value] of params) {
        const condition = FILTER_KEY.exec(key);

        if (condition !== null) {
            addCondition(query.filters, condition[1].toLowerCase(), condition[2], value);
        }
    }

    const page = Number.parseInt(params.get('page') ?? '', 10);

    return { query, page: Number.isInteger(page) && page >= 1 ? Math.min(page, MAX_PAGE) : 1 };
}

/**
 * Adds one condition read from the address to the filters, unless its value makes no sense for it.
 *
 * @param {Record<string, Filter>} filters
 * @param {string} id The field.
 * @param {string} condition `empty`, `min`, `max`, `is` or `in`.
 * @param {string} value
 * @returns {void}
 */
function addCondition(filters, id, condition, value) {
    if (value === '') {
        return;
    }

    const filter = (filters[id] ??= {});

    if (condition === 'empty' || condition === 'is') {
        if (value === 'true' || value === 'false') {
            filter[condition] = value === 'true';
        }
    } else if (condition === 'in') {
        (filter.in ??= []).push(value);
    } else {
        filter[condition] = value;
    }

    // A field whose only conditions were refused has no filter.
    if (Object.keys(filter).length === 0) {
        delete filters[id];
    }
}
