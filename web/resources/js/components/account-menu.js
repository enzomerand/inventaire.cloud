/**
 * Alpine component of the signed-in user's menu in the header.
 *
 * Signing out is an API call (the session lives on the server); once it is done
 * the page leaves for the home page, which renders for a visitor. The endpoint
 * and the home page come from the markup (`data-logout-url`, `data-home-url` on
 * the root), because the CSP build of Alpine does not allow Blade to inject
 * values into expressions.
 */
import { HttpError, http } from '../lib/http.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';

/**
 * @returns {Record<string, any>}
 */
export default function accountMenu() {
    return {
        /** True while signing out; blocks double clicks. */
        busy: false,

        /** Endpoint that ends the session (POST), from `data-logout-url` on the component root. */
        get logoutUrl() {
            return this.$root.dataset.logoutUrl;
        },

        /** Page to go to afterwards, from `data-home-url` on the component root. */
        get homeUrl() {
            return this.$root.dataset.homeUrl;
        },

        /**
         * Ends the session, then leaves for the home page.
         *
         * A 401 means the session was already over (another tab signed out):
         * the outcome is the one that was asked for. Any other failure is shown
         * and the person stays signed in, so they are not misled.
         *
         * @returns {Promise<void>}
         */
        async signOut() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.post(this.logoutUrl, undefined, { silent: true });
            } catch (error) {
                if (!(error instanceof HttpError && error.status === 401)) {
                    this.busy = false;

                    if (error instanceof HttpError) {
                        notify('danger', error.message);
                    }

                    return;
                }
            }

            redirectTo(this.homeUrl);
        },
    };
}
