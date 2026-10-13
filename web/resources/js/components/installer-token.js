/**
 * Alpine component of the setup token form, the first screen of the install
 * wizard.
 *
 * Whoever opens /install must prove they can read the server (the token is in
 * the application log and printed by an artisan command). On success the page
 * reloads so the server renders the wizard itself for the now authorized
 * session.
 */
import { HttpError, http } from '../lib/http.js';
import { reloadPage } from '../lib/page.js';

/**
 * @returns {{
 *   token: string,
 *   error: string,
 *   busy: boolean,
 *   readonly url: string,
 *   readonly invalidClass: string,
 *   submit: () => Promise<void>,
 * }}
 */
export default function installerToken() {
    return {
        /** What the person typed. */
        token: '',

        /** Validation message of the token field; empty when there is none. */
        error: '',

        /** True while the token is being checked; blocks double submits. */
        busy: false,

        /** Endpoint verifying the token (POST), read from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Bootstrap class flagging the field, bound with `:class` (the CSP build has no inline conditions in markup). */
        get invalidClass() {
            return this.error === '' ? '' : 'is-invalid';
        },

        /**
         * Sends the token. A wrong token (422) is shown on the field; throttling and
         * server failures are already reported by the HTTP helper, so they are not
         * repeated here.
         *
         * @returns {Promise<void>}
         */
        async submit() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = '';

            try {
                await http.post(this.url, { token: this.token });
            } catch (error) {
                this.busy = false;

                if (error instanceof HttpError && error.status === 422) {
                    this.error = error.errors.token?.[0] ?? error.message;
                }

                return;
            }

            reloadPage();
        },
    };
}
