/**
 * Alpine component of the page of the invitations addressed to the signed-in person: list them, accept one (which takes the person
 * to the workspace) and decline one.
 *
 * An invitation is bound to the email address the person signed in with, so the API only gives them theirs. Accepting makes the person
 * a member with the role the invitation names; declining asks for a confirmation in the card itself. The header of the app shows how
 * many invitations wait: this page tells it (the `invitations-changed` event of the window) whenever the number changes. The
 * endpoints come from the markup (`data-url`, `data-workspace-url` on the root), because the CSP build of Alpine does not allow Blade
 * to inject values into expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session, an invitation that is gone...) are not repeated; a refusal
 * the API explains with a code is told in words of our own.
 */
import { momentText } from '../grid/rows.js';
import { fillUrl } from '../lib/forms.js';
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';
import { refusalText } from '../members/refusals.js';

/** The badge colors of the roles an invitation gives (Tabler's light variants; a badge always says the role in words as well). */
const BADGE_CLASSES = {
    editor: 'bg-azure-lt',
    viewer: 'bg-secondary-lt',
};

/**
 * @typedef {object} ReceivedInvitation An invitation addressed to the person, as `ReceivedInvitationResource` describes it.
 * @property {string} id
 * @property {{ id: string, name: string }} workspace
 * @property {'editor' | 'viewer'} role
 * @property {{ name: string } | null} inviter Null once the account that sent it was deleted.
 * @property {string} expires_at ISO 8601.
 */

/**
 * @returns {Record<string, any>}
 */
