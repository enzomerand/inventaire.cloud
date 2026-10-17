/**
 * Alpine component of the API tokens card of the account page: list the tokens of the person, make one and revoke one.
 *
 * A token is shown in full once, in the answer that makes it: the card puts it in a read-only box, with a button that
 * copies it, until the person says they have it. It is then dropped from the page, and nobody can read it back. The
 * list itself only holds what the API gives for every token (name, permissions, dates), never the value.
 *
 * Making a token is a form that opens in the card (`creating`); revoking asks for a confirmation in the row itself.
 * The creation is `silent` for the HTTP helper: its refusals (validation, the limit of tokens, the pace of creations)
 * are shown in the form, next to the fields or in its alert. The endpoint comes from the markup (`data-url` on the
 * root), because the CSP build of Alpine does not allow Blade to inject values into expressions.
 */
import { momentText } from '../grid/rows.js';
import { firstMessages, throttledMessage } from '../lib/forms.js';
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';

/** The lifetime the form offers first, in days: the value of the option of the select. */
const DEFAULT_EXPIRY = '90';

/** How long the button says "Copied", in milliseconds. */
const COPIED_MS = 2000;

/** The badge colors of the permissions (Tabler's light variants; a badge always says the permission in words as well). */
const BADGE_CLASSES = {
    read: 'bg-azure-lt',
    write: 'bg-yellow-lt',
    manage: 'bg-red-lt',
};

/**
 * @typedef {object} ApiToken A token, as `ApiTokenResource` describes it.
 * @property {number | string} id
 * @property {string} name
 * @property {string[]} abilities
 * @property {string | null} created_at ISO 8601.
 * @property {string | null} last_used_at ISO 8601; null for a token that was never used.
 * @property {string | null} expires_at ISO 8601; null for a token that never expires.
 */

/**
 * @returns {Record<string, any>}
 */
