/**
 * Alpine component of the members page of a workspace: list who belongs to it and, for its owner, change a role, remove a
 * member and hand the ownership over; for the other members, leave it.
 *
 * What is shown is what the server holds: the list is read from the API, and a change either updates the row from the answer or
 * reads the list again when the answer says it was out of date (somebody else changed the workspace in the meantime). Removing,
 * handing the ownership over and leaving ask for a confirmation in the page itself. After the ownership changed hands the page
 * reloads (what it offers depends on who the owner is), and after leaving the person goes back to their space (`/app`). The
 * endpoints and what the person is come from the markup (`data-*` on the root), because the CSP build of Alpine does not allow Blade
 * to inject values into expressions. The API judges the person again whatever the page offers.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; a refusal the API explains with a
 * code is told in words of our own (see ../members/refusals.js).
 */
import { momentText } from '../grid/rows.js';
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { redirectTo, reloadPage } from '../lib/page.js';
import { refusalText } from '../members/refusals.js';
import { roleClass, roleLabel } from '../members/roles.js';

/**
 * @typedef {object} Member A member, as `MemberResource` describes it.
 * @property {string} id The identifier of the member in this workspace (not the number of their account).
 * @property {string} name
 * @property {string | null} email Only given to the owner and to the member themselves.
 * @property {'owner' | 'editor' | 'viewer'} role
 * @property {string | null} joined_at ISO 8601.
 * @property {boolean} is_self Whether it is the person who is looking.
 */

/**
 * @returns {Record<string, any>}
 */
