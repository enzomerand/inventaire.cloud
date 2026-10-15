/**
 * Alpine component of the header of an inventory page: its name and description, and what may be done with the
 * inventory itself (rename or describe it, delete it), according to the abilities the page hands over.
 *
 * The name and the description start as the page rendered them (`data-name`, `data-description`) and follow what the
 * API answers to a change. Deleting asks for a confirmation that says how many items go with the inventory, read from
 * the API at that moment (the page may be older than the last item added by somebody else); it takes the person back
 * to the page of the workspace. The endpoints and abilities come from the markup (`data-*` attributes on the root),
 * because the CSP build of Alpine does not allow Blade to inject values into expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * under the form that caused them, or as a toast.
 */
import { firstMessages } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';

/** What the title of the tab says between the name of the page and the name of the application. */
const TITLE_SEPARATOR = ' · ';

/**
 * @returns {Record<string, any>}
 */
export default function inventoryHeader() {
    return {
        /** The name of the inventory. */
        name: '',

        /** Its description, "" for none. */
        description: '',

        /** What was typed in the dialog that changes them. */
        form: { name: '', description: '' },

        /** The messages of a refused form, by input (`name`, `description`). */
        errors: {},

        /** Why the form was refused when it is not about one input ("" when it was not). */
        formError: '',

        /** True while a request is in flight; blocks double submits. */
        busy: false,

        /** How many items the inventory holds, as the API said when the person asked to delete it (null until then). */
        itemsCount: null,

        /** Endpoint of the inventory (GET, PATCH, DELETE), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Page of the workspace the inventory belongs to, from `data-workspace-page` on the component root. */
        get workspacePage() {
            return this.$root.dataset.workspacePage;
        },

        /** Whether the person may change the name and the description, from `data-can-update`. */
        get canUpdate() {
            return this.$root.dataset.canUpdate === '1';
        },

        /** Whether the person may delete the inventory, from `data-can-delete`. */
        get canDelete() {
            return this.$root.dataset.canDelete === '1';
        },

        /** Whether the person may manage the fields (and so is offered the link to their page), from `data-can-manage-fields`. */
        get canManageFields() {
            return this.$root.dataset.canManageFields === '1';
        },

        /** Whether the inventory has a description to show. */
        get hasDescription() {
            return this.description !== '';
        },

        /** Whether the form can be submitted: it has a name, and nothing else is in flight. */
        get canSubmit() {
            return !this.busy && this.form.name.trim() !== '';
        },

        /** The question asked before the inventory is deleted, with the number of items that go with it. */
        get confirmText() {
            return this.itemsCount === null
                ? ''
                : tn('inventories.confirm_delete', this.itemsCount, { name: this.name });
        },

        /**
         * Takes the name and the description the page rendered, and forgets the form whenever a dialog is closed.
         *
         * @returns {void}
         */
        init() {
            this.name = this.$root.dataset.name ?? '';
            this.description = this.$root.dataset.description ?? '';

            this.$nextTick(() => {
                onModalHidden(this.$refs.editModal, () => this.resetForm());
                onModalHidden(this.$refs.deleteModal, () => {
                    this.itemsCount = null;
                });
            });
        },

        /**
         * Opens the dialog that changes the name and the description, with the current ones in it.
         *
         * @returns {void}
         */
        startEdit() {
            this.form = { name: this.name, description: this.description };
            this.errors = {};
            this.formError = '';
            showModal(this.$refs.editModal);
        },

        /**
         * Forgets the refusals of the form.
         *
         * @returns {void}
         */
        resetForm() {
            this.errors = {};
            this.formError = '';
        },

        /**
         * Saves the name and the description.
         *
         * @returns {Promise<void>}
         */
        async save() {
            if (!this.canSubmit) {
                return;
            }

            this.busy = true;
            this.errors = {};
            this.formError = '';

            try {
                const description = this.form.description.trim();
                const answer = await http.patch(this.url, {
                    name: this.form.name.trim(),
                    description: description === '' ? null : description,
                });

                this.name = answer.data.name;
                this.description = answer.data.description ?? '';
                this.retitle();
                hideModal(this.$refs.editModal);
                notify('success', t('inventories.notice.updated'));
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Puts the new name in the title of the tab, in front of the name of the application.
         *
         * @returns {void}
         */
        retitle() {
            const at = document.title.lastIndexOf(TITLE_SEPARATOR);

            document.title = at === -1 ? this.name : this.name + document.title.slice(at);
        },

        /**
         * Asks for the confirmation of the deletion: reads how many items the inventory holds now, then opens the dialog.
         *
         * @returns {Promise<void>}
         */
        async askDelete() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                this.itemsCount = (await http.get(this.url)).data.items_count;
                showModal(this.$refs.deleteModal);
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Deletes the inventory and everything in it, then takes the person back to the page of the workspace.
         *
         * @returns {Promise<void>}
         */
        async confirmDelete() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await http.delete(this.url);
                hideModal(this.$refs.deleteModal);
                redirectTo(this.workspacePage);
            } catch (error) {
                this.busy = false;
                this.fail(error);
            }
        },

        /**
         * Tells the person why a request failed, unless the HTTP helper already did: under the input a 422 is about, or as a toast.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            // The inventory itself is gone (deleted by somebody else, or the person left the workspace): the helper has
            // said so, and there is nothing to show here any more.
            if (error instanceof HttpError && error.status === 404) {
                redirectTo(this.workspacePage);

                return;
            }

            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError) || error.reported) {
                return;
            }

            if (error.status === 422) {
                const messages = firstMessages(error.errors);
                const other = Object.keys(messages).find((name) => name !== 'name' && name !== 'description');

                this.errors = messages;
                this.formError =
                    other !== undefined ? messages[other] : Object.keys(messages).length === 0 ? error.message : '';
            } else {
                notify('danger', error.message);
            }
        },
    };
}
