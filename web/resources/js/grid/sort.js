/**
 * The sort of the list of items: which keys it takes, and how a click on a header moves from one state to the next.
 */

/** The sort by the date an item was added. */
export const CREATED = 'created_at';

/** The sort by the date an item was last changed. */
export const UPDATED = 'updated_at';

/**
 * The direction a list is sorted in by a key.
 *
 * @param {import('./query.js').Query} query
 * @param {string} key `created_at`, `updated_at` or the id of a field.
 * @returns {'asc' | 'desc' | null} Null when the list is sorted by something else.
 */
export function directionBy(query, key) {
    return query.sort === key ? query.direction : null;
}

/**
 * The sort a click on the header of a key leads to: ascending, then descending, then none (the newest first).
 *
 * @param {import('./query.js').Query} query The sort now.
 * @param {string} key `created_at`, `updated_at` or the id of a field.
 * @returns {{ sort: string, direction: 'asc' | 'desc' }}
 */
export function cycleSort(query, key) {
    const direction = directionBy(query, key);

    if (direction === null) {
        return { sort: key, direction: 'asc' };
    }

    return direction === 'asc' ? { sort: key, direction: 'desc' } : { sort: '', direction: 'asc' };
}
