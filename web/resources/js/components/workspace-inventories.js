/**
 * Alpine component of the page of a workspace: its inventories as cards, and the dialog that adds one.
 *
 * The list is read from the API (`GET /workspaces/{workspace}/inventories`, by name, each with its counts). People who
 * may edit the workspace (the page says so in `data-can-create`) get a "New inventory" button and a dialog; once the
 * inventory is created the person is taken to its page, where its fields are next. The dialog starts from scratch (a name
 * and a description) or from a template (see `inventory-templates/picker.js`, which this component is merged with), and
 * `?template=<slug>` on the address opens it on a template. The endpoints and the link of a card come from the markup
 * (`data-url`, `data-inventory-url`, `data-templates-url`, `data-template-url` on the root, and `data-loading-label` for
 * the words the live regions say), because the CSP build of Alpine does not allow Blade to inject values into expressions.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * under the form that caused them, or as a toast.
 */
import { compose } from '../lib/compose.js';
import { firstMessages, fillUrl } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';
import templatePicker from '../inventory-templates/picker.js';

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
    return compose(templatePicker(), {
        /** The inventories of the workspace. */
        items: [],

        /** True while the list is being read (and until it was, so that the empty message does not flash). */
        loading: true,

        /** True when the last reading of the list failed: the workspace is not known to be empty, the page offers to retry. */
        loadFailed: false,

        /** True while the creation request is in flight; blocks double submits. */
        busy: false,

        /** What was typed in the creation dialog. */
        form: { name: '', description: '' },

        /** The messages of a refused form, by input (`name`, `description`). */
        errors: {},

        /** Why the form was refused when it is not about one input ("" when it was not). */
        formError: '',

        /** The element the component is set on, kept when the page opens (see `rootElement`); null until then. */
        rootNode: null,

        /**
         * The element the component is set on, which carries the endpoints and the abilities (`data-*`). Taken when the page
         * opens rather than asked of Alpine each time: Alpine stops answering `$root` to a call made from the scope of an
         * element that has been taken out of the page since, and a reading that such a call started still needs them.
         */
        get rootElement() {
            return this.rootNode ?? this.$root;
        },

        /** Endpoint of the inventories of the workspace (GET, POST), from `data-url` on the component root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /** Page of an inventory, with `__id__` where its ULID goes, from `data-inventory-url` on the component root. */
        get inventoryUrl() {
            return this.rootElement.dataset.inventoryUrl;
        },

        /** Whether the person may add inventories to the workspace, from `data-can-create` on the component root. */
        get canCreate() {
            return this.rootElement.dataset.canCreate === '1';
        },

        /** The word that says something is being read ("Loading…"), from `data-loading-label` on the component root. */
        get loadingLabel() {
            return this.rootElement.dataset.loadingLabel ?? '';
        },

        /** What the screen readers hear while the list is being read ("" otherwise). Its region is always on the page. */
        get loadingAnnouncement() {
            return this.loading ? this.loadingLabel : '';
        },

        /** Whether the workspace is known to hold no inventory (not when the list could not be read). */
        get empty() {
            return !this.loading && !this.loadFailed && this.items.length === 0;
        },

        /** Whether the creation form can be submitted: it has a name, and nothing else is in flight. */
        get canSubmit() {
            return !this.busy && this.form.name.trim() !== '';
        },

        /**
         * Reads the inventories when the page opens, forgets the form whenever the dialog is closed, and opens the dialog on
         * the template the address names, if it names one. The dialog is not there yet when the component starts, hence the
         * wait for the next tick.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.rootNode = this.$root;
            this.$nextTick(() => {
                this.modalNode = this.$refs.modal;
                onModalHidden(this.modalElement, () => this.resetForm());
                this.openLinkedTemplate();
            });

            await this.load();
        },

        /**
         * Reads the inventories of the workspace from the API. A failure is remembered (`loadFailed`) until the next
         * reading, so that the page says it could not read the list instead of claiming the workspace is empty.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;
            this.loadFailed = false;

            try {
                this.items = (await http.get(this.url)).data;
            } catch (error) {
                this.loadFailed = true;
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
         * Opens the dialog that adds an inventory, on its first step.
         *
         * @returns {void}
         */
        startCreate() {
            this.resetForm();
            showModal(this.modalElement);
        },

        /**
         * Forgets what was typed, the refusals and the path taken in the dialog.
         *
         * @returns {void}
         */
        resetForm() {
            this.form = { name: '', description: '' };
            this.errors = {};
            this.formError = '';
            this.resetPicker();
        },

        /**
         * What the form does when it is submitted: only its own step creates the inventory (the other steps hold no form
         * control that could submit it, but the Enter key must never get there).
         *
         * @returns {Promise<void>}
         */
        async submit() {
            if (this.step === 'form') {
                await this.create();
            }
        },

        /**
         * Creates the inventory, from the template the form is for if there is one, then takes the person to its page.
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
                const payload = {
                    name: this.form.name.trim(),
                    description: description === '' ? null : description,
                };

                if (this.formTemplate !== null) {
                    payload.template = this.formTemplate.slug;
                }

                const answer = await http.post(this.url, payload);

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
            } else if (error.status === 409 && error.code === 'field_limit_reached') {
                this.formError = t('fields.error.limit', { count: error.body?.limit ?? '' });
            } else {
                notify('danger', error.message);
            }
        },
    });
}
