/**
 * Alpine component behind the language switcher in the header.
 *
 * Saving the choice server-side (rather than only reloading with a query
 * parameter) lets the same preference apply to every later page and to API
 * clients. The endpoint and the locales come from the markup (`data-url` on the
 * root, `data-locale` on each item) because the CSP build of Alpine does not
 * allow Blade to inject values into expressions.
 */
import { HttpError, http } from '../lib/http.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';

/**
 * @returns {{
 *   busy: boolean,
 *   readonly url: string,
 *   select: (event: Event) => Promise<void>,
 * }}
 */
export default function localeSwitcher() {
    return {
        /** True while a change is in flight; blocks double submits. */
        busy: false,

        /**
         * Endpoint that stores the chosen locale (PUT), read from `data-url` on
         * the component root. `$root`, not `$el`: handlers run with `$el` set to
         * the clicked item.
         */
        get url() {
            return this.$root.dataset.url;
        },

        /**
         * Saves the locale of the clicked item then reloads so the whole page is
         * rendered in it.
         *
         * Failures the HTTP helper already reported (network, 5xx, expired
         * session...) are not repeated. For the others the message shown is
         * either the server's translated validation message for the locale
         * field or the helper's own translated message, never raw response text.
         *
         * @param {Event} event Click on an element carrying `data-locale`.
         * @returns {Promise<void>}
         */
        async select(event) {
            // currentTarget is only set while the event is being dispatched.
            const locale = event.currentTarget.dataset.locale;

            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.put(this.url, { locale });
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
