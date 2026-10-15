/**
 * Alpine component of the profile card of the account page: the name and the language of the account.
 *
 * Saving goes through the API; the answer carries the `locale` cookie when the language changed, so the page is
 * reloaded in the new language once the confirmation had time to be read (the header shows the name too). The
 * endpoint and the current values come from the markup (`data-url`, `data-name`, `data-locale` on the root),
 * because the CSP build of Alpine does not allow Blade to inject values into expressions.
 */
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';

/**
 * @returns {Record<string, any>}
 */
export default function accountProfile() {
    return {
        /** What is in the name box. */
        name: '',

        /** The language chosen in the select. */
        locale: '',

        /** True while the profile is being saved; blocks double submits. */
        busy: false,

        /** Endpoint of the account (PATCH), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Whether the form can be submitted: it has a name, and nothing else is in flight. */
        get canSave() {
            return !this.busy && this.name.trim() !== '';
        },

        /**
         * Fills the form with the values of the account, from the markup.
         *
         * @returns {void}
         */
        init() {
            this.name = this.$root.dataset.name;
            this.locale = this.$root.dataset.locale;
        },

        /**
         * Saves the name and the language, then reloads the page so that it shows both.
         *
         * Failures the HTTP helper already reported are not repeated; a refused value (422) is shown with the
         * server's translated message.
         *
         * @returns {Promise<void>}
         */
        async save() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.patch(this.url, { name: this.name.trim(), locale: this.locale });
            } catch (error) {
                this.busy = false;

                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.errors.name?.[0] ?? error.errors.locale?.[0] ?? error.message);
                }

                return;
            }

            // Stays locked: the page is about to reload.
            notify('success', t('account.notice.saved'));
            scheduleNavigation(reloadPage);
        },
    };
}
