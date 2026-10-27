/**
 * Alpine component of the field editor of an inventory: the fields in order, to add, change, delete and move.
 *
 * The fields are read from the API (`GET /inventories/{inventory}`, which carries them in order). Adding or changing
 * one happens in a dialog: its name, its type (chosen when adding, then fixed), whether it is required, and the options
 * of the type (see `../fields/options.js`, which says which options a type has and converts them both ways). A select
 * keeps its choices' ids when they are renamed or recolored, so that the values already entered stay attached to
 * them. Deleting a field asks for a confirmation that says its values are erased from every item. Moving a field up
 * or down sends the whole new order, and shows the order the server answers.
 *
 * The endpoints, the types and the currencies (with their names in the language of the page) come from the markup
 * (`data-*` attributes on the root), because the CSP build of Alpine does not allow Blade to inject values into
 * expressions.
 *
 * What a person sees can be older than the data: before the dialog opens on a field the list is read again (a field
 * changed or deleted since, or choices another editor added, must not be overwritten by what this page remembers),
 * and the choices of a select are sent only when the person changed them. A 404 on a change (the field was deleted
 * meanwhile) closes the dialog and reads the list again; if it is the inventory that is gone, that reading takes the
 * person back to the workspace.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated; the others are shown
 * under the input they are about, in the dialog, or as a toast.
 */
import {
    BARCODE_FORMATS,
    barcodeFormatName,
    blankChoice,
    canBeRequired,
    formToOptions,
    optionNames,
    optionsToForm,
    summarizeOptions,
} from '../fields/options.js';
import { COLORS, swatchClass } from '../fields/palette.js';
import { firstMessages, fillUrl, revealFirstRefusal } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';

/** The type a new field starts as. */
const DEFAULT_TYPE = 'text';

/** The errors of the options of the choices (`options.choices.<index>.label`) are read by their index. */
const CHOICE_PREFIX = 'options.choices.';

/**
 * @typedef {import('../fields/types.js').Field} Field
 */

/**
 * @returns {Record<string, any>}
 */
