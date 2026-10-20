/**
 * Alpine component of the administration's settings page: the choices that apply to the whole instance, and where the
 * e-signature service (Documenso) stands.
 *
 * There are two choices: whether new addresses can create an account, and, while they cannot, whether an invitation to a
 * workspace still lets its address create one ("on invitation"). Flipping a switch saves it at once; the switch then shows
 * what the server answers, and goes back to what it was if the change could not be saved. The endpoint and the current
 * values come from the markup (`data-url`, `data-registration-open` and `data-invitation-sign-up`, "1" or "0", on the
 * root), because the CSP build of Alpine does not allow Blade to inject values into expressions.
 *
 * The state of Documenso is read-only (it is set in the environment of the instance): whether the instance can ask for
 * signatures and whether its webhook is open (`data-documenso-configured`, `data-documenso-secret`, "1" or "0"), and the
 * address to give Documenso (`data-webhook-url`), which the page offers to copy.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * as a toast.
 */
import { copyValue } from '../lib/feedback.js';
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

        /** Whether, while sign-ups are closed, an invitation to a workspace lets its address create an account, as the server holds it. */
        invitationSignUp: true,

        /** True while a change is being saved; blocks the switches. */
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
            this.invitationSignUp = data.invitationSignUp === '1';
            this.documensoConfigured = data.documensoConfigured === '1';
            this.webhookSecretConfigured = data.documensoSecret === '1';
            this.webhookUrl = data.webhookUrl ?? '';
        },

        /**
         * Stops the timer that makes the button forget it copied, when the page is taken apart (Alpine calls `destroy()` when the
         * element of the component is removed from the page, as it calls `init()` when it is set up): it would otherwise fire on a
         * component nobody reads any more.
         *
         * @returns {void}
         */
        destroy() {
            clearTimeout(this.copiedTimer);
            this.copiedTimer = null;
        },

        /**
         * Copies the address of the webhook. Where the browser does not allow it (a page that is not served over https, a
         * refused permission), the address is selected instead, so that the keyboard can copy it.
         *
         * @returns {Promise<void>}
         */
        copyWebhook() {
            return copyValue(this, this.webhookUrl, {
                select: () => this.select(),
                copied: t('tokens.copied'),
                hint: t('admin.settings.select_hint'),
            });
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
         * Saves the position the sign-ups switch was put in.
         *
         * @param {Event} event The change event of the checkbox.
         * @returns {Promise<void>}
         */
        async toggleRegistration(event) {
            await this.saveSwitch(event.target, 'registration_open', 'registrationOpen', {
                on: 'admin.settings.notice.opened',
                off: 'admin.settings.notice.closed',
            });
        },

        /**
         * Saves the position the invitations switch was put in.
         *
         * @param {Event} event The change event of the checkbox.
         * @returns {Promise<void>}
         */
        async toggleInvitationSignUp(event) {
            await this.saveSwitch(event.target, 'invitation_sign_up', 'invitationSignUp', {
                on: 'admin.settings.notice.invitations_on',
                off: 'admin.settings.notice.invitations_off',
            });
        },

        /**
         * Sends one setting and shows what the server holds afterwards: the switch is put back to the stored value, whether the change
         * was saved or not.
         *
         * @param {HTMLInputElement} box The checkbox that was flipped.
         * @param {string} setting Name of the setting in the API.
         * @param {'registrationOpen' | 'invitationSignUp'} property The state that mirrors it.
         * @param {{ on: string, off: string }} notices The messages that say it was turned on or off.
         * @returns {Promise<void>}
         */
        async saveSwitch(box, setting, property, notices) {
            if (this.busy) {
                box.checked = this[property];

                return;
            }

            this.busy = true;

            try {
                const answer = await http.put(this.url, { [setting]: box.checked === true });

                this[property] = answer.data[setting] === true;
                notify('success', t(this[property] ? notices.on : notices.off));
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.message);
                }
            } finally {
                box.checked = this[property];
                this.busy = false;
            }
        },
    };
}
