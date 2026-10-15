/**
 * Alpine component of the danger zone of the account page: deleting the account.
 *
 * Deleting cannot be undone, so it takes a code read from the mailbox of the account and a confirmation:
 *
 *   idle  --"Delete my account", a code is mailed-->  code  --right code, confirmed-->  gone (home page)
 *
 * The server refuses early when the account cannot be deleted (the last administrator, the only owner of a workspace
 * shared with others): the card then says why, and lists the workspaces, instead of asking for a code that would
 * lead nowhere. Every request is `silent` and its failures are shown in the card's own alert, like the sign-in page
 * does. The endpoints and the page to go to afterwards come from the markup (`data-code-url`, `data-url`,
 * `data-home-url` on the root), because the CSP build of Alpine does not allow Blade to inject values into
 * expressions.
 */
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { redirectTo, reloadPage } from '../lib/page.js';

/** Options shared by every request: the failures are handled here. */
const QUIET = { silent: true };

/** Past this wait (two minutes), the time to wait is told in minutes rather than seconds. */
const LONG_WAIT_SECONDS = 120;

/**
 * @returns {Record<string, any>}
 */
export default function accountDeletion() {
    return {
        /** "idle" until the person asks, "code" once a code was mailed and the confirmation is shown. */
        phase: 'idle',

        /** What was typed in the code box. */
        code: '',

        /** Whether the person ticked the box that says they understand. */
        understood: false,

        /** True while a request is in flight; blocks double submits. */
        busy: false,

        /** Message of the last failure, shown in the card's alert ("" when there is none). */
        error: '',

        /** The workspaces to hand over before the account can be deleted: `{ id, name }` each. */
        blocking: [],

        /** Endpoint that mails the code (POST), from `data-code-url` on the component root. */
        get codeUrl() {
            return this.$root.dataset.codeUrl;
        },

        /** Endpoint of the account (DELETE), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Page to go to once the account is gone, from `data-home-url` on the component root. */
        get homeUrl() {
            return this.$root.dataset.homeUrl;
        },

        /** Whether the delete button can be pressed: a whole code, and the box ticked. */
        get ready() {
            return this.understood && this.code.replace(/\s+/g, '').length === 6;
        },

        /**
         * Asks for a code (first phase), or for another one (second phase).
         *
         * @param {boolean} again True when the person asked for a new code: they are told it was sent.
         * @returns {Promise<void>}
         */
        async sendCode(again) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = '';
            this.blocking = [];

            try {
                await http.post(this.codeUrl, undefined, QUIET);

                this.code = '';
                this.phase = 'code';
                this.focus('code');
                notify('success', t(again ? 'account.notice.code_resent' : 'account.notice.code_sent'));
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Asks for the first code.
         *
         * @returns {Promise<void>}
         */
        async start() {
            await this.sendCode(false);
        },

        /**
         * Asks for another code.
         *
         * @returns {Promise<void>}
         */
        async resend() {
            await this.sendCode(true);
        },

        /**
         * Gives up and closes the confirmation.
         *
         * @returns {void}
         */
        cancel() {
            this.phase = 'idle';
            this.code = '';
            this.understood = false;
            this.error = '';
            this.blocking = [];
        },

        /**
         * Deletes the account with the typed code, then leaves for the home page, which renders for a visitor.
         *
         * Success keeps the card locked meanwhile, so that nothing can be submitted twice.
         *
         * @returns {Promise<void>}
         */
        async confirm() {
            if (this.busy || !this.ready) {
                return;
            }

            this.busy = true;
            this.error = '';
            this.blocking = [];

            try {
                await http.delete(this.url, { ...QUIET, data: { code: this.code.replace(/\s+/g, '') } });
            } catch (error) {
                this.busy = false;
                this.fail(error);

                return;
            }

            redirectTo(this.homeUrl);
        },

        /**
         * Shows a failed request in the card's alert.
         *
         * Validation messages (422) are the server's, translated by it; the refusals to delete (409) are told by
         * key, with the workspaces in the way; every other message comes from a translation key. A cancelled request
         * (not an HttpError) says nothing.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 409 && error.code === 'last_administrator') {
                this.phase = 'idle';
                this.error = t('account.error.last_administrator');
            } else if (error.status === 409 && error.code === 'sole_owner_of_shared_workspaces') {
                this.phase = 'idle';
                this.error = t('account.error.sole_owner');
                this.blocking = Array.isArray(error.body?.workspaces) ? error.body.workspaces : [];
            } else if (error.status === 419) {
                // Silent calls are not reloaded by the HTTP helper; the message says the page will be.
                this.error = error.message;
                scheduleNavigation(reloadPage);
            } else if (error.status === 422) {
                this.error = error.errors.code?.[0] ?? error.message;

                if (error.code === 'deletion_code_wrong') {
                    this.code = '';
                    this.focus('code');
                }
            } else if (error.status === 429) {
                this.error = this.throttledMessage(error.retryAfter);
            } else {
                this.error = error.message;
            }
        },

        /**
         * Tells how long to wait, in minutes when it is long.
         *
         * @param {number | null} seconds The server's Retry-After, if it gave one.
         * @returns {string}
         */
        throttledMessage(seconds) {
            if (!seconds) {
                return t('error.too_many_requests');
            }

            return seconds > LONG_WAIT_SECONDS
                ? t('login.error.throttled_minutes', { minutes: Math.ceil(seconds / 60) })
                : t('login.error.throttled', { seconds });
        },

        /**
         * Puts the cursor in a box once the phase has been rendered.
         *
         * @param {'code'} ref Name of the `x-ref` of the box.
         * @returns {void}
         */
        focus(ref) {
            this.$nextTick(() => this.$refs[ref]?.focus());
        },
    };
}
