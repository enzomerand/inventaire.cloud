/**
 * Alpine component of the invitations part of the members page, for the owner of a workspace: invite an address as an editor or a
 * viewer, and list the invitations that wait for an answer, to send again or to withdraw.
 *
 * An invitation is bound to an email address, not to a link: the person signs in with it and answers from their own invitations
 * page. The form is `silent` for the HTTP helper: its refusals (the address is not valid, belongs to a member already, is invited
 * already, the workspace is full, the pace of invitations) are shown in the form, next to the field or in its alert. Sending again
 * is `silent` too, to tell how long to wait when it comes too soon. Withdrawing asks for a confirmation in the row itself. The
 * endpoint comes from the markup (`data-url` on the root), because the CSP build of Alpine does not allow Blade to inject values
 * into expressions.
 */
import { momentText } from '../grid/rows.js';
import { firstMessages, throttledMessage } from '../lib/forms.js';
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';
import { refusalText } from '../members/refusals.js';
import { roleClass, roleLabel } from '../members/roles.js';

/**
 * @typedef {object} SentInvitation An invitation the workspace sent, as `SentInvitationResource` describes it.
 * @property {string} id
 * @property {string} email
 * @property {'editor' | 'viewer'} role
 * @property {{ id: string, name: string } | null} invited_by Null once the account that sent it was deleted.
 * @property {string} expires_at ISO 8601.
 * @property {string | null} created_at ISO 8601.
 */

/**
 * @returns {Record<string, any>}
 */
