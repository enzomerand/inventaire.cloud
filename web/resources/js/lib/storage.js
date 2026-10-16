/**
 * Small preferences kept in the browser (`localStorage`), for the conveniences a person would be annoyed to set again: the
 * view they last chose for a list, say.
 *
 * Storage may be missing or refuse (a private window, blocked site data, a full quota, a sandboxed frame): every access is
 * guarded, and the page works the same without it.
 */

/**
 * Reads a preference.
 *
 * @param {string} key
 * @returns {string | null} Null when there is none, or when the browser would not say.
 */
export function readPreference(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

/**
 * Keeps a preference, if the browser lets it.
 *
 * @param {string} key
 * @param {string} value
 * @returns {void}
 */
export function writePreference(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // The choice lasts until the page is left, which is all a refusing browser allows.
    }
}
