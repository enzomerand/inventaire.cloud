/**
 * Alpine component of the administration's settings page: the choices that apply to the whole instance, and where the
 * e-signature service (Documenso) stands.
 *
 * There is one choice, whether new addresses can create an account. Flipping the switch saves it at once; the switch
 * then shows what the server answers, and goes back to what it was if the change could not be saved. The endpoint and
 * the current value come from the markup (`data-url` and `data-registration-open`, "1" or "0", on the root), because
 * the CSP build of Alpine does not allow Blade to inject values into expressions.
 *
 * The state of Documenso is read-only (it is set in the environment of the instance): whether the instance can ask for
 * signatures and whether its webhook is open (`data-documenso-configured`, `data-documenso-secret`, "1" or "0"), and the
 * address to give Documenso (`data-webhook-url`), which the page offers to copy.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * as a toast.
 */
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';

/** How long the button that copies the address says it did, in milliseconds. */
const COPIED_MS = 2000;

/**
 * @returns {Record<string, any>}
 */
export default function adminSettings() {
    return {
        /** Whether new addresses can create an account, as the server holds it. */
        registrationOpen: true,

        /** True while a change is being saved; blocks the switch. */
        busy: false,

        /** Whether the instance has the address and the API token of Documenso (so signatures can be asked for). */
        documensoConfigured: false,

        /** Whether the instance has a webhook secret (so the webhook answers). */
        webhookSecretConfigured: false,

        /** The address Documenso is told to call, from `data-webhook-url`. */
        webhookUrl: '',

        /** Whether the address was copied (the button says so for a moment). */
        copied: false,

        /** What the live region says; changing it is how a screen reader is told. */
        announcement: '',

        /** The timer that makes the button forget it copied. */
        copiedTimer: null,

        /** Endpoint of the settings (PUT), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** What the button that copies the address says. */
        get copyLabel() {
            return t(this.copied ? 'tokens.copied' : 'tokens.copy');
        },

        /**
         * Reads the values the page was rendered with.
         *
         * @returns {void}
         */
        init() {
            const data = this.$root.dataset;

            this.registrationOpen = data.registrationOpen === '1';
            this.documensoConfigured = data.documensoConfigured === '1';
            this.webhookSecretConfigured = data.documensoSecret === '1';
            this.webhookUrl = data.webhookUrl ?? '';
        },

        /**
         * Copies the address of the webhook. Where the browser does not allow it (a page that is not served over https, a
         * refused permission), the address is selected instead, so that the keyboard can copy it.
         *
         * @returns {Promise<void>}
         */
        async copyWebhook() {
            let done;

            try {
                await navigator.clipboard.writeText(this.webhookUrl);
                done = true;
            } catch {
                done = this.copySelected();
            }

            if (!done) {
                this.announce(t('admin.settings.select_hint'));

                return;
            }

            this.copied = true;
            this.announce(t('tokens.copied'));
            clearTimeout(this.copiedTimer);
            this.copiedTimer = setTimeout(() => {
                this.copied = false;
            }, COPIED_MS);
        },

        /**
         * Selects the address in its box, and copies the selection when the browser still lets a page do it.
         *
         * @returns {boolean} Whether the selection was copied.
         */
        copySelected() {
            this.select();

            try {
                return document.execCommand('copy') === true;
            } catch {
                return false;
            }
        },

        /**
         * Selects the whole address in its box (a click or the focus on it does it, whatever the browser).
         *
         * @returns {void}
         */
        select() {
            this.$refs.webhook?.select();
        },

        /**
         * Says something in the live region. It is emptied first, so that the same sentence said twice in a row is announced twice.
         *
         * @param {string} message
         * @returns {void}
         */
        announce(message) {
            this.announcement = '';
            this.$nextTick(() => {
                this.announcement = message;
            });
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
