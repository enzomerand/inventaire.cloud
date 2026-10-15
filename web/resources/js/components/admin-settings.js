/**
 * Alpine component of the administration's settings page: the choices that apply to the whole instance.
 *
 * Today there is one, whether new addresses can create an account. Flipping the switch saves it at once; the switch
 * then shows what the server answers, and goes back to what it was if the change could not be saved. The endpoint and
 * the current value come from the markup (`data-url` and `data-registration-open`, "1" or "0", on the root), because
 * the CSP build of Alpine does not allow Blade to inject values into expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * as a toast.
 */
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';

/**
 * @returns {Record<string, any>}
 */
export default function adminSettings() {
    return {
        /** Whether new addresses can create an account, as the server holds it. */
        registrationOpen: true,

        /** True while a change is being saved; blocks the switch. */
        busy: false,

        /** Endpoint of the settings (PUT), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /**
         * Reads the value the page was rendered with.
         *
         * @returns {void}
         */
        init() {
            this.registrationOpen = this.$root.dataset.registrationOpen === '1';
        },

        /**
         * Saves the position the switch was put in.
         *
         * @param {Event} event The change event of the checkbox.
         * @returns {Promise<void>}
         */
        async toggleRegistration(event) {
            const box = event.target;

            if (this.busy) {
                box.checked = this.registrationOpen;

                return;
            }

            this.busy = true;

            try {
                const answer = await http.put(this.url, { registration_open: box.checked === true });

                this.registrationOpen = answer.data.registration_open === true;
                notify(
                    'success',
                    t(this.registrationOpen ? 'admin.settings.notice.opened' : 'admin.settings.notice.closed'),
                );
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.message);
                }
            } finally {
                box.checked = this.registrationOpen;
                this.busy = false;
            }
        },
    };
}