export default function inventoryFields() {
    return {
        /** The fields of the inventory, in order. */
        fields: [],

        /** The types a field can have, with their names: `[{ type, label }]`, from `data-types`. */
        types: [],

        /** The currencies an amount can be in, with their names: `[{ code, name }]`, from `data-currencies`. */
        currencies: [],

        /** True until the fields have been read. */
        loading: true,

        /** True when the last reading of the fields failed: the inventory is not known to have none, the page offers to retry. */
        loadFailed: false,

        /** True while a change is in flight; blocks double submits. */
        busy: false,

        /** What the dialog holds: the field being changed (null while it adds one), its type, name, whether it is required and its options. */
        form: { id: null, type: DEFAULT_TYPE, name: '', required: false, options: optionsToForm(DEFAULT_TYPE) },

        /** The messages of a refused form, by name of the input in the API (`name`, `options.decimals`...). */
        errors: {},

        /** Why the form was refused when it is not about one input ("" when it was not). */
        formError: '',

        /** The field the deletion dialog is about, or null. */
        deleting: null,

        /** The options of the field as they were when the dialog opened on it, to send only what the person changed. */
        opened: null,

        /** Endpoint of the inventory (GET), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Endpoint that adds a field (POST), from `data-fields-url` on the component root. */
        get fieldsUrl() {
            return this.$root.dataset.fieldsUrl;
        },

        /** Endpoint that orders the fields (PUT), from `data-order-url` on the component root. */
        get orderUrl() {
            return this.$root.dataset.orderUrl;
        },

        /** Page of the workspace the inventory belongs to, where the person goes if it was deleted, from `data-workspace-page`. */
        get workspacePage() {
            return this.$root.dataset.workspacePage;
        },

        /** Endpoint of one field, with `__id__` where its ULID goes, from `data-field-url` on the component root. */
        get fieldUrl() {
            return this.$root.dataset.fieldUrl;
        },

        /** Whether the inventory is known to have no field (not when the fields could not be read). */
        get empty() {
            return !this.loading && !this.loadFailed && this.fields.length === 0;
        },

        /** Whether the dialog changes a field (rather than adds one). */
        get editing() {
            return this.form.id !== null;
        },

        /** The title of the dialog. */
        get modalTitle() {
            return t(this.editing ? 'fields.title_edit' : 'fields.title_add');
        },

        /** Whether the form can be submitted: it has a name, and nothing else is in flight. */
        get canSubmit() {
            return !this.busy && this.form.name.trim() !== '';
        },

        /** The question asked before a field is deleted. */
        get confirmText() {
            return this.deleting === null ? '' : t('fields.confirm_delete', { name: this.deleting.name });
        },

        /** The colors a choice can have, with their names, for the picker. */
        get colors() {
            return [
                { value: '', label: t('fields.color.none') },
                ...COLORS.map((color) => ({ value: color, label: t('fields.color.' + color) })),
            ];
        },

        /** The formats a barcode can be limited to, with their names, for the picker. */
        get formats() {
            return BARCODE_FORMATS.map((format) => ({ value: format, label: barcodeFormatName(format) }));
        },

        /** The grades a rating can go up to, for the picker (as text, like the value of the picker). */
        get ratingMaxima() {
            return ['3', '4', '5', '6', '7', '8', '9', '10'];
        },

        /** How many files a photo or file field can hold, for the picker (as text, like the value of the picker). */
        get fileMaxima() {
            return ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
        },

        /** Whether the type picked in the dialog can be required (the photo and file fields cannot: they are filled by uploading). */
        get canRequire() {
            return canBeRequired(this.form.type);
        },

        /**
         * Reads the types and the currencies the markup carries, forgets the form whenever a dialog is closed, and reads the
         * fields when the page opens.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.types = JSON.parse(this.$root.dataset.types ?? '[]');
            this.currencies = JSON.parse(this.$root.dataset.currencies ?? '[]');

            this.$nextTick(() => {
                onModalHidden(this.$refs.fieldModal, () => this.resetDialog());
                onModalHidden(this.$refs.deleteModal, () => {
                    this.deleting = null;
                });
            });

            await this.load();
        },

        /**
         * Reads the fields of the inventory from the API. A failure is remembered (`loadFailed`) until the next reading, so
         * that the page says it could not read the fields instead of claiming the inventory has none.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loadFailed = false;

            try {
                this.fields = (await http.get(this.url)).data.fields ?? [];
            } catch (error) {
                this.loadFailed = true;

                if (error instanceof HttpError && error.status === 404) {
                    // The inventory itself is gone (deleted by somebody else): there is nothing to edit here any more.
                    redirectTo(this.workspacePage);
                } else {
                    this.fail(error);
                }
            } finally {
                this.loading = false;
            }
        },

        /**
         * The name of the type of a field, in the language of the page.
         *
         * @param {Field} field
         * @returns {string}
         */
        typeLabel(field) {
            return this.types.find((type) => type.type === field.type)?.label ?? field.type;
        },

        /**
         * The name of a currency, with its code ("euro (EUR)").
         *
         * @param {string} code
         * @returns {string}
         */
        currencyName(code) {
            const found = this.currencies.find((currency) => currency.code === code);

            return found ? found.name + ' (' + code + ')' : code;
        },

        /**
         * A short account of the options of a field.
         *
         * @param {Field} field
         * @returns {string}
         */
        summary(field) {
            return summarizeOptions(field, (code) => this.currencyName(code));
        },

        /**
         * Whether a field is the first of the list.
         *
         * @param {Field} field
         * @returns {boolean}
         */
        isFirst(field) {
            return this.fields[0]?.id === field.id;
        },

        /**
         * Whether a field is the last of the list.
         *
         * @param {Field} field
         * @returns {boolean}
         */
        isLast(field) {
            return this.fields[this.fields.length - 1]?.id === field.id;
        },

        /**
         * The accessible name of a button that acts on a field: the message with the name of the field in it.
         *
         * @param {string} key The key of the message (`fields.move_up_named`, `fields.edit_named`...).
         * @param {Field} field
         * @returns {string}
         */
        named(key, field) {
            return t(key, { name: field.name });
        },

        /**
         * Moves a field one place up.
         *
         * @param {Field} field
         * @returns {Promise<void>}
         */
        moveUp(field) {
            return this.move(field, -1);
        },

        /**
         * Moves a field one place down.
         *
         * @param {Field} field
         * @returns {Promise<void>}
         */
        moveDown(field) {
            return this.move(field, 1);
        },

        /**
         * Moves a field by some places and sends the new order; the order the server answers is the one shown.
         *
         * @param {Field} field
         * @param {number} places Negative to go up.
         * @returns {Promise<void>}
         */
        async move(field, places) {
            const from = this.fields.findIndex((other) => other.id === field.id);
            const to = from + places;

            if (this.busy || from === -1 || to < 0 || to >= this.fields.length) {
                return;
            }

            const order = this.fields.map((other) => other.id);
            order.splice(to, 0, ...order.splice(from, 1));
            this.busy = true;

            try {
                this.fields = (await http.put(this.orderUrl, { fields: order })).data;
            } catch (error) {
                this.fail(error);
                // The list may have changed under the person's eyes (a field added or deleted): show what the server holds.
                await this.load();
            } finally {
                this.busy = false;
            }
        },

        /**
         * Opens the dialog that adds a field.
         *
         * @returns {void}
         */
        startCreate() {
            this.resetDialog();
            this.opened = null;
            this.form = {
                id: null,
                type: DEFAULT_TYPE,
                name: '',
                required: false,
                options: optionsToForm(DEFAULT_TYPE),
            };
            showModal(this.$refs.fieldModal);
        },

        /**
         * Opens the dialog that changes a field, with its name, requirement and options as the server holds them now: the
         * list is read again first, since this page may be older than a change another editor made (choices added to a
         * select, say, which saving a stale dialog would delete). A field that is gone, or a list that could not be read,
         * opens nothing.
         *
         * @param {Field} field
         * @returns {Promise<void>}
         */
        async startEdit(field) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await this.load();
            } finally {
                this.busy = false;
            }

            const fresh = this.fields.find((other) => other.id === field.id);

            if (this.loadFailed || fresh === undefined) {
                return;
            }

            this.resetDialog();
            this.form = {
                id: fresh.id,
                type: fresh.type,
                name: fresh.name,
                required: fresh.required,
                options: optionsToForm(fresh.type, fresh.options),
            };
            this.opened = optionsToForm(fresh.type, fresh.options);
            showModal(this.$refs.fieldModal);
        },

        /**
         * Forgets the refusals of the form.
         *
         * @returns {void}
         */
        resetDialog() {
            this.errors = {};
            this.formError = '';
        },

        /**
         * Takes the type picked in the dialog: the options start again from the defaults of the new type.
         *
         * @param {Event} event
         * @returns {void}
         */
        selectType(event) {
            this.form.type = /** @type {HTMLSelectElement} */ (event.target).value;
            this.form.options = optionsToForm(this.form.type);
            this.errors = {};
        },

        /**
         * Whether the type picked in the dialog has an option.
         *
         * @param {string} name
         * @returns {boolean}
         */
        hasOption(name) {
            return optionNames(this.form.type).includes(name);
        },

        /**
         * Takes the option picked in a list of the dialog (a currency, a format, the highest grade, the color of a choice).
         *
         * @param {Record<string, any>} target The object that holds the option: the options of the form, or a choice.
         * @param {string} key The name of the option.
         * @param {Event} event
         * @returns {void}
         */
        pick(target, key, event) {
            target[key] = /** @type {HTMLSelectElement} */ (event.target).value;
        },

        /**
         * The classes of the swatch that shows the color of a choice.
         *
         * @param {{ color: string }} choice
         * @returns {string}
         */
        swatch(choice) {
            return swatchClass(choice.color || null);
        },

        /**
         * Adds an empty choice at the end of the list of choices.
         *
         * @returns {void}
         */
        addChoice() {
            this.form.options.choices.push(blankChoice());
        },

        /**
         * Removes a choice from the list; the items that had it lose it.
         *
         * @param {number} index
         * @returns {void}
         */
        removeChoice(index) {
            this.form.options.choices.splice(index, 1);
        },

        /**
         * The accessible name of the button that removes a choice.
         *
         * @param {{ label: string }} choice
         * @returns {string}
         */
        removeChoiceLabel(choice) {
            return choice.label === ''
                ? t('fields.choice.remove')
                : t('fields.choice.remove_named', { name: choice.label });
        },

        /**
         * The message under an input of the form, "" when it was not refused.
         *
         * @param {string} name The name of the input in the API (`name`, `options.decimals`...).
         * @returns {string}
         */
        errorOf(name) {
            return this.errors[name] ?? '';
        },

        /**
         * The classes of an input of the form: marked when it was refused.
         *
         * @param {string} name The name of the input in the API.
         * @returns {string}
         */
        controlClass(name) {
            return this.errors[name] === undefined ? 'form-control' : 'form-control is-invalid';
        },

        /**
         * The classes of a `<select>` of the form: marked when it was refused.
         *
         * @param {string} name The name of the input in the API.
         * @returns {string}
         */
        selectClass(name) {
            return this.errors[name] === undefined ? 'form-select' : 'form-select is-invalid';
        },

        /**
         * The message under a choice: about its label or its color.
         *
         * @param {number} index Position of the choice in the list.
         * @returns {string}
         */
        choiceError(index) {
            return this.errors[CHOICE_PREFIX + index + '.label'] ?? this.errors[CHOICE_PREFIX + index + '.color'] ?? '';
        },

        /**
         * Adds the field, or sends the changes to the one being changed.
         *
         * @returns {Promise<void>}
         */
        async save() {
            if (!this.canSubmit) {
                return;
            }

            // A row added and left empty is not a choice: take it out of the form before it is sent, so the messages
            // of the server (by position) point at the rows that are shown.
            this.form.options.choices = this.form.options.choices.filter(
                (choice) => choice.id !== null || choice.label.trim() !== '',
            );

            if (this.form.options.choices.length === 0) {
                this.form.options.choices.push(blankChoice());
            }

            this.busy = true;
            this.resetDialog();

            const body = {
                name: this.form.name.trim(),
                required: this.canRequire && this.form.required,
                options: formToOptions(this.form.type, this.form.options, this.editing ? this.opened : null),
            };

            try {
                if (this.editing) {
                    await http.patch(fillUrl(this.fieldUrl, this.form.id), body);
                } else {
                    await http.post(this.fieldsUrl, { type: this.form.type, ...body });
                }

                hideModal(this.$refs.fieldModal);
                notify('success', t(this.editing ? 'fields.notice.saved' : 'fields.notice.added'));
                await this.load();
            } catch (error) {
                this.failForm(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Opens the dialog that asks to confirm the deletion of a field.
         *
         * @param {Field} field
         * @returns {void}
         */
        askDelete(field) {
            this.deleting = field;
            showModal(this.$refs.deleteModal);
        },

        /**
         * Deletes the field, once confirmed, with its values in every item.
         *
         * @returns {Promise<void>}
         */
        async confirmDelete() {
            if (this.busy || this.deleting === null) {
                return;
            }

            this.busy = true;

            try {
                await http.delete(fillUrl(this.fieldUrl, this.deleting.id));
                hideModal(this.$refs.deleteModal);
                notify('success', t('fields.notice.deleted'));
                await this.load();
            } catch (error) {
                if (this.isGone(error)) {
                    // Already deleted by somebody else (the helper said so): the list is not what the server holds any more.
                    hideModal(this.$refs.deleteModal);
                    await this.load();
                } else {
                    this.fail(error);
                }
            } finally {
                this.busy = false;
            }
        },

        /**
         * Whether a request failed because what it was about no longer exists (a 404, which the HTTP helper has told).
         *
         * @param {unknown} error
         * @returns {boolean}
         */
        isGone(error) {
            return error instanceof HttpError && error.status === 404;
        },

        /**
         * Tells the person why the form was refused: under the inputs a 422 is about, in the dialog for the refusal to go
         * past the quota, or as a toast.
         *
         * @param {unknown} error
         * @returns {void}
         */
        failForm(error) {
            if (this.isGone(error)) {
                // The field was deleted meanwhile (or the inventory: the reading finds out): close and show what is left.
                hideModal(this.$refs.fieldModal);
                this.load();

                return;
            }

            if (!(error instanceof HttpError) || error.reported) {
                return;
            }

            if (error.status === 422) {
                const messages = firstMessages(error.errors);
                const shown = ['name', 'type', 'required'];
                const other = Object.keys(messages).find(
                    (name) => !shown.includes(name) && !name.startsWith('options.'),
                );

                this.errors = messages;
                this.formError =
                    other !== undefined ? messages[other] : Object.keys(messages).length === 0 ? error.message : '';
                this.revealRefusal();
            } else if (error.status === 409 && error.code === 'field_limit_reached') {
                this.formError = t('fields.error.limit', { count: error.body?.limit ?? '' });
                this.revealRefusal();
            } else {
                notify('danger', error.message);
            }
        },

        /**
         * Brings the first refusal of the dialog into view and puts the cursor on its control, once it has been rendered: the
         * options of a type (the choices of a select, say) can push it outside what the scrolling part of the dialog shows, and
         * "Save" would look as if it did nothing.
         *
         * @returns {void}
         */
        revealRefusal() {
            const dialog = this.$refs.fieldModal;

            this.$nextTick(() => revealFirstRefusal(dialog));
        },

        /**
         * Tells the person why a request failed, unless the HTTP helper already did.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (error instanceof HttpError && !error.reported) {
                notify(
                    'danger',
                    error.status === 422
                        ? (Object.values(firstMessages(error.errors))[0] ?? error.message)
                        : error.message,
                );
            }
        },
    };
}
