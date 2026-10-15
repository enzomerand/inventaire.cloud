/**
 * Alpine component of the page of a workspace: its inventories as cards, and the form that adds one.
 *
 * The list is read from the API (`GET /workspaces/{workspace}/inventories`, by name, each with its counts). People who
 * may edit the workspace (the page says so in `data-can-create`) get a "New inventory" button and a dialog; once the
 * inventory is created the person is taken to its page, where its fields are next. The endpoint and the link of a card
 * come from the markup (`data-url`, `data-inventory-url` on the root), because the CSP build of Alpine does not allow
 * Blade to inject values into expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * under the form that caused them, or as a toast.
 */
import { firstMessages, fillUrl } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';

/**
 * @typedef {object} InventorySummary An inventory, as `InventoryResource` describes it in a list.
 * @property {string} id
 * @property {string} name
 * @property {string | null} description
 * @property {number} fields_count
 * @property {number} items_count
 */

/**
 * @returns {Record<string, any>}
 */
export default function workspaceInventories() {
    return {
        /** The inventories of the workspace. */
        items: [],

        /** True while the list is being read (and until it was, so that the empty message does not flash). */
        loading: true,

        /** True while the creation request is in flight; blocks double submits. */
        busy: false,

        /** What was typed in the creation dialog. */
        form: { name: '', description: '' },

        /** The messages of a refused form, by input (`name`, `description`). */
        errors: {},

        /** Why the form was refused when it is not about one input ("" when it was not). */
        formError: '',

        /** Endpoint of the inventories of the workspace (GET, POST), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Page of an inventory, with `__id__` where its ULID goes, from `data-inventory-url` on the component root. */
        get inventoryUrl() {
            return this.$root.dataset.inventoryUrl;
        },

        /** Whether the person may add inventories to the workspace, from `data-can-create` on the component root. */
        get canCreate() {
            return this.$root.dataset.canCreate === '1';
        },

        /** Whether the workspace is known to hold no inventory. */
        get empty() {
            return !this.loading && this.items.length === 0;
        },

        /** Whether the creation form can be submitted: it has a name, and nothing else is in flight. */
        get canSubmit() {
            return !this.busy && this.form.name.trim() !== '';
        },

        /**
         * Reads the inventories when the page opens, and forgets the form whenever the dialog is closed.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.$nextTick(() => onModalHidden(this.$refs.modal, () => this.resetForm()));

            await this.load();
        },

        /**
         * Reads the inventories of the workspace from the API.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;

            try {
                this.items = (await http.get(this.url)).data;
            } catch (error) {
                this.fail(error);
            } finally {
                this.loading = false;
            }
        },

        /**
         * Where the page of an inventory is: its card links there.
         *
         * @param {InventorySummary} item
         * @returns {string}
         */
        pageUrl(item) {
            return fillUrl(this.inventoryUrl, item.id);
        },

        /**
         * What an inventory holds, as a sentence ("12 items · 4 fields").
         *
         * @param {InventorySummary} item
         * @returns {string}
         */
        countsLabel(item) {
            return tn('inventories.items', item.items_count) + ' · ' + tn('inventories.fields', item.fields_count);
        },

        /**
         * Opens the dialog that adds an inventory.
         *
         * @returns {void}
         */
        startCreate() {
            this.resetForm();
            showModal(this.$refs.modal);
        },

        /**
         * Forgets what was typed and the refusals.
         *
         * @returns {void}
         */
        resetForm() {
            this.form = { name: '', description: '' };
            this.errors = {};
            this.formError = '';
        },

        /**
         * Creates the inventory, then takes the person to its page.
         *
         * @returns {Promise<void>}
         */
        async create() {
            if (!this.canSubmit) {
                return;
            }

            this.busy = true;
            this.errors = {};
            this.formError = '';

            try {
                const description = this.form.description.trim();
                const answer = await http.post(this.url, {
                    name: this.form.name.trim(),
                    description: description === '' ? null : description,
                });

                hideModal(this.$refs.modal);
                redirectTo(this.pageUrl(answer.data));
            } catch (error) {
                this.busy = false;
                this.fail(error);
            }
        },

        /**
         * Tells the person why a request failed, unless the HTTP helper already did: under the input a 422 is about, or in
         * the dialog for the refusal to go past the quota.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
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
            } else if (error.status === 409 && error.code === 'inventory_limit_reached') {
                this.formError = t('inventories.error.limit', { count: error.body?.limit ?? '' });
            } else {
                notify('danger', error.message);
            }
        },
    };
}
