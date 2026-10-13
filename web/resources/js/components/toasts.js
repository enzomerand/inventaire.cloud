/**
 * Alpine component rendering the toast stack (see components/toasts.blade.php).
 *
 * It only adapts the `toasts` store to what the template needs. With the CSP
 * build of Alpine templates cannot hold logic such as inline arrow functions or
 * string building, so every piece of behaviour is a named property or method
 * here, registered through `Alpine.data()`.
 */

/**
 * @returns {{
 *   readonly polite: import('../stores/toasts.js').Toast[],
 *   readonly assertive: import('../stores/toasts.js').Toast[],
 *   levelClass: (toast: import('../stores/toasts.js').Toast) => string,
 *   dismiss: (id: number) => void,
 * }}
 */
export default function toasts() {
    return {
        /** Toasts for the polite live region (success, info). */
        get polite() {
            return this.$store.toasts.polite;
        },

        /** Toasts for the assertive live region (warning, danger). */
        get assertive() {
            return this.$store.toasts.assertive;
        },

        /**
         * Tabler border utility giving the toast its level colour.
         *
         * @param {import('../stores/toasts.js').Toast} toast
         * @returns {string}
         */
        levelClass(toast) {
            return `border-${toast.type}`;
        },

        /**
         * Closes a toast before its timer does.
         *
         * @param {number} id
         * @returns {void}
         */
        dismiss(id) {
            this.$store.toasts.dismiss(id);
        },
    };
}
