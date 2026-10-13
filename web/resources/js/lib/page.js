/**
 * Thin wrapper over page-level navigation side effects.
 *
 * Kept in its own module so callers (and their tests) can replace the reload
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