export default function accountTokens() {
    return {
        /** The tokens that work, the newest first. */
        tokens: [],

        /** True while the list is being read (and until it was, so that the empty message does not flash). */
        loading: true,

        /** True when the list could not be read: the card then offers to try again. */
        loadFailed: false,

        /** True while the creation is in flight; blocks double submits. */
        busy: false,

        /** Whether the creation form is open. */
        creating: false,

        /** What was typed and ticked in the creation form: `name`, `abilities` (names) and `expires` (days, "" for never). */
        form: { name: '', abilities: ['read'], expires: DEFAULT_EXPIRY },

        /** The messages of a refused creation, under their fields: `name`, `abilities`, `expires` ("" when there is none). */
        errors: {},

        /** Why the creation was refused when it is not about a field ("" when it was not). */
        formError: '',

        /** The value of the token that was just made, shown once; "" when no token is being shown. */
        revealed: '',

        /** Whether the value was copied (the button says so for a moment). */
        copied: false,

        /** Id of the token waiting for the confirmation of its revocation, or null. */
        confirmingId: null,

        /** Id of the token being revoked (request in flight), or null. */
        revokingId: null,

        /** What assistive technology is told, in the card's polite live region ("" when nothing). */
        announcement: '',

        /** Root element of the component, kept from `init()` because `$root` is gone once an element has left the page. */
        rootElement: null,

        /** Timer that takes "Copied" off the button. */
        copiedTimer: null,

        /** Endpoint of the tokens (GET, POST, and DELETE on `/{id}`), from `data-url` on the component root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /** Whether the creation form can be submitted: a name, at least one permission, and nothing in flight. */
        get canCreate() {
            return !this.busy && this.form.name.trim() !== '' && this.form.abilities.length > 0;
        },

        /** Whether the value of a token is being shown. */
        get hasRevealed() {
            return this.revealed !== '';
        },

        /** Whether the button that opens the creation form is shown: not while the form is open or a token is shown. */
        get canOpen() {
            return !this.creating && !this.hasRevealed;
        },

        /** Whether the list is known to be empty (the empty message is not shown while it is read, or when it failed). */
        get empty() {
            return !this.loading && !this.loadFailed && this.tokens.length === 0;
        },

        /** What the button that copies the value says. */
        get copyLabel() {
            return t(this.copied ? 'tokens.copied' : 'tokens.copy');
        },

        /**
         * Reads the tokens when the page opens, and again when another card revokes them all.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.rootElement = this.$root;
            await this.load();
        },

        /**
         * Reads the tokens from the API.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;
            this.loadFailed = false;

            try {
                const answer = await http.get(this.url);

                this.tokens = answer.data;
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (!(error instanceof HttpError)) {
                    return;
                }

                this.loadFailed = true;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Opens the creation form, with the lifetime and permission it offers first.
         *
         * @returns {void}
         */
        open() {
            this.creating = true;
            this.focus('name');
        },

        /**
         * Gives up the creation: closes the form and forgets what was typed.
         *
         * @returns {void}
         */
        cancel() {
            this.creating = false;
            this.reset();
            this.focus('open');
        },

        /**
         * Makes the token, shows its value and puts the token at the head of the list.
         *
         * @returns {Promise<void>}
         */
        async create() {
            if (!this.canCreate) {
                return;
            }

            this.busy = true;
            this.errors = {};
            this.formError = '';

            try {
                const answer = await http.post(
                    this.url,
                    {
                        name: this.form.name.trim(),
                        abilities: this.form.abilities,
                        expires_in_days: this.form.expires === '' ? null : Number(this.form.expires),
                    },
                    { silent: true },
                );
                const { plain_text_token: value, ...token } = answer.data;

                this.tokens = [token, ...this.tokens];
                this.revealed = value;
                this.copied = false;
                this.creating = false;
                this.reset();
                this.focus('token', true);
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Hides the value of the token for good: the person says they have it.
         *
         * @returns {void}
         */
        dismiss() {
            this.revealed = '';
            this.copied = false;
            clearTimeout(this.copiedTimer);
            this.focus('open');
        },

        /**
         * Copies the value of the token. Where the browser does not allow it (a page that is not served over https, a
         * refused permission), the value is selected instead, so that the keyboard can copy it.
         *
         * @returns {Promise<void>}
         */
        async copy() {
            let done;

            try {
                await navigator.clipboard.writeText(this.revealed);
                done = true;
            } catch {
                done = this.copySelected();
            }

            if (!done) {
                this.announce(t('tokens.select_hint'));

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
         * Selects the value of the token in its box, and copies the selection when the browser still lets a page do it.
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
         * Selects the whole value in its box (a click or the focus on it does it, whatever the browser).
         *
         * @returns {void}
         */
        select() {
            this.target('token')?.select();
        },

        /**
         * Asks for the confirmation of the revocation of a token.
         *
         * @param {ApiToken} token
         * @returns {void}
         */
        askRevoke(token) {
            this.confirmingId = token.id;
            this.focusRow('confirm', token.id);
        },

        /**
         * Gives up the revocation.
         *
         * @returns {void}
         */
        cancelRevoke() {
            const id = this.confirmingId;

            this.confirmingId = null;
            this.focusRow('revoke', id);
        },

        /**
         * Revokes a token: it stops working at once, and leaves the list.
         *
         * @param {ApiToken} token
         * @returns {Promise<void>}
         */
        async revoke(token) {
            if (this.revokingId !== null) {
                return;
            }

            this.revokingId = token.id;

            try {
                await http.delete(`${this.url}/${encodeURIComponent(String(token.id))}`);

                this.remove(token.id);
                this.announce(t('tokens.notice.revoked'));
                this.focus('heading');
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (!(error instanceof HttpError)) {
                    return;
                }

                // Already gone (revoked elsewhere, or expired): the list was out of date, and now it is not.
                if (error.status === 404) {
                    this.remove(token.id);
                    this.focus('heading');
                } else if (!error.reported) {
                    notify('danger', error.message);
                }
            } finally {
                this.revokingId = null;
            }
        },

        /**
         * Takes a token off the list.
         *
         * @param {number | string} id
         * @returns {void}
         */
        remove(id) {
            this.tokens = this.tokens.filter((token) => token.id !== id);

            if (this.confirmingId === id) {
                this.confirmingId = null;
            }
        },

        /**
         * Whether the revocation of a token waits for its confirmation.
         *
         * @param {ApiToken} token
         * @returns {boolean}
         */
        isConfirming(token) {
            return this.confirmingId === token.id;
        },

        /**
         * Whether the revocation of a token is in flight.
         *
         * @param {ApiToken} token
         * @returns {boolean}
         */
        isRevoking(token) {
            return this.revokingId === token.id;
        },

        /**
         * The name of a permission, in the language of the page.
         *
         * @param {string} ability
         * @returns {string}
         */
        abilityLabel(ability) {
            return t(`tokens.ability.${ability}`);
        },

        /**
         * The classes of the badge of a permission.
         *
         * @param {string} ability
         * @returns {string}
         */
        abilityClass(ability) {
            return BADGE_CLASSES[ability];
        },

        /**
         * When a token was made, as a sentence.
         *
         * @param {ApiToken} token
         * @returns {string}
         */
        createdText(token) {
            return t('tokens.created', { date: momentText(token.created_at) });
        },

        /**
         * When a token was last used, as a sentence.
         *
         * @param {ApiToken} token
         * @returns {string}
         */
        lastUsedText(token) {
            return token.last_used_at
                ? t('tokens.last_used', { date: momentText(token.last_used_at) })
                : t('tokens.never_used');
        },

        /**
         * When a token expires, as a sentence.
         *
         * @param {ApiToken} token
         * @returns {string}
         */
        expiresText(token) {
            return token.expires_at
                ? t('tokens.expires', { date: momentText(token.expires_at) })
                : t('tokens.never_expires');
        },

        /**
         * The name of the button that revokes a token (it says which one).
         *
         * @param {ApiToken} token
         * @returns {string}
         */
        revokeLabel(token) {
            return t('tokens.revoke_named', { name: token.name });
        },

        /**
         * The question asked before a token is revoked.
         *
         * @param {ApiToken} token
         * @returns {string}
         */
        confirmText(token) {
            return t('tokens.confirm_revoke', { name: token.name });
        },

        /**
         * Whether a field of the form was refused (for `aria-invalid`).
         *
         * @param {'name' | 'abilities' | 'expires'} field
         * @returns {boolean}
         */
        isInvalid(field) {
            return Boolean(this.errors[field]);
        },

        /**
         * Tells why the creation was refused: under the field a validation message is about, in the alert of the form for
         * the rest.
         *
         * Validation messages (422) are the server's, translated by it; the limit of tokens (409) and the pace of creations
         * (429) are told by key. A cancelled request (not an HttpError) says nothing.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 422) {
                const messages = firstMessages(error.errors);

                this.errors = {
                    name: messages.name ?? '',
                    abilities:
                        messages.abilities ??
                        Object.entries(messages).find(([field]) => field.startsWith('abilities.'))?.[1] ??
                        '',
                    expires: messages.expires_in_days ?? '',
                };

                if (!Object.values(this.errors).some(Boolean)) {
                    this.formError = error.message;
                } else if (this.errors.name) {
                    this.focus('name');
                }
            } else if (error.status === 409 && error.code === 'token_limit_reached') {
                this.formError = t('tokens.error.limit', { count: error.body?.limit ?? '' });
            } else if (error.status === 429) {
                this.formError = throttledMessage(error.retryAfter);
            } else if (error.status === 419) {
                // Silent calls are not reloaded by the HTTP helper; the message says the page will be.
                this.formError = error.message;
                scheduleNavigation(reloadPage);
            } else {
                this.formError = error.message;
            }
        },

        /**
         * Empties the creation form, and its messages.
         *
         * @returns {void}
         */
        reset() {
            this.form = { name: '', abilities: ['read'], expires: DEFAULT_EXPIRY };
            this.errors = {};
            this.formError = '';
        },

        /**
         * Says something to assistive technology, in the card's polite live region. The region is emptied first, so that
         * a message said twice in a row is read twice.
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
         * An element of the card the cursor can be put on, by the name its markup gives it (`data-focus`). It is looked up
         * from the root rather than with `$refs`, which is empty once the element that started the call (the button of a
         * row that was just revoked) has left the page.
         *
         * @param {'name' | 'token' | 'open' | 'heading'} name
         * @returns {HTMLElement | null}
         */
        target(name) {
            return this.rootElement.querySelector(`[data-focus="${name}"]`);
        },

        /**
         * Puts the cursor on an element of the card once what was changed has been rendered.
         *
         * @param {'name' | 'token' | 'open' | 'heading'} ref Name of the element (see {@link target}).
         * @param {boolean} [select] Whether to select the text of a box as well.
         * @returns {void}
         */
        focus(ref, select = false) {
            this.$nextTick(() => {
                const element = this.target(ref);

                element?.focus();

                if (select) {
                    element?.select?.();
                }
            });
        },

        /**
         * Puts the cursor on a button of the row of a token once what was changed has been rendered.
         *
         * @param {'confirm' | 'revoke'} action What the button does (`data-token-action`).
         * @param {number | string | null} id The token (`data-token-id`).
         * @returns {void}
         */
        focusRow(action, id) {
            this.$nextTick(() => {
                const buttons = this.rootElement.querySelectorAll(`[data-token-action="${action}"]`);

                [...buttons].find((button) => button.dataset.tokenId === String(id))?.focus();
            });
        },
    };
}
