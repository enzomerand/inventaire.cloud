/**
 * Alpine component of the sign-in page.
 *
 * There are no passwords. The visitor gives an email address, a 6-digit code is
 * sent to it, and typing the code back signs them in. An address without an
 * account asks for a name once the code is typed (the server keeps the code
 * valid meanwhile), so signing in and signing up are the same flow:
 *
 *   email  --code sent-->  code  --right code, unknown address-->  name
 *
 * The endpoints and the page to go to next come from the markup
 * (`data-code-url`, `data-verify-url`, `data-app-url` on the root), because the
 * CSP build of Alpine does not allow Blade to inject values into expressions.
 * Every request is `silent`: this page is open to visitors, so the HTTP helper
 * must not answer a failure by redirecting to the very page we are on. Each
 * failure is shown in the page's own alert instead. The one exception is a
 * stale page (419, the CSRF token could not be renewed): the message says the
 * page is about to reload, so it does.
 */
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { redirectTo, reloadPage } from '../lib/page.js';

/** Server codes meaning the code can no longer be used: a new one must be requested. */
const DEAD_CODES = ['login_code_expired', 'login_code_exhausted'];

/** Past this wait (two minutes), the time to wait is told in minutes rather than seconds. */
const LONG_WAIT_SECONDS = 120;

/** Options shared by every request: the failures are handled here. */
const QUIET = { silent: true };

/**
 * @returns {Record<string, any>}
 */
export default function login() {
    return {
        /** "email" while the address is asked, "code" once a code was sent, "name" when the account must be created. */
        phase: 'email',

        /** What was typed in the address box. */
        email: '',

        /** What was typed in the code box. */
        code: '',

        /** What was typed in the name box. */
        name: '',

        /** True while a request is in flight; blocks double submits. */
        busy: false,

        /** Message of the last failure, shown in the page's alert ("" when there is none). */
        error: '',

        /** Endpoint that emails a code, from `data-code-url` on the component root. */
        get codeUrl() {
            return this.$root.dataset.codeUrl;
        },

        /** Endpoint that checks a code, from `data-verify-url` on the component root. */
        get verifyUrl() {
            return this.$root.dataset.verifyUrl;
        },

        /** Page to go to once signed in, from `data-app-url` on the component root. */
        get appUrl() {
            return this.$root.dataset.appUrl;
        },

        /**
         * Asks for a code for the typed address (first phase).
         *
         * @returns {Promise<void>}
         */
        async requestCode() {
            await this.sendCode(false);
        },

        /**
         * Asks for another code for the same address (second phase).
         *
         * @returns {Promise<void>}
         */
        async resend() {
            await this.sendCode(true);
        },

        /**
         * Emails a code and moves to the code phase.
         *
         * @param {boolean} again True when the person asked for a new code: they are told it was sent, since the page does not change.
         * @returns {Promise<void>}
         */
        async sendCode(again) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = '';

            try {
                const answer = await http.post(this.codeUrl, { email: this.email.trim() }, QUIET);

                // The server returns the address in its canonical form, which is the one the code was sent to.
                this.email = answer?.data?.email ?? this.email.trim();
                this.code = '';
                this.phase = 'code';
                this.focus('code');

                if (again) {
                    notify('success', t('login.notice.code_resent'));
                }
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Sends the typed code, with the name when the account is being created.
         *
         * Success leaves the page for the signed-in space and keeps the form
         * locked meanwhile, so that nothing can be submitted twice.
         *
         * @returns {Promise<void>}
         */
        async verify() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = '';

            const payload = { email: this.email, code: this.code.replace(/\s+/g, '') };

            if (this.phase === 'name') {
                payload.name = this.name.trim();
            }

            try {
                await http.post(this.verifyUrl, payload, QUIET);
            } catch (error) {
                this.busy = false;
                this.rejected(error);

                return;
            }

            redirectTo(this.appUrl);
        },

        /**
         * Goes back to the address box, to use another one.
         *
         * @returns {void}
         */
        back() {
            this.phase = 'email';
            this.error = '';
            this.code = '';
            this.name = '';
            this.focus('email');
        },

        /**
         * Reacts to a code that was not accepted.
         *
         * @param {unknown} error
         * @returns {void}
         */
        rejected(error) {
            if (error instanceof HttpError && error.status === 422) {
                // Right code, unknown address: the account needs a name before it can be created.
                if (error.code === 'name_required' && this.phase !== 'name') {
                    this.phase = 'name';
                    this.focus('name');

                    return;
                }

                // A code that can no longer be used is replaced from the code phase, where "Send a new code" is.
                if (DEAD_CODES.includes(error.code)) {
                    this.phase = 'code';
                    this.focus('code');
                }

                if (error.code === 'login_code_wrong') {
                    this.code = '';
                    this.focus('code');
                }
            }

            this.fail(error);
        },

        /**
         * Shows a failed request in the page's alert.
         *
         * Validation messages (422) are the server's, translated by it; every
         * other message comes from a translation key. A cancelled request (not
         * an HttpError) says nothing.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 403 && error.code === 'not_stateful') {
                this.error = t('login.error.not_stateful');
            } else if (error.status === 419) {
                // Silent calls are not reloaded by the HTTP helper; the message says the page will be.
                this.error = error.message;
                scheduleNavigation(reloadPage);
            } else if (error.status === 429) {
                this.error = this.throttledMessage(error.retryAfter);
            } else if (error.status === 422) {
                this.error =
                    error.errors.email?.[0] ?? error.errors.code?.[0] ?? error.errors.name?.[0] ?? error.message;
            } else {
                this.error = error.message;
            }
        },

        /**
         * Tells how long to wait, in minutes when it is long (an hour of waiting is not "3540 seconds").
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
         * @param {'email' | 'code' | 'name'} ref Name of the `x-ref` of the box.
         * @returns {void}
         */
        focus(ref) {
            this.$nextTick(() => this.$refs[ref]?.focus());
        },
    };
}
