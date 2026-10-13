/**
 * Alpine component behind the language switcher in the header.
 *
 * Saving the choice server-side (rather than only reloading with a query
 * parameter) lets the same preference apply to every later page and to API
 * clients that share the session.
 */
import { HttpError, http } from '../lib/http.js';
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
         * Failures the HTTP helper already reported (network, 5xx, expired
         * session...) are not repeated. For the others the message shown is
         * either the server's translated validation message for the locale
         * field or the helper's own translated message, never raw response text.
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

                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.errors.locale?.[0] ?? error.message);
                }

                return;
            }

            reloadPage();
        },
    };
}
