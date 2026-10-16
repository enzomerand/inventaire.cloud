/**
 * Builders for the tests of the items of an inventory: fields, items, and what the API answers for them.
 */
import { useMemoryStorage } from './storage.js';

export const INVENTORY = '/api/v1/inventories/tools';
export const ITEMS = INVENTORY + '/items';

/** A field of the inventory. */
export function field(id, type, options = {}, extra = {}) {
    return { id, name: 'Field ' + id, type, options, required: false, position: 0, ...extra };
}

export const FIELDS = [
    field('name', 'text', { max_length: 100 }, { name: 'Name', required: true }),
    field('qty', 'number', { decimals: 2, unit: 'kg' }, { name: 'Weight' }),
    field('price', 'money', { currency: 'EUR' }, { name: 'Price' }),
    field(
        'state',
        'select',
        {
            choices: [
                { id: 'aaaaaaaa', label: 'New', color: 'green' },
                { id: 'bbbbbbbb', label: 'Used' },
            ],
        },
        { name: 'State' },
    ),
    field(
        'parts',
        'multiselect',
        {
            choices: [
                { id: 'cccccccc', label: 'Red' },
                { id: 'dddddddd', label: 'Blue' },
                { id: 'eeeeeeee', label: 'Green' },
            ],
        },
        { name: 'Parts' },
    ),
    field('fragile', 'boolean', {}, { name: 'Fragile' }),
    field('bought', 'date', {}, { name: 'Bought' }),
    field('seen', 'datetime', {}, { name: 'Seen' }),
    field('labels', 'tags', {}, { name: 'Labels' }),
    field('grade', 'rating', { max: 5 }, { name: 'Grade' }),
    field('tint', 'color', {}, { name: 'Tint' }),
];

/**
 * An id in the form of the id of a field (a ULID: 26 letters and digits), made of a readable name, since the address of the
 * page only takes ids in that form for the fields it filters.
 *
 * @param {string} name Letters and digits, at most 26.
 * @returns {string}
 */
export function ulid(name) {
    return name.padStart(26, '0');
}

/**
 * The fields of the inventory with the ids of some of them turned into ULIDs (see {@link ulid}).
 *
 * @param {string[]} names The ids to turn.
 * @param {Record<string, any>[]} [fields]
 * @returns {Record<string, any>[]}
 */
export function withUlids(names, fields = FIELDS) {
    return fields.map((one) => (names.includes(one.id) ? { ...one, id: ulid(one.id) } : one));
}

/** An item as `ItemResource` describes it. */
export function item(id, values = {}) {
    return { id, values, created_at: '2026-10-15T10:00:00Z', updated_at: '2026-10-15T10:00:00Z' };
}

/** What `GET /api/v1/inventories/{inventory}` answers. */
export function inventoryAnswer(fields = FIELDS, itemsCount = undefined) {
    return { data: { id: 'tools', name: 'Tools', items_count: itemsCount, fields } };
}

/** What `GET /api/v1/inventories/{inventory}/items` answers for a page. */
export function page(
    items,
    {
        current = 1,
        last = 1,
        total = items.length,
        from = items.length ? 1 : null,
        to = items.length || null,
        totals = {},
    } = {},
) {
    return { data: items, meta: { current_page: current, last_page: last, total, from, to, totals } };
}

/**
 * A component as Alpine would hand it to the page: the root with its data attributes, the refs, and a `$nextTick` that
 * runs at once.
 *
 * @param {() => Record<string, any>} factory The component function.
 * @param {{ canEdit?: boolean }} [options]
 * @returns {Record<string, any>}
 */
export function mountComponent(factory, { canEdit = true } = {}) {
    const component = factory();

    const root = document.createElement('div');

    Object.assign(root.dataset, {
        url: INVENTORY,
        itemsUrl: ITEMS,
        itemUrl: ITEMS + '/__id__',
        workspacePage: '/app/w/shop',
        canEdit: canEdit ? '1' : '0',
        canManageFields: '1',
    });
    component.$root = root;
    component.$refs = {
        modal: document.createElement('div'),
        filters: document.createElement('div'),
        search: document.createElement('input'),
    };
    component.$nextTick = (callback) => callback();
    document.documentElement.lang = 'en';
    // The component keeps its search, sort and filters in the address: every test starts from a clean one.
    window.history.replaceState(null, '', '/');
    useMemoryStorage();

    return component;
}

/** A promise that is settled by hand, to answer a request when the test says so. */
export function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => {
        resolve = done;
        reject = fail;
    });

    return { promise, resolve, reject };
}
