/**
 * Alpine component of the notice that the server leaves for the next page (see components/flash-notice.blade.php):
 * it shows the message as a toast, once, when the page loads.
 *
 * The type and the message come from the markup (`data-type`, `data-message` on the root), already translated by the
 * server: the CSP build of Alpine does not allow Blade to inject values into expressions, and scripts never hold
 * display text of their own.
 */
import { notify } from '../lib/notify.js';

/**
 * @returns {Record<string, any>}
 */
export default function flashNotice() {
    return {
        /**
         * Shows the notice, unless the markup holds no message.
         *
         * @returns {void}
         */
        init() {
            const { type, message } = this.$root.dataset;

            if (message) {
                notify(type ?? 'info', message);
            }
        },
    };
}
