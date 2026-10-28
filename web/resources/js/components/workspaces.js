/**
 * Alpine component of the workspaces page: list them, create one, rename or delete the ones the person owns, choose
 * the one to work in, and let the owners decide whether their editors may use the photo entry.
 *
 * The list lives in the shared `workspaces` store (see stores/workspaces.js), which the header shows as well; after
 * every change the component asks the API for the list again, so what is shown is what the server holds. Deleting
 * asks for a confirmation in the row itself. The endpoints come from the markup (`data-url`, `data-current-url`,
 * `data-workspace-url` on the root), because the CSP build of Alpine does not allow Blade to inject values into
 * expressions.
 *
 * The photo entry (editors add an item from photos that Claude, at Anthropic, reads) is the owner's choice for each workspace, a
 * switch in its row. The list endpoint does not carry it (it is a token route), so the page renders it: `data-photo-entry` is the
 * consent of each workspace the person owns, as `{ulid: bool}`, or `null` when the instance does not offer the feature (no switch is
 * shown then). `data-photo-entry-url` is the endpoint with `__id__` where the ULID goes. On an instance that sells plans, the workspaces
 * whose plan does not include the photo entry (Free) are listed in `data-photo-entry-locked` (a JSON list of ULIDs): their switch is
 * off and disabled, with a hint that leads to the subscription page (`data-billing-url`, `__id__` where the ULID goes).
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are
 * shown as a toast, with the server's translated validation message when there is one, and a message of our own for
 * the refusal to delete the last workspace.
 */
import { fillUrl } from '../lib/forms.js';
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

        /** The consent of the workspaces the person owns to the photo entry, by ULID; null when the instance does not offer it. */
        photoEntry: null,

        /** The ULIDs of the workspaces whose plan does not include the photo entry (the switch is disabled for them). */
        photoEntryLocked: [],

        /** Endpoint of the workspaces (GET, POST, PATCH, DELETE), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Endpoint that chooses the current workspace (PUT), from `data-current-url` on the component root. */
        get currentUrl() {
            return this.$root.dataset.currentUrl;
        },

        /** Page of a workspace, with `__id__` where its ULID goes, from `data-workspace-url` on the component root. */
        get workspaceUrl() {
            return this.$root.dataset.workspaceUrl;
        },

        /** Page of the members of a workspace, with `__id__` where its ULID goes, from `data-members-url` on the component root. */
        get membersPage() {
            return this.$root.dataset.membersUrl;
        },

        /** Endpoint of the photo entry of a workspace (PUT), with `__id__` where its ULID goes, from `data-photo-entry-url` on the component root. */
        get photoEntryUrl() {
            return this.$root.dataset.photoEntryUrl;
        },

        /** Page of the subscription of a workspace, with `__id__` where its ULID goes, from `data-billing-url` on the component root. */
        get billingPage() {
            return this.$root.dataset.billingUrl;
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
         * Reads the consent to the photo entry the page was rendered with, and puts the cursor in the creation box when the page was
         * opened from "New workspace" in the header.
         *
         * @returns {void}
         */
        init() {
            // `data-photo-entry` is rendered by the server: an object of booleans by ULID, or `null` when the instance does not offer the
            // feature (an attribute that is missing means the same).
            this.photoEntry = JSON.parse(this.$root.dataset.photoEntry ?? 'null');
            this.photoEntryLocked = JSON.parse(this.$root.dataset.photoEntryLocked ?? '[]');

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
         * Whether the switch of the photo entry is offered for a workspace: the instance offers the feature, and the person owns the
         * workspace (the owner alone chooses).
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        canPhotoEntry(item) {
            return this.photoEntry !== null && this.canManage(item);
        },

        /**
         * Whether the switch of the photo entry of a workspace shows it on: the owner turned it on and the plan includes it (a choice made
         * before the workspace went back to Free is kept, but the feature is off meanwhile). A workspace the page does not know (one
         * created since it was rendered) has it off, as a new workspace does.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        photoEntryOn(item) {
            return this.photoEntry?.[item.id] === true && !this.photoEntryLockedByPlan(item);
        },

        /**
         * Whether the plan of a workspace leaves the photo entry out (an instance that sells plans, a workspace on Free): its switch can
         * not be turned on, whatever the owner chose before.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        photoEntryLockedByPlan(item) {
            return this.photoEntryLocked.includes(item.id);
        },

        /**
         * Whether the switch of the photo entry of a workspace is disabled: a request is in flight, or the plan leaves it out.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        photoEntryDisabled(item) {
            return this.busy || this.photoEntryLockedByPlan(item);
        },

        /**
         * The ids of the hints that describe the switch of a workspace: the one about Claude, and the one about the plan when it applies.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        photoEntryHintIds(item) {
            const ids = ['workspace-photo-entry-hint-' + item.id];

            if (this.photoEntryLockedByPlan(item)) {
                ids.push('workspace-photo-entry-plan-' + item.id);
            }

            return ids.join(' ');
        },

        /**
         * Where the subscription page of a workspace is: the way to the plan that includes the photo entry.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        billingUrl(item) {
            return fillUrl(this.billingPage, item.id);
        },

        /**
         * The accessible name of the photo entry switch of a workspace.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        photoEntryLabel(item) {
            return t('workspaces.photo_entry_named', { name: item.name });
        },

        /**
         * Whether the delete button is offered for a workspace: to its owners, and never for the last workspace of the
         * person, which the server refuses to delete (`last_workspace`) anyway.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {boolean}
         */
        canDelete(item) {
            return this.canManage(item) && this.items.length > 1;
        },

        /**
         * Where the page of a workspace is: its name links there.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        pageUrl(item) {
            return fillUrl(this.workspaceUrl, item.id);
        },

        /**
         * Where the page of the members of a workspace is: the people who belong to it, and for its owner the way to manage them.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        membersUrl(item) {
            return fillUrl(this.membersPage, item.id);
        },

        /**
         * The accessible name of the link to the members of a workspace.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        membersLabel(item) {
            return t('workspaces.members_named', { name: item.name });
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
         * Saves the position the photo entry switch of a workspace was put in. The switch then shows what the server answers, and goes
         * back to what it was if the change could not be saved. When the server says the instance no longer offers the feature (`409`),
         * the switches are taken off the page: the administrator turned it off since it was rendered.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @param {Event} event The change event of the checkbox.
         * @returns {Promise<void>}
         */
        async togglePhotoEntry(item, event) {
            const box = event.target;

            if (this.busy || this.photoEntryLockedByPlan(item)) {
                box.checked = this.photoEntryOn(item);

                return;
            }

            this.busy = true;

            try {
                const answer = await http.put(fillUrl(this.photoEntryUrl, item.id), { enabled: box.checked === true });
                const enabled = answer.data.enabled === true;

                this.photoEntry = { ...this.photoEntry, [item.id]: enabled };
                notify(
                    'success',
                    t(enabled ? 'workspaces.notice.photo_entry_on' : 'workspaces.notice.photo_entry_off', {
                        name: item.name,
                    }),
                );
            } catch (error) {
                if (error instanceof HttpError && error.status === 409 && error.code === 'photo_entry_unavailable') {
                    this.photoEntry = null;
                }

                this.fail(error);
            } finally {
                box.checked = this.photoEntryOn(item);
                this.busy = false;
            }
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
