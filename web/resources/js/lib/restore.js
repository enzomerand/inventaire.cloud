/**
 * What to do when the browser brings a page back from its back/forward cache.
 */
import { reloadPage } from './page.js';

/**
 * Reloads the page whenever the browser brings it back from its back/forward cache (`pageshow` with `persisted`).
 *
 * The cache restores a page as it was left, scripts and all: a Create button still disabled because the request that
 * navigated away was in flight, a switcher that no longer answers, a table whose columns are those of before the
 * fields were edited. The page after a reload is the one the server would give now.
 *
 * @returns {void}
 */
export function reloadWhenRestored() {
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            reloadPage();
        }
    });
}
