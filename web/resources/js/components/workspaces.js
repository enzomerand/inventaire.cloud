/**
 * Alpine component of the workspaces page: list them, create one, rename or delete the ones the person owns, and
 * choose the one to work in.
 *
 * The list lives in the shared `workspaces` store (see stores/workspaces.js), which the header shows as well; after
 * every change the component asks the API for the list again, so what is shown is what the server holds. Deleting
 * asks for a confirmation in the row itself. The endpoints come from the markup (`data-url`, `data-current-url`
 * on the root), because the CSP build of Alpine does not allow Blade to inject values into expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are
 * shown as a toast, with the server's translated validation message when there is one, and a message of our own for
 * the refusal to delete the last workspace.
 */
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';
import { fromApi } from '../stores/workspaces.js';

/**
 * @returns {Record<string, any>}
 */
export default function workspaces() {
    return {
        /** What was typed in the box that names a new workspace. */
        newName: '',

        /** True while a request is in flight; blocks double submits. */
        busy: false,

        /** Id of the workspace being renamed, or null. */
        editingId: null,

        /** What was typed in the rename box. */
        editName: '',

        /** Id of the workspace waiting for the confirmation of its deletion, or null. */
        confirmingId: null,

        /** Endpoint of the workspaces (GET, POST, PATCH, DELETE), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Endpoint that chooses the current workspace (PUT), from `data-current-url` on the component root. */
        get currentUrl() {
            return this.$root.dataset.currentUrl;
        },

        /** The workspaces of the person, from the shared store. */
        get items() {
            return this.$store.workspaces.items;
        },

        /** Whether the creation form can be submitted: it has a name, and nothing else is in flight. */
        get canCreate() {
            return !this.busy && this.newName.trim() !== '';
        },

        /**
         * Puts the cursor in the creation box when the page was opened from "New workspace" in the header.
         *
         * @returns {void}
         */
        init() {
            if (window.location.hash === '#create') {
                this.$nextTick(() => this.$refs.name?.focus());
            }
        },

        /**
         * Whether the person may rename and delete a workspace: only its owners.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        canManage(item) {
            return item.role === 'owner';
        },

        /**
         * The role of the person in a workspace, as they read it.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        roleLabel(item) {
            return t('workspaces.role.' + item.role);
        },

        /**
         * Whether a workspace is the one being renamed.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        isEditing(item) {
            return this.editingId === item.id;
        },

        /**
         * Whether a workspace is waiting for the confirmation of its deletion.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        isConfirming(item) {
            return this.confirmingId === item.id;
        },

        /**
         * The accessible name of the rename button of a workspace.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        renameLabel(item) {
            return t('workspaces.rename_named', { name: item.name });
        },

        /**
         * The accessible name of the delete button of a workspace.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        deleteLabel(item) {
            return t('workspaces.delete_named', { name: item.name });
        },

        /**
         * The question asked before a workspace is deleted.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        confirmText(item) {
            return t('workspaces.confirm_delete', { name: item.name });
        },

        /**
         * Creates a workspace with the typed name; it becomes the current one.
         *
         * @returns {Promise<void>}
         */
        async create() {
            await this.change(async () => {
                await http.post(this.url, { name: this.newName.trim() });
                this.newName = '';
            }, 'workspaces.notice.created');
        },

        /**
         * Opens the rename box of a workspace, with its current name in it.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {void}
         */
        startRename(item) {
            this.confirmingId = null;
            this.editingId = item.id;
            this.editName = item.name;
        },

        /**
         * Closes the rename box without saving.
         *
         * @returns {void}
         */
        cancelRename() {
            this.editingId = null;
            this.editName = '';
        },

        /**
         * Saves the new name of a workspace.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {Promise<void>}
         */
        async saveRename(item) {
            const saved = await this.change(
                () => http.patch(this.url + '/' + item.id, { name: this.editName.trim() }),
                'workspaces.notice.renamed',
            );

            if (saved) {
                this.cancelRename();
            }
        },

        /**
         * Asks for the confirmation of the deletion of a workspace.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {void}
         */
        askDelete(item) {
            this.cancelRename();
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
         * Deletes a workspace, once confirmed, and everything in it.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {Promise<void>}
         */
        async confirmDelete(item) {
            const deleted = await this.change(() => http.delete(this.url + '/' + item.id), 'workspaces.notice.deleted');

            if (deleted) {
                this.confirmingId = null;
            }
        },

        /**
         * Makes a workspace the current one, then reloads the page so that it shows that workspace.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {Promise<void>}
         */
        async makeCurrent(item) {
            if (this.busy || item.current) {
                return;
            }

            this.busy = true;

            try {
                await http.put(this.currentUrl, { workspace: item.id });
            } catch (error) {
                this.busy = false;
                this.fail(error);

                return;
            }

            reloadPage();
        },

        /**
         * Runs a change, tells the person how it went and shows the list as the server now holds it.
         *
         * @param {() => Promise<unknown>} action The request.
         * @param {string} notice Key of the message that says it worked.
         * @returns {Promise<boolean>} Whether it worked.
         */
        async change(action, notice) {
            if (this.busy) {
                return false;
            }

            this.busy = true;

            try {
                await action();
                notify('success', t(notice));
            } catch (error) {
                this.fail(error);
                this.busy = false;

                return false;
            }

            try {
                await this.refresh();
            } finally {
                this.busy = false;
            }

            return true;
        },

        /**
         * Reads the list again from the API into the shared store.
         *
         * @returns {Promise<void>}
         */
        async refresh() {
            try {
                const answer = await http.get(this.url);

                this.$store.workspaces.replace(answer.data.map(fromApi));
            } catch (error) {
                this.fail(error);
            }
        },

        /**
         * Tells the person why a request failed, unless the HTTP helper already did.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError) || error.reported) {
                return;
            }

            if (error.status === 409 && error.code === 'last_workspace') {
                notify('danger', t('workspaces.error.last'));
            } else if (error.status === 422) {
                notify('danger', error.errors.name?.[0] ?? error.errors.workspace?.[0] ?? error.message);
            } else {
                notify('danger', error.message);
            }
        },
    };
}
