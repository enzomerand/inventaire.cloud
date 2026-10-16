/**
 * What the list of items is asked for: a search, a sort and filters, kept apart from the page and from the interface.
 *
 * The same description serves three purposes. It is held by the component (a {@link Query}); it is sent to the API as
 * the parameters of `GET /api/v1/inventories/{inventory}/items` ({@link toParams}); and it is written in the address of the
 * page ({@link toSearch}) and read back from it ({@link fromSearch}), so that a reload, or a page restored by the browser
 * (history navigation, a discarded tab), shows the same view again. The address is replaced, not added to (see
 * `lib/location.js`): Back leaves the page, it does not step through the searches. The address uses the very names of the
 * API (`q`, `sort`, `direction`, `filter[<field>][<condition>]`) plus `page` and `view`, so there is one format to know.
 *
 * Values are the canonical ones of the API (`"89.90"`, `"2026-10-15"`, `"2026-10-15T12:30:00Z"`); what a person types in a
 * box is converted to them by `filters.js`. Nothing here talks to the server: whether a field still exists, or can be sorted,
 * is for the server to say (it answers `422`), and the component starts again from {@link emptyQuery}.
 */

import { parseView } from './view.js';

/** Longest search the API takes (`ListItemsRequest::SEARCH_MAX_LENGTH`). */
export const SEARCH_MAX_LENGTH = 200;

/** Longest sort the API takes (`ListItemsRequest::SORT_MAX_LENGTH`). */
const SORT_MAX_LENGTH = 64;

/** Highest page the API takes. */
const MAX_PAGE = 1_000_000;

/** A condition of a filter in the address or in the parameters: `filter[<field>][<condition>]`, with `[<index>]` after `in`. */
const FILTER_KEY = /^filter\[([^\]]+)\]\[(empty|min|max|is|in)\](?:\[\d*\])?$/;

/** The id of a field: a ULID, 26 letters and digits (lowercase on the server, read in lowercase from the address). */
const FIELD_ID = /^[0-9a-z]{26}$/i;

/** The id of a choice of a field: 8 lowercase letters and digits (`ChoiceType::ID_LENGTH`). */
const CHOICE_ID = /^[a-z0-9]{8}$/;

/** A bound for a number the way the server takes it: below 1e15 in absolute value, with at most 6 decimals. */
export const NUMBER_BOUND = /^-?\d{1,15}(\.\d{1,6})?$/;

/** The forms in which the server stores a date, a moment and a time of day, which a bound must be written in. */
export const TEMPORAL_BOUND = {
    date: /^\d{4}-\d{2}-\d{2}$/,
    datetime: /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/,
    time: /^\d{2}:\d{2}$/,
};

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
 * What the totals of the list depend on: the search and the filters, not the sort nor the page. Two queries with the same
 * key have the same totals.
 *
 * @param {Query} query
 * @returns {string}
 */
export function totalsKeyOf(query) {
    const filters = Object.entries(query.filters).sort(([first], [second]) =>
        first < second ? -1 : first > second ? 1 : 0,
    );

    return JSON.stringify([query.q, filters.map(([id, filter]) => [id, Object.entries(filter).sort()])]);
}

/**
 * Takes out of a query the criteria the API refused, and only those. The refusal names them by the keys of its errors:
 * `q`, `sort`, `direction`, `filter.<field>` (the whole filter of the field) and `filter.<field>.<condition>` (that
 * condition), and `totals.<n>` for a field it does not add up (which says the fields the page holds are out of date, not
 * that a criterion is wrong).
 *
 * @param {Query} query
 * @param {Record<string, unknown>} errors The `errors` of the answer, by key.
 * @returns {{ query: Query, dropped: boolean, staleFields: boolean }} The query without the refused criteria (the very
 *   query when nothing was refused that it holds), whether something was dropped, and whether the fields are out of date.
 */
