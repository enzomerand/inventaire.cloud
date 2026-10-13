/**
 * Alpine component wrapping the toast stack (see components/toasts.blade.php).
 *
 * The template reads and drives the `toasts` store directly (`$store.toasts`),
 * so the component only holds what an expression cannot express: with the CSP
 * build of Alpine, string building lives in a method registered through
 * `Alpine.data()`.
 */

/**
 * @returns {{ levelClass: (toast: import('../stores/toasts.js').Toast) => string }}
 */
export default function toasts() {
    return {
        /**
         * Tabler border utility giving the toast its level colour.
         *
         * @param {import('../stores/toasts.js').Toast} toast
         * @returns {string}
         */
        levelClass(toast) {
            return `border-${toast.type}`;
        },
    };
}
