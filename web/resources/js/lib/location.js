/**
 * The query string of the address of the page, read and rewritten in place.
 *
 * Kept in its own module, like `page.js`, so that what a page shows can be kept in its address (a search, a sort, the
 * filters of a list) without a component touching `window.history` itself.
 */

/**
 * The query string of the address of the page, with its leading `?` (an empty string when there is none).
 *
 * @returns {string}
 */
export function currentSearch() {
    return window.location.search;
}

/**
 * Replaces the query string of the address of the page, without adding an entry to the history and without reloading
 * the page: Back leaves the page, and a reload comes back to the same view. The path and the fragment stay.
 *
 * A browser that refuses (a sandboxed frame, say) is left alone: the address is a convenience, never a requirement.
 *
 * @param {string} search The new query string, with or without its leading `?`; empty to remove it.
 * @returns {void}
 */
export function replaceSearch(search) {
    const query = search.replace(/^\?/, '');
    const url = window.location.pathname + (query === '' ? '' : '?' + query) + window.location.hash;

    try {
        window.history.replaceState(window.history.state, '', url);
    } catch {
        // The address stays as it is.
    }
}