export function withoutRefused(query, errors) {
    const next = { ...query, filters: { ...query.filters } };
    let staleFields = false;

    for (const key of Object.keys(errors ?? {})) {
        if (key === 'q') {
            next.q = '';
        } else if (key === 'sort') {
            next.sort = '';
            next.direction = 'asc';
        } else if (key === 'direction') {
            next.direction = 'asc';
        } else if (key.startsWith('totals.') || key === 'totals') {
            staleFields = true;
        } else if (key.startsWith('filter.')) {
            const [id, condition] = key.slice('filter.'.length).split('.');
            const own = Object.keys(next.filters).find((candidate) => candidate.toLowerCase() === id.toLowerCase());

            if (own === undefined) {
                continue;
            }

            if (condition === undefined) {
                delete next.filters[own];

                continue;
            }

            const rest = { ...next.filters[own] };

            delete rest[/** @type {keyof Filter} */ (condition)];

            if (Object.keys(rest).length === 0) {
                delete next.filters[own];
            } else {
                next.filters[own] = rest;
            }
        }
    }

    const dropped = JSON.stringify(next) !== JSON.stringify(query);

    return { query: dropped ? next : query, dropped, staleFields };
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
 * The query string that writes a view in the address of the page: the parameters of the API, the page unless it is the
 * first one, and the way the items are laid out when the person chose one.
 *
 * @param {Query} query
 * @param {{ page?: number, view?: import('./view.js').View | null }} [options]
 * @returns {string} Without the leading `?`; empty for the default view.
 */
export function toSearch(query, { page = 1, view = null } = {}) {
    const params = /** @type {Record<string, string>} */ (toParams(query, { page: page > 1 ? page : null }));

    if (view !== null) {
        params.view = view;
    }

    return new URLSearchParams(params).toString();
}

/**
 * Reads a view back from the query string of the address of the page. Anything that makes no sense (a direction that is
 * neither `asc` nor `desc`, a page that is no number, a condition nobody knows, a field that is no ULID, a bound or a
 * choice written in no form the server takes) is left out on its own, so that a hand-edited address never breaks the page
 * nor costs the rest of the view; whether the fields and the values it mentions exist is for the server to say.
 *
 * @param {string} search The query string, with or without its leading `?`.
 * @returns {{ query: Query, page: number, view: import('./view.js').View | null }} The way to lay the items out is null
 *   when the address does not say.
 */
export function fromSearch(search) {
    const params = new URLSearchParams(search);
    const query = emptyQuery();

    query.q = clip((params.get('q') ?? '').trim(), SEARCH_MAX_LENGTH);
    query.sort = clip((params.get('sort') ?? '').trim(), SORT_MAX_LENGTH);
    query.direction = query.sort !== '' && params.get('direction')?.toLowerCase() === 'desc' ? 'desc' : 'asc';

    // The names in the address are the person's to choose: they land in a map without a prototype, and only as the id of a
    // field, never as a name that means something to an object (`__proto__`, `constructor`).
    /** @type {Record<string, Filter>} */
    const filters = Object.create(null);

    for (const [key, value] of params) {
        const condition = FILTER_KEY.exec(key);

        if (condition !== null) {
            addCondition(filters, condition[1].toLowerCase(), condition[2], value);
        }
    }

    query.filters = { ...filters };

    const page = Number.parseInt(params.get('page') ?? '', 10);

    return {
        query,
        page: Number.isInteger(page) && page >= 1 ? Math.min(page, MAX_PAGE) : 1,
        view: parseView(params.get('view')),
    };
}

/**
 * Whether a text is a bound in one of the forms the server takes (a number, a date, a moment, a time of day). Which of
 * them a field takes is for the server to say: this only turns away what no field takes, so that one wrong value in an
 * address costs that condition alone, not the whole view.
 *
 * @param {string} value
 * @returns {boolean}
 */
export function isBound(value) {
    return NUMBER_BOUND.test(value) || Object.values(TEMPORAL_BOUND).some((form) => form.test(value));
}

/**
 * Cuts a text to a number of characters, by code points, so that a character made of two UTF-16 units is never split.
 *
 * @param {string} text
 * @param {number} max
 * @returns {string}
 */
function clip(text, max) {
    const characters = [...text];

    return characters.length > max ? characters.slice(0, max).join('') : text;
}

/**
 * Adds one condition read from the address to the filters, unless the field or its value makes no sense: only the
 * condition is left out, the rest of the view stays.
 *
 * @param {Record<string, Filter>} filters A map without a prototype.
 * @param {string} id The field, in lowercase.
 * @param {string} condition `empty`, `min`, `max`, `is` or `in`.
 * @param {string} value
 * @returns {void}
 */
function addCondition(filters, id, condition, value) {
    if (!FIELD_ID.test(id)) {
        return;
    }

    if (condition === 'empty' || condition === 'is') {
        if (value === 'true' || value === 'false') {
            (filters[id] ??= {})[condition] = value === 'true';
        }
    } else if (condition === 'in') {
        if (CHOICE_ID.test(value)) {
            const filter = (filters[id] ??= {});

            if (!(filter.in ??= []).includes(value)) {
                filter.in.push(value);
            }
        }
    } else if (isBound(value)) {
        (filters[id] ??= {})[condition] = value;
    }
}
