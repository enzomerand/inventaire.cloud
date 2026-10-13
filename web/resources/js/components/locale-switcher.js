/**
 * Alpine component behind the language switcher in the header.
 *
 * Saving the choice server-side (rather than only reloading with a query
 * parameter) lets the same preference apply to every later page and to API
 * clients that share the session.
 */
import { http } from '../lib/http.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';

/**
 * @param {string} url Endpoint that stores the chosen locale (PUT).
 * @returns {{ busy: boolean, select: (locale: string) => Promise<void> }}
 */
export default function localeSwitcher(url) {
    return {
        /** True while a change is in flight; blocks double submits. */
        busy: false,

        /**
         * Saves the locale then reloads so the whole page is rendered in it.
         *
         * Failures that the HTTP helper already reports (network, 5xx, expired
         * session) are not repeated here; only the remaining client errors
         * (e.g. a rejected locale) get a toast.
         *
         * @param {string} locale
         * @returns {Promise<void>}
         */
        async select(locale) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.put(url, { locale });
            } catch (error) {
                this.busy = false;

                const alreadyReported = error.status === 0 || error.status >= 500 || error.status === 419;
                if (!alreadyReported) {
                    notify('danger', error.message);
                }

                return;
            }

            reloadPage();
        },
    };
}
