/**
 * Thin wrapper over page-level navigation side effects.
 *
 * Kept in its own module so callers (and their tests) can replace navigation
 * without touching `window.location`, which browsers and jsdom make immutable.
 */

/**
 * Reloads the current page, e.g. to refresh an expired CSRF token or to render
 * the interface in a newly selected language.
 *
 * @returns {void}
 */
export function reloadPage() {
    window.location.reload();
}

/**
 * Navigates to another URL, e.g. the login page after the session ended.
 *
 * @param {string} url Same-origin path or absolute URL.
 * @returns {void}
 */
export function redirectTo(url) {
    window.location.assign(url);
}

/**
 * Hands a file to the browser to save, as a download.
 *
 * @param {Blob} blob The content of the file.
 * @param {string} filename Name the browser proposes.
 * @returns {void}
 */
export function saveFile(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();

    // Once the browser has taken the file.
    setTimeout(() => URL.revokeObjectURL(url), 0);
}