export default function workspaceMembers() {
    return {
        /** The members, the owner first, then in the order they joined. */
        members: [],

        /** True while the list is being read (and until it was, so that the empty states do not flash). */
        loading: true,

        /** True when the list could not be read: the card then offers to try again. */
        loadFailed: false,

        /** True while a change is in flight; blocks double clicks and every other control. */
        busy: false,

        /** Id of the member waiting for the confirmation of an action, or null. */
        confirmingId: null,

        /** What the confirmation is about: `remove` or `transfer` ("" when nothing waits). */
        confirmingAction: '',

        /** Whether the person is asked to confirm that they leave the workspace. */
        confirmingLeave: false,

        /** Root element of the component, kept from `init()` because `$root` is gone once an element has left the page. */
        rootElement: null,

        /** Endpoint of the members (GET, and PATCH and DELETE on `/{id}`), from `data-url` on the component root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /** Endpoint that hands the ownership over (POST), from `data-transfer-url` on the component root. */
        get transferUrl() {
            return this.rootElement.dataset.transferUrl;
        },

        /** Where the person goes after leaving: their space, from `data-home-url` on the component root. */
        get homeUrl() {
            return this.rootElement.dataset.homeUrl;
        },

        /** The name of the workspace, from `data-workspace-name` on the component root. */
        get workspaceName() {
            return this.rootElement.dataset.workspaceName ?? '';
        },

        /** Whether the person is the owner of the workspace, from `data-is-owner` on the component root. */
        get isOwner() {
            return this.rootElement.dataset.isOwner === '1';
        },

        /** Whether somebody else than the person belongs to the workspace (the owner then cannot leave it without handing it over). */
        get hasOthers() {
            return this.members.length > 1;
        },

        /** Whether the person can ask to leave: they are not the owner, and the list (which tells which member they are) is there. */
        get canLeave() {
            return !this.busy && !this.isOwner && this.members.some((member) => member.is_self);
        },

        /** The question asked before the person leaves. */
        get leaveConfirmText() {
            return t('members.confirm_leave', { name: this.workspaceName });
        },

        /** What the button that confirms the action that waits says. */
        get confirmLabel() {
            return t(this.confirmingAction === 'transfer' ? 'members.make_owner' : 'members.remove');
        },

        /** The color of the button that confirms: handing the ownership over is a warning, removing somebody a danger. */
        get confirmClass() {
            return this.confirmingAction === 'transfer' ? 'btn-warning' : 'btn-danger';
        },

        /**
         * Reads the members when the page opens.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.rootElement = this.$root;
            await this.load();
        },

        /**
         * Reads the members from the API.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;
            this.loadFailed = false;

            try {
                const answer = await http.get(this.url);

                this.members = answer.data;
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

        /** The name of a role and the classes of its badge (see `members/roles.js`), for the template to call. */
        roleLabel,
        roleClass,

        /**
         * Whether the owner may act on a member: any member but the owner (their role is not theirs to change, they are not removed).
         *
         * @param {Member} member
         * @returns {boolean}
         */
        canManage(member) {
            return this.isOwner && member.role !== 'owner';
        },

        /**
         * When a member joined, as a sentence ("" when the API does not know).
         *
         * @param {Member} member
         * @returns {string}
         */
        joinedText(member) {
            const date = momentText(member.joined_at);

            return date === '' ? '' : t('members.joined', { date });
        },

        /**
         * The `id` of the select that changes the role of a member.
         *
         * @param {Member} member
         * @returns {string}
         */
        roleSelectId(member) {
            return `member-role-${member.id}`;
        },

        /**
         * The accessible name of the select that changes the role of a member.
         *
         * @param {Member} member
         * @returns {string}
         */
        roleSelectLabel(member) {
            return t('members.role_of', { name: member.name });
        },

        /**
         * The accessible name of the button that hands the ownership over to a member.
         *
         * @param {Member} member
         * @returns {string}
         */
        transferLabel(member) {
            return t('members.make_owner_named', { name: member.name });
        },

        /**
         * The accessible name of the button that removes a member.
         *
         * @param {Member} member
         * @returns {string}
         */
        removeLabel(member) {
            return t('members.remove_named', { name: member.name });
        },

        /**
         * Whether an action of a member waits for its confirmation.
         *
         * @param {Member} member
         * @returns {boolean}
         */
        isConfirming(member) {
            return this.confirmingId === member.id;
        },

        /**
         * The question asked before the action that waits is done.
         *
         * @param {Member} member
         * @returns {string}
         */
        confirmText(member) {
            return t(this.confirmingAction === 'transfer' ? 'members.confirm_transfer' : 'members.confirm_remove', {
                name: member.name,
            });
        },

        /**
         * The `id` of the element that holds the question of a member's confirmation: the button that confirms points at it
         * (`aria-describedby`), so that a screen reader says what is being confirmed along with the button.
         *
         * @param {Member} member
         * @returns {string}
         */
        confirmTextId(member) {
            return `member-confirm-text-${member.id}`;
        },

        /**
         * Gives up the action that waits for its confirmation, and puts the cursor back on the button that asked for it.
         *
         * @returns {void}
         */
        cancelConfirm() {
            const { confirmingId: id, confirmingAction: action } = this;

            this.confirmingId = null;
            this.confirmingAction = '';
            this.focusRow(action, id);
        },

        /**
         * Does the action that waited for its confirmation.
         *
         * @param {Member} member
         * @returns {Promise<void>}
         */
        confirm(member) {
            return this.confirmingAction === 'transfer' ? this.transfer(member) : this.remove(member);
        },

        /**
         * Changes the role of a member to the one chosen in the select of its row. The select goes back to the role the member has
         * when the change is refused.
         *
         * @param {Member} member
         * @param {Event} event The `change` event of the select.
         * @returns {Promise<void>}
         */
        async changeRole(member, event) {
            const select = /** @type {HTMLSelectElement} */ (event.target);
            const role = select.value;

            if (this.busy || role === member.role) {
                select.value = member.role;

                return;
            }

            this.busy = true;

            try {
                const answer = await http.patch(this.memberUrl(member), { role });

                this.replace(answer.data);
                notify('success', t('members.notice.role_changed', { name: member.name }));
            } catch (error) {
                select.value = member.role;
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Removes a member from the workspace: they lose access at once, and the row leaves the list.
         *
         * @param {Member} member
         * @returns {Promise<void>}
         */
        async remove(member) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.delete(this.memberUrl(member));

                this.drop(member.id);
                notify('success', t('members.notice.removed', { name: member.name }));
                this.focus('heading');
            } catch (error) {
                // Already gone (it left, or was removed elsewhere): the list was out of date, and now it is not.
                if (error instanceof HttpError && error.status === 404) {
                    this.drop(member.id);
                    this.focus('heading');
                } else {
                    this.fail(error);
                }
            } finally {
                this.busy = false;
            }
        },

        /**
         * Hands the ownership over to a member: they become the owner and the person an editor. The page reloads once the notice
         * has been read, since what it offers depends on who the owner is.
         *
         * @param {Member} member
         * @returns {Promise<void>}
         */
        async transfer(member) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.post(this.transferUrl, { member_id: member.id });
            } catch (error) {
                this.busy = false;
                this.fail(error);

                return;
            }

            // The controls stay disabled until the page is reloaded.
            notify('success', t('members.notice.transferred', { name: member.name }));
            scheduleNavigation(reloadPage);
        },

        /**
         * Asks for the confirmation that the person leaves the workspace.
         *
         * @returns {void}
         */
        askLeave() {
            this.confirmingLeave = true;
            this.focus('confirmLeave');
        },

        /**
         * Gives up leaving.
         *
         * @returns {void}
         */
        cancelLeave() {
            this.confirmingLeave = false;
            this.focus('leave');
        },

        /**
         * Leaves the workspace: the person removes themselves from it, and goes back to their space once the notice has been read.
         *
         * @returns {Promise<void>}
         */
        async leave() {
            const self = this.members.find((member) => member.is_self);

            if (this.busy || !self) {
                return;
            }

            this.busy = true;

            try {
                await http.delete(this.memberUrl(self));
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && error.status === 404) {
                    // Not a member any more (removed in the meantime, or the workspace is gone): there is nothing left to leave.
                    scheduleNavigation(() => redirectTo(this.homeUrl));

                    return;
                }

                this.busy = false;
                this.fail(error);

                return;
            }

            // The controls stay disabled until the person is gone.
            notify('success', t('members.notice.left', { name: this.workspaceName }));
            scheduleNavigation(() => redirectTo(this.homeUrl));
        },

        /**
         * Tells why a request failed, unless the HTTP helper already did, and reads the list again when the failure shows that it
         * was out of date.
         *
         * A member who is gone (404) or a rule that no longer holds (409: the member became the owner, say) means somebody changed
         * the workspace in the meantime. A `403` (`not_owner`) means the person is not the owner any more: the page, which was
         * rendered for the owner, is reloaded.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError)) {
                return;
            }

            this.confirmingId = null;
            this.confirmingAction = '';
            this.confirmingLeave = false;

            if (error.status === 404 || error.status === 409) {
                void this.load();
            }

            if (error.code === 'not_owner') {
                scheduleNavigation(reloadPage);

                // Said whether or not the helper reported: its own words for a 403 do not say why, these do.
                notify('danger', t('members.error.not_owner'));

                return;
            }

            if (!error.reported) {
                notify('danger', refusalText(error) ?? error.message);
            }
        },

        /**
         * Asks for the confirmation of an action on a member.
         *
         * @param {Member} member
         * @param {'remove' | 'transfer'} action
         * @returns {void}
         */
        ask(member, action) {
            this.confirmingId = member.id;
            this.confirmingAction = action;
            this.focusRow('confirm', member.id);
        },

        /**
         * The address of one member in the API.
         *
         * @param {Member} member
         * @returns {string}
         */
        memberUrl(member) {
            return `${this.url}/${encodeURIComponent(member.id)}`;
        },

        /**
         * Puts a member the API sent back in the place of the one the list has.
         *
         * @param {Member} member
         * @returns {void}
         */
        replace(member) {
            this.members = this.members.map((current) => (current.id === member.id ? member : current));
        },

        /**
         * Takes a member off the list.
         *
         * @param {string} id
         * @returns {void}
         */
        drop(id) {
            this.members = this.members.filter((member) => member.id !== id);

            if (this.confirmingId === id) {
                this.confirmingId = null;
                this.confirmingAction = '';
            }
        },

        /**
         * Puts the cursor on an element of the card, by the name its markup gives it (`data-focus`), once what was changed has been
         * rendered. It is looked up from the root rather than with `$refs`, which is empty once the element that started the call
         * (the button of a row that was just removed) has left the page.
         *
         * @param {'heading' | 'leave' | 'confirmLeave'} name
         * @returns {void}
         */
        focus(name) {
            this.$nextTick(() => this.rootElement.querySelector(`[data-focus="${name}"]`)?.focus());
        },

        /**
         * Puts the cursor on a button of the row of a member once what was changed has been rendered.
         *
         * @param {string} action What the button does (`data-member-action`): `confirm`, `remove` or `transfer`.
         * @param {string | null} id The member (`data-member-id`).
         * @returns {void}
         */
        focusRow(action, id) {
            this.$nextTick(() => {
                const buttons = this.rootElement.querySelectorAll(`[data-member-action="${action}"]`);

                [...buttons].find((button) => button.dataset.memberId === String(id))?.focus();
            });
        },
    };
}
