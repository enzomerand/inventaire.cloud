/**
 * Alpine component of the administration's users page: list and search the accounts, create one, make one an
 * administrator (or not any more) and delete one.
 *
 * The list is one page of the API at a time (25 accounts, newest first); the search box filters it on the server
 * (names and addresses, case ignored). After every change the component asks the API for the page again, so what is
 * shown is what the server holds. Deleting asks for a confirmation in the row itself. The endpoint comes from the
 * markup (`data-url` on the root), because the CSP build of Alpine does not allow Blade to inject values into
 * expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are
 * shown as a toast, or under the creation form when they are about what was typed there.
 */
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';

/**
 * @typedef {object} AdminUser An account, as `AdminUserResource` describes it.
 * @property {number} id
 * @property {string} name
 * @property {string} email
 * @property {boolean} is_admin
 * @property {boolean} is_self Whether it is the administrator who is looking.
 * @property {number} workspaces_count
 * @property {string | null} created_at ISO 8601.
 */

/**
 * @returns {Record<string, any>}
 */
export default function adminUsers() {
    return {
        /** The accounts of the page that is shown. */
        items: [],

        /** What was typed in the search box. */
        query: '',

        /** The search the shown page belongs to (what was typed when "Search" was pressed). */
        activeQuery: '',

        /** Number of the page that is shown, from 1. */
        page: 1,

        /** Number of the last page. */
        lastPage: 1,

        /** Accounts that match, all pages together. */
        total: 0,

        /** Position of the first and of the last account of the page among those that match (0 when there are none). */
        from: 0,
        to: 0,

        /** True while a page is being read (and until the first one is, so that the empty message does not flash). */
        loading: true,

        /** True while a change is in flight; blocks double submits. */
        busy: false,

        /** Ticket of the last page asked for: an answer for an older one is dropped. */
        ticket: 0,

        /** What was typed in the address box of the creation form. */
        newEmail: '',

        /** What was typed in the name box of the creation form. */
        newName: '',

        /** Why the creation form was refused ("" when it was not). */
        formError: '',

        /** Id of the account waiting for the confirmation of its deletion, or null. */
        confirmingId: null,

        /** Endpoint of the accounts (GET, POST, and PATCH and DELETE on `/{id}`), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Whether the creation form can be submitted: it has an address and a name, and nothing else is in flight. */
        get canCreate() {
            return !this.busy && this.newEmail.trim() !== '' && this.newName.trim() !== '';
        },

        /** Whether the list is known to be empty (nothing matches, or there is no account at all). */
        get empty() {
            return !this.loading && this.items.length === 0;
        },

        /** Whether there is a page before this one. */
        get hasPrevious() {
            return this.page > 1;
        },

        /** Whether there is a page after this one. */
        get hasNext() {
            return this.page < this.lastPage;
        },

        /** Which accounts are shown, as a sentence ("1–25 of 40"), or "" when there are none. */
        get rangeLabel() {
            return this.total === 0
                ? ''
                : t('admin.users.range', { first: this.from, last: this.to, total: this.total });
        },

        /**
         * Reads the first page when the page opens.
         *
         * @returns {Promise<void>}
         */
        async init() {
            await this.load(1);
        },

        /**
         * Reads a page of the list from the API (for the search that is active).
         *
         * @param {number} page Number of the page to read.
         * @returns {Promise<void>}
         */
        async load(page) {
            const ticket = ++this.ticket;
            this.loading = true;

            try {
                const answer = await http.get(this.url, { params: { q: this.activeQuery, page } });

                if (ticket !== this.ticket) {
                    return;
                }

                // Accounts were deleted since the page was shown, and it is past the end now: the last one it is.
                if (answer.data.length === 0 && page > answer.meta.last_page) {
                    this.loading = false;
                    await this.load(answer.meta.last_page);

                    return;
                }

                this.items = answer.data;
                this.page = answer.meta.current_page;
                this.lastPage = answer.meta.last_page;
                this.total = answer.meta.total;
                this.from = answer.meta.from ?? 0;
                this.to = answer.meta.to ?? 0;
            } catch (error) {
                this.fail(error);
            } finally {
                if (ticket === this.ticket) {
                    this.loading = false;
                }
            }
        },

        /**
         * Searches names and addresses for what was typed, from the first page.
         *
         * @returns {Promise<void>}
         */
        async search() {
            this.confirmingId = null;
            this.activeQuery = this.query.trim();
            await this.load(1);
        },

        /**
         * Shows the page before.
         *
         * @returns {Promise<void>}
         */
        async previous() {
            if (this.hasPrevious) {
                await this.load(this.page - 1);
            }
        },

        /**
         * Shows the page after.
         *
         * @returns {Promise<void>}
         */
        async next() {
            if (this.hasNext) {
                await this.load(this.page + 1);
            }
        },

        /**
         * Creates the account of the typed address and name; the person is told by email how to sign in.
         *
         * @returns {Promise<void>}
         */
        async create() {
            if (!this.canCreate) {
                return;
            }

            this.busy = true;
            this.formError = '';

            try {
                await http.post(this.url, { email: this.newEmail.trim(), name: this.newName.trim() });
                notify('success', t('admin.users.notice.created'));
                this.newEmail = '';
                this.newName = '';
            } catch (error) {
                this.busy = false;
                this.failCreate(error);

                return;
            }

            // The newest account comes first: show the list from the top, whatever was searched.
            this.query = '';
            this.activeQuery = '';

            try {
                await this.load(1);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Makes an account an administrator, or takes that away.
         *
         * @param {AdminUser} item
         * @returns {Promise<void>}
         */
        async toggleAdmin(item) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            const promote = !item.is_admin;

            try {
                const answer = await http.patch(this.url + '/' + item.id, { is_admin: promote });

                this.items = this.items.map((other) => (other.id === item.id ? answer.data : other));
                notify('success', t(promote ? 'admin.users.notice.promoted' : 'admin.users.notice.demoted'));
            } catch (error) {
                await this.failChange(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Asks for the confirmation of the deletion of an account.
         *
         * @param {AdminUser} item
         * @returns {void}
         */
        askDelete(item) {
            this.confirmingId = item.id;
        },

        /**
         * Gives up deleting.
         *
         * @returns {void}
         */
        cancelDelete() {
            this.confirmingId = null;
        },

        /**
         * Deletes an account, once confirmed.
         *
         * @param {AdminUser} item
         * @returns {Promise<void>}
         */
        async confirmDelete(item) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.delete(this.url + '/' + item.id);
                notify('success', t('admin.users.notice.deleted'));
                this.confirmingId = null;
                await this.load(this.page);
            } catch (error) {
                await this.failChange(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Whether an account is waiting for the confirmation of its deletion.
         *
         * @param {AdminUser} item
         * @returns {boolean}
         */
        isConfirming(item) {
            return this.confirmingId === item.id;
        },

        /**
         * The text of the button that grants or removes administrator rights, for what it will do.
         *
         * @param {AdminUser} item
         * @returns {string}
         */
        toggleText(item) {
            return t(item.is_admin ? 'admin.users.demote' : 'admin.users.promote');
        },

        /**
         * The accessible name of that button, with the name of the account.
         *
         * @param {AdminUser} item
         * @returns {string}
         */
        toggleLabel(item) {
            return t(item.is_admin ? 'admin.users.demote_named' : 'admin.users.promote_named', { name: item.name });
        },

        /**
         * The accessible name of the delete button of an account.
         *
         * @param {AdminUser} item
         * @returns {string}
         */
        deleteLabel(item) {
            return t('admin.users.delete_named', { name: item.name });
        },

        /**
         * The question asked before an account is deleted.
         *
         * @param {AdminUser} item
         * @returns {string}
         */
        confirmText(item) {
            return t('admin.users.confirm_delete', { name: item.name });
        },

        /**
         * How many workspaces an account belongs to, as a short sentence.
         *
         * @param {AdminUser} item
         * @returns {string}
         */
        workspacesLabel(item) {
            return t('admin.users.workspace_count', { count: item.workspaces_count });
        },

        /**
         * The day an account was created, in the language of the page ("" when unknown).
         *
         * @param {AdminUser} item
         * @returns {string}
         */
        createdLabel(item) {
            const date = new Date(item.created_at ?? '');

            if (Number.isNaN(date.getTime())) {
                return '';
            }

            return t('admin.users.created_on', {
                date: new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                    dateStyle: 'medium',
                }).format(date),
            });
        },

        /**
         * Tells why the creation was refused: under the form when it is about what was typed there, as a toast otherwise.
         *
         * @param {unknown} error
         * @returns {void}
         */
        failCreate(error) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 409 && error.code === 'account_exists') {
                this.formError = t('admin.users.error.account_exists');
            } else if (error.status === 422) {
                this.formError = error.errors.email?.[0] ?? error.errors.name?.[0] ?? error.message;
            } else if (!error.reported) {
                notify('danger', error.message);
            }
        },

        /**
         * Tells why a change was refused, and shows the list as the server holds it when the account is gone.
         *
         * @param {unknown} error
         * @returns {Promise<void>}
         */
        async failChange(error) {
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 409 && error.code === 'last_administrator') {
                notify('danger', t('admin.users.error.last_administrator'));
            } else if (error.status === 409 && error.code === 'self_deletion') {
                notify('danger', t('admin.users.error.self_deletion'));
            } else if (!error.reported) {
                notify('danger', error.message);
            }

            if (error.status === 404) {
                this.confirmingId = null;
                await this.load(this.page);
            }
        },

        /**
         * Tells why the list could not be read, unless the HTTP helper already did.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            if (error instanceof HttpError && !error.reported) {
                notify('danger', error.message);
            }
        },
    };
}