export default function myInvitations() {
    return {
        /** The invitations that wait for an answer, the newest first. */
        invitations: [],

        /** True while the list is being read (and until it was, so that the empty state does not flash). */
        loading: true,

        /** True when the list could not be read: the page then offers to try again. */
        loadFailed: false,

        /** True while an answer is in flight; blocks double clicks and every other control. */
        busy: false,

        /** Id of the invitation whose acceptance is in flight, or null (it carries the spinner). */
        acceptingId: null,

        /** Id of the invitation waiting for the confirmation of its refusal, or null. */
        confirmingId: null,

        /** Root element of the component, kept from `init()` because `$root` is gone once an element has left the page. */
        rootElement: null,

        /** Endpoint of the invitations (GET, DELETE on `/{id}`, POST on `/{id}/accept`), from `data-url` on the component root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /** Page of a workspace, with `__id__` where its ULID goes, from `data-workspace-url` on the component root. */
        get workspaceUrl() {
            return this.rootElement.dataset.workspaceUrl;
        },

        /** Whether the list is known to be empty (the empty state is not shown while it is read, or when it failed). */
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
         * Reads the invitations from the API, and tells the header how many there are.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;
            this.loadFailed = false;

            try {
                const answer = await http.get(this.url);

                this.invitations = answer.data;
                this.tellHeader();
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
         * The name of the role an invitation gives, in the language of the page.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        roleLabel(invitation) {
            return t(`workspaces.role.${invitation.role}`);
        },

        /**
         * The classes of the badge of the role an invitation gives.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        roleClass(invitation) {
            return BADGE_CLASSES[invitation.role] ?? 'bg-secondary-lt';
        },

        /**
         * Who invited the person, as a sentence ("" when that account was deleted).
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        inviterText(invitation) {
            return invitation.inviter ? t('invitations.invited_by', { name: invitation.inviter.name }) : '';
        },

        /**
         * Until when an invitation is good, as a sentence.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        expiresText(invitation) {
            return t('invitations.expires', { date: momentText(invitation.expires_at) });
        },

        /**
         * The accessible name of the button that accepts an invitation (it says which one).
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        acceptLabel(invitation) {
            return t('invitations.accept_named', { name: invitation.workspace.name });
        },

        /**
         * The accessible name of the button that declines an invitation (it says which one).
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        declineLabel(invitation) {
            return t('invitations.decline_named', { name: invitation.workspace.name });
        },

        /**
         * The question asked before an invitation is declined.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        declineText(invitation) {
            return t('invitations.confirm_decline', { name: invitation.workspace.name });
        },

        /**
         * Whether the refusal of an invitation waits for its confirmation.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {boolean}
         */
        isConfirming(invitation) {
            return this.confirmingId === invitation.id;
        },

        /**
         * Whether the acceptance of an invitation is in flight.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {boolean}
         */
        isWorking(invitation) {
            return this.acceptingId === invitation.id;
        },

        /**
         * Asks for the confirmation of the refusal of an invitation.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {void}
         */
        askDecline(invitation) {
            this.confirmingId = invitation.id;
            this.focusCard('confirm', invitation.id);
        },

        /**
         * Gives up the refusal, and puts the cursor back on the button that asked for it.
         *
         * @returns {void}
         */
        cancelDecline() {
            const id = this.confirmingId;

            this.confirmingId = null;
            this.focusCard('decline', id);
        },

        /**
         * Accepts an invitation: the person joins the workspace with the role it names, and is taken to it once the notice has been
         * read. Everything stays disabled until the page is left.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {Promise<void>}
         */
        async accept(invitation) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.acceptingId = invitation.id;

            let workspace;

            try {
                const answer = await http.post(this.invitationUrl(invitation) + '/accept');

                workspace = answer.data;
            } catch (error) {
                this.busy = false;
                this.acceptingId = null;
                this.fail(error, invitation);

                return;
            }

            notify('success', t('invitations.notice.accepted', { name: invitation.workspace.name }));
            scheduleNavigation(() => redirectTo(fillUrl(this.workspaceUrl, workspace.id)));
        },

        /**
         * Declines an invitation: it can no longer be accepted, and leaves the list.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {Promise<void>}
         */
        async decline(invitation) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.delete(this.invitationUrl(invitation));

                this.drop(invitation.id);
                notify('success', t('invitations.notice.declined', { name: invitation.workspace.name }));
            } catch (error) {
                this.fail(error, invitation);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Tells why a request failed, unless the HTTP helper already did. An invitation that is gone (answered, withdrawn or
         * expired in the meantime) leaves the list.
         *
         * @param {unknown} error
         * @param {ReceivedInvitation} invitation The invitation the request was about.
         * @returns {void}
         */
        fail(error, invitation) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 404) {
                this.drop(invitation.id);
            }

            if (error.reported) {
                return;
            }

            notify('danger', this.failureText(error));
        },

        /**
         * The words for a refused request: the workspace or the account is full (told for the person who accepts, not for the owner who
         * invites), another refusal of a rule, or what the HTTP helper says.
         *
         * @param {HttpError} error
         * @returns {string}
         */
        failureText(error) {
            if (error.code === 'member_limit_reached') {
                return t('invitations.error.workspace_full');
            }

            if (error.code === 'membership_limit_reached') {
                return t('invitations.error.membership_limit', { count: error.body?.limit ?? '' });
            }

            return refusalText(error) ?? error.message;
        },

        /**
         * The address of one invitation in the API.
         *
         * @param {ReceivedInvitation} invitation
         * @returns {string}
         */
        invitationUrl(invitation) {
            return `${this.url}/${encodeURIComponent(invitation.id)}`;
        },

        /**
         * Takes an invitation off the list, tells the header, and puts the cursor on what is left.
         *
         * @param {string} id
         * @returns {void}
         */
        drop(id) {
            this.invitations = this.invitations.filter((invitation) => invitation.id !== id);

            if (this.confirmingId === id) {
                this.confirmingId = null;
            }

            this.tellHeader();
            this.focusAfterRemoval();
        },

        /**
         * Tells the header of the app how many invitations wait, so that its badge follows (see invitations-link.js).
         *
         * @returns {void}
         */
        tellHeader() {
            window.dispatchEvent(
                new CustomEvent('invitations-changed', { detail: { count: this.invitations.length } }),
            );
        },

        /**
         * Puts the cursor on the first invitation that is left, or on the message that says there are none, once the list has
         * been rendered.
         *
         * @returns {void}
         */
        focusAfterRemoval() {
            this.$nextTick(() => {
                const next = this.rootElement.querySelector('[data-invitation-action="accept"]');
                const heading = this.rootElement.querySelector('[data-focus="heading"]');

                (next ?? heading)?.focus();
            });
        },

        /**
         * Puts the cursor on a button of the card of an invitation once what was changed has been rendered.
         *
         * @param {'confirm' | 'decline'} action What the button does (`data-invitation-action`).
         * @param {string | null} id The invitation (`data-invitation-id`).
         * @returns {void}
         */
        focusCard(action, id) {
            this.$nextTick(() => {
                const buttons = this.rootElement.querySelectorAll(`[data-invitation-action="${action}"]`);

                [...buttons].find((button) => button.dataset.invitationId === String(id))?.focus();
            });
        },
    };
}
