/**
 * Alpine component of the security card of the account page: signing out of every other device.
 *
 * The action asks for a confirmation first, in the card itself. This device stays signed in (the server signs it in
 * again with a fresh session). The endpoint comes from the markup (`data-url` on the root), because the CSP build of
 * Alpine does not allow Blade to inject values into expressions.
 */
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';

/**
 * @returns {Record<string, any>}
 */
export default function accountSecurity() {
    return {
        /** True while the confirmation is shown. */
        confirming: false,

        /** True while the request is in flight; blocks double clicks. */
        busy: false,

        /** Endpoint that signs out the other devices (POST), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /**
         * Shows the confirmation.
         *
         * @returns {void}
         */
        ask() {
            this.confirming = true;
        },

        /**
         * Gives up.
         *
         * @returns {void}
         */
        cancel() {
            this.confirming = false;
        },

        /**
         * Signs every other device out, and says so.
         *
         * @returns {Promise<void>}
         */
        async signOutEverywhere() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.post(this.url);
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.message);
                }

                return;
            } finally {
                this.busy = false;
            }

            this.confirming = false;
            notify('success', t('account.notice.signed_out_everywhere'));
        },
    };
}