export default function workspaceInvitations() {
    return {
        /** The invitations that wait for an answer, the newest first. */
        invitations: [],

        /** True while the list is being read (and until it was, so that the empty message does not flash). */
        loading: true,

        /** True when the list could not be read: the card then offers to try again. */
        loadFailed: false,

        /** True while an invitation is being sent; blocks double submits. */
        busy: false,

        /** What was typed and chosen in the form: `email` and `role` (`editor` or `viewer`). */
        form: { email: '', role: 'editor' },

        /** The messages of a refused invitation, under their fields: `email` and `role` ("" when there is none). */
        errors: {},

        /** Why the invitation was refused when it is not about a field ("" when it was not). */
        formError: '',

        /** Id of the invitation waiting for the confirmation of its withdrawal, or null. */
        confirmingId: null,

        /** Id of the invitation a request is in flight for (sending it again, withdrawing it), or null. */
        workingId: null,

        /** Root element of the component, kept from `init()` because `$root` is gone once an element has left the page. */
        rootElement: null,

        /** Endpoint of the invitations (GET, POST, and DELETE on `/{id}`, POST on `/{id}/resend`), from `data-url` on the root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /**
         * Whether a request is in flight for any invitation. Only one at a time is made (`resend()` and `withdraw()` ignore a click
         * while another is in flight), so the buttons of every row are disabled meanwhile rather than left to do nothing.
         */
        get anyWorking() {
            return this.workingId !== null;
        },

        /** Whether the form can be submitted: an address, and nothing in flight. */
        get canInvite() {
            return !this.busy && this.form.email.trim() !== '';
        },

        /** Whether the list is known to be empty (the empty message is not shown while it is read, or when it failed). */
        get empty() {
            return !this.loading && !this.loadFailed && this.invitations.length === 0;
        },

        /**
         * Reads the invitations when the page opens.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.rootElement = this.$root;
            await this.load();
        },

        /**
         * Reads the invitations from the API.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;
            this.loadFailed = false;

            try {
                const answer = await http.get(this.url);

                this.invitations = answer.data;
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
         * Invites the address of the form, and puts the invitation at the head of the list. The mail goes out after the answer.
         *
         * @returns {Promise<void>}
         */
        async invite() {
            if (!this.canInvite) {
                return;
            }

            this.busy = true;
            this.errors = {};
            this.formError = '';

            try {
                const answer = await http.post(
                    this.url,
                    { email: this.form.email.trim(), role: this.form.role },
                    { silent: true },
                );

                this.invitations = [answer.data, ...this.invitations];
                notify('success', t('members.notice.invited', { email: answer.data.email }));
                this.form.email = '';
                this.focus('email');
            } catch (error) {
                this.failForm(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Sends an invitation again: the mail goes out once more and the invitation is good for another term.
         *
         * @param {SentInvitation} invitation
         * @returns {Promise<void>}
         */
        async resend(invitation) {
            if (this.workingId !== null) {
                return;
            }

            this.workingId = invitation.id;

            try {
                const answer = await http.post(`${this.invitationUrl(invitation)}/resend`, undefined, { silent: true });

                this.replace(answer.data);
                notify('success', t('members.notice.resent', { email: invitation.email }));
            } catch (error) {
                this.failSilent(error, invitation);
            } finally {
                this.workingId = null;
            }
        },

        /**
         * Asks for the confirmation of the withdrawal of an invitation.
         *
         * @param {SentInvitation} invitation
         * @returns {void}
         */
        askWithdraw(invitation) {
            this.confirmingId = invitation.id;
            this.focusRow('confirm', invitation.id);
        },

        /**
         * Gives up the withdrawal, and puts the cursor back on the button that asked for it.
         *
         * @returns {void}
         */
        cancelWithdraw() {
            const id = this.confirmingId;

            this.confirmingId = null;
            this.focusRow('withdraw', id);
        },

        /**
         * Withdraws an invitation: it can no longer be accepted, and leaves the list.
         *
         * @param {SentInvitation} invitation
         * @returns {Promise<void>}
         */
        async withdraw(invitation) {
            if (this.workingId !== null) {
                return;
            }

            this.workingId = invitation.id;

            try {
                await http.delete(this.invitationUrl(invitation), { silent: true });

                this.drop(invitation.id);
                notify('success', t('members.notice.withdrawn', { email: invitation.email }));
                this.focus('heading');
            } catch (error) {
                // Already gone (answered, expired, or withdrawn elsewhere): the list was out of date, and now it is not.
                if (error instanceof HttpError && error.status === 404) {
                    this.drop(invitation.id);
                    this.focus('heading');
                } else {
                    this.failSilent(error, invitation);
                }
            } finally {
                this.workingId = null;
            }
        },

        /**
         * Whether the withdrawal of an invitation waits for its confirmation.
         *
         * @param {SentInvitation} invitation
         * @returns {boolean}
         */
        isConfirming(invitation) {
            return this.confirmingId === invitation.id;
        },

        /**
         * Whether a request is in flight for an invitation.
         *
         * @param {SentInvitation} invitation
         * @returns {boolean}
         */
        isWorking(invitation) {
            return this.workingId === invitation.id;
        },

        /** The name of a role and the classes of its badge (see `members/roles.js`), for the template to call. */
        roleLabel,
        roleClass,

        /**
         * Who sent an invitation and until when it is good, as a sentence.
         *
         * @param {SentInvitation} invitation
         * @returns {string}
         */
        metaText(invitation) {
            const expires = t('invitations.expires', { date: momentText(invitation.expires_at) });

            return invitation.invited_by
                ? `${t('invitations.invited_by', { name: invitation.invited_by.name })} · ${expires}`
                : expires;
        },

        /**
         * The question asked before an invitation is withdrawn.
         *
         * @param {SentInvitation} invitation
         * @returns {string}
         */
        confirmText(invitation) {
            return t('members.confirm_withdraw', { email: invitation.email });
        },

        /**
         * The `id` of the element that holds the question of the confirmation of a withdrawal: the button that confirms points at it
         * (`aria-describedby`).
         *
         * @param {SentInvitation} invitation
         * @returns {string}
         */
        confirmTextId(invitation) {
            return `invitation-confirm-text-${invitation.id}`;
        },

        /**
         * The accessible name of the button that sends an invitation again (it says which one).
         *
         * @param {SentInvitation} invitation
         * @returns {string}
         */
        resendLabel(invitation) {
            return t('members.resend_named', { email: invitation.email });
        },

        /**
         * The accessible name of the button that withdraws an invitation (it says which one).
         *
         * @param {SentInvitation} invitation
         * @returns {string}
         */
        withdrawLabel(invitation) {
            return t('members.withdraw_named', { email: invitation.email });
        },

        /**
         * Whether a field of the form was refused (for `aria-invalid`).
         *
         * @param {'email' | 'role'} field
         * @returns {boolean}
         */
        isInvalid(field) {
            return Boolean(this.errors[field]);
        },

        /**
         * Tells why the invitation was refused: under the field a validation message is about, in the alert of the form for the
         * rest.
         *
         * Validation messages (422) are the server's, translated by it; the refusals of the rules (409, 403) and the pace of
         * invitations (429) are told by key. A cancelled request (not an HttpError) says nothing.
         *
         * @param {unknown} error
         * @returns {void}
         */
        failForm(error) {
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 422) {
                const messages = firstMessages(error.errors);

                this.errors = { email: messages.email ?? '', role: messages.role ?? '' };

                if (!Object.values(this.errors).some(Boolean)) {
                    this.formError = error.message;
                } else if (this.errors.email) {
                    this.focus('email');
                }

                return;
            }

            this.formError = this.silentText(error);
            this.leaveStalePage(error);
        },

        /**
         * Tells why a request that did not use the central feedback of the HTTP helper failed, in a toast.
         *
         * @param {unknown} error
         * @param {SentInvitation} invitation The invitation the request was about.
         * @returns {void}
         */
        failSilent(error, invitation) {
            if (!(error instanceof HttpError)) {
                return;
            }

            // The invitation was answered or withdrawn in the meantime: the list was out of date.
            if (error.status === 404) {
                this.drop(invitation.id);
            }

            notify('danger', this.silentText(error));
            this.leaveStalePage(error);
        },

        /**
         * The words for a failure of a `silent` request: the refusal of a rule, the pace of invitations, or what the HTTP helper says.
         *
         * @param {HttpError} error
         * @returns {string}
         */
        silentText(error) {
            if (error.status === 429 && error.code !== 'resend_too_soon') {
                return throttledMessage(error.retryAfter);
            }

            return refusalText(error) ?? error.message;
        },

        /**
         * Reloads the page when it is stale: the session ended (401), the page's token expired (419) or the person is not the owner
         * any more (`not_owner`), which this page, rendered for the owner, offers things to. A silent call does not navigate by
         * itself; a reload brings the person to the login page, back with a fresh token, or to what a member sees.
         *
         * @param {HttpError} error
         * @returns {void}
         */
        leaveStalePage(error) {
            if (error.status === 401 || error.status === 419 || error.code === 'not_owner') {
                scheduleNavigation(reloadPage);
            }
        },

        /**
         * The address of one invitation in the API.
         *
         * @param {SentInvitation} invitation
         * @returns {string}
         */
        invitationUrl(invitation) {
            return `${this.url}/${encodeURIComponent(invitation.id)}`;
        },

        /**
         * Puts an invitation the API sent back in the place of the one the list has.
         *
         * @param {SentInvitation} invitation
         * @returns {void}
         */
        replace(invitation) {
            this.invitations = this.invitations.map((current) => (current.id === invitation.id ? invitation : current));
        },

        /**
         * Takes an invitation off the list.
         *
         * @param {string} id
         * @returns {void}
         */
        drop(id) {
            this.invitations = this.invitations.filter((invitation) => invitation.id !== id);

            if (this.confirmingId === id) {
                this.confirmingId = null;
            }
        },

        /**
         * Puts the cursor on an element, by the name its markup gives it (`data-focus`), once what was changed has been rendered.
         * It is looked up from the root rather than with `$refs`, which is empty once the element that started the call has left
         * the page.
         *
         * @param {'email' | 'heading'} name
         * @returns {void}
         */
        focus(name) {
            this.$nextTick(() => this.rootElement.querySelector(`[data-focus="${name}"]`)?.focus());
        },

        /**
         * Puts the cursor on a button of the row of an invitation once what was changed has been rendered.
         *
         * @param {'confirm' | 'withdraw'} action What the button does (`data-invitation-action`).
         * @param {string | null} id The invitation (`data-invitation-id`).
         * @returns {void}
         */
        focusRow(action, id) {
            this.$nextTick(() => {
                const buttons = this.rootElement.querySelectorAll(`[data-invitation-action="${action}"]`);

                [...buttons].find((button) => button.dataset.invitationId === String(id))?.focus();
            });
        },
    };
}
