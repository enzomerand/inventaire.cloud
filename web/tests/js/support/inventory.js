/**
 * Builders for the tests of the items of an inventory: fields, items, and what the API answers for them.
 */
import { useMemoryStorage } from './storage.js';

export const INVENTORY = '/api/v1/inventories/tools';
export const ITEMS = INVENTORY + '/items';

/**
 * How the API says the list treats each type of field (`query_kind` and `summable` of a field): the answer of the server,
 * which the interface does not work out itself. Tested on the server side (FieldsApiTest).
 */
const QUERY_KINDS = {
    text: ['text', false],
    textarea: ['text', false],
    number: ['number', true],
    money: ['number', true],
    percentage: ['number', false],
    rating: ['number', false],
    boolean: ['boolean', false],
    date: ['temporal', false],
    datetime: ['temporal', false],
    time: ['temporal', false],
    select: ['choice', false],
    multiselect: ['choices', false],
    tags: ['tags', false],
    url: ['text', false],
    email: ['text', false],
    phone: ['text', false],
    color: ['text', false],
    barcode: ['text', false],
    file: ['files', false],
    photo: ['files', false],
};

/**
 * What the API says of a field of a type about the list: `query_kind` (null for a type the server does not know) and
 * `summable`.
 *
 * @param {string} type
 * @returns {{ query_kind: string | null, summable: boolean }}
 */
export function queryFacts(type) {
    const [queryKind, summable] = QUERY_KINDS[type] ?? [null, false];

    return { query_kind: queryKind, summable };
}

/** A field of the inventory, as the API describes it (with the way the list sorts, filters and adds it up). */
export function field(id, type, options = {}, extra = {}) {
    return { id, name: 'Field ' + id, type, options, required: false, position: 0, ...queryFacts(type), ...extra };
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

/** An attachment as `ItemResource` describes it: a JPEG with a thumbnail, unless the test says otherwise. */
export function attachment(id, fieldId = null, extra = {}) {
    return {
        id,
        field: fieldId,
        name: id + '.jpg',
        mime: 'image/jpeg',
        size: 2048,
        width: 640,
        height: 480,
        has_thumbnail: true,
        uploaded_at: '2026-10-15T11:30:00Z',
        uploaded_by: null,
        url: `/api/v1/attachments/${id}`,
        thumbnail_url: `/api/v1/attachments/${id}/thumbnail`,
        ...extra,
    };
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
 * @param {{ canEdit?: boolean, maxAttachmentSize?: number, laterTick?: boolean }} [options] `maxAttachmentSize`: the largest
 *   file the page says the server takes, in bytes (the page says nothing when not given). `laterTick`: `$nextTick` runs the
 *   function after the code that asked is done, as Alpine's does, instead of at once (which hides the bugs of what is
 *   assumed to have happened by the time the function runs).
 * @returns {Record<string, any>}
 */
export function mountComponent(factory, { canEdit = true, maxAttachmentSize = undefined, laterTick = false } = {}) {
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

    if (maxAttachmentSize !== undefined) {
        root.dataset.maxAttachmentSize = String(maxAttachmentSize);
    }

    component.$root = root;
    component.$refs = {
        modal: document.createElement('div'),
        filters: document.createElement('div'),
        search: document.createElement('input'),
        picker: document.createElement('input'),
    };
    component.$nextTick = laterTick ? (callback) => void queueMicrotask(callback) : (callback) => callback();
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
