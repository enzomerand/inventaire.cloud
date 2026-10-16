/**
 * The part of the "New inventory" dialog that starts an inventory from a template.
 *
 * It is a piece of the `workspaceInventories` component (see `lib/compose.js`), so `this` is the whole component: it uses
 * its form (`form`, `errors`, `formError`), its `canCreate`, its elements (`rootElement`, `modalElement`), its `loadingLabel`
 * and its `fail()`.
 *
 * The dialog is a short path with four steps, all in the same markup (only the current one is shown):
 *
 *     choose -> pick -> preview -> form
 *        \________________________/^
 *                 (from scratch)
 *
 * "Back" walks the path the other way. The catalogue (`GET /templates`) is read the first time the picker opens and kept for
 * the life of the page; a template is read (`GET /templates/{slug}`) when it is selected, to list its fields. The form of a
 * template starts with its name and its summary, which the person may edit; what was typed is kept while they go back to
 * the preview and forward again, and replaced when they pick another template.
 *
 * `?template=<slug>` on the address of the page opens the dialog on the preview of that template ({@link openLinkedTemplate});
 * a slug nobody knows opens the picker, with a notice.
 */
import { fillUrl } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { currentSearch, replaceSearch } from '../lib/location.js';
import { showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { categoriesOf, filterTemplates, isSlug, linkedTemplate, withoutLinkedTemplate } from './catalogue.js';

/** The longest name of an inventory, as the API takes it (the form's own `maxlength`). */
const MAX_NAME = 120;

/** The longest description of an inventory, as the API takes it (the form's own `maxlength`). */
const MAX_DESCRIPTION = 2000;

/**
 * Whether the body of a template answer is a template the preview can work with (the form takes its slug and its name).
 *
 * @param {any} data
 * @returns {boolean}
 */
function isTemplateBody(data) {
    return typeof data?.slug === 'string' && typeof data?.name === 'string';
}

/**
 * @typedef {import('./catalogue.js').Template} Template
 * @typedef {import('./catalogue.js').TemplateSummary} TemplateSummary
 * @typedef {import('./catalogue.js').CategoryChip} CategoryChip
 */

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function templatePicker() {
    return {
        /** Where the person is in the dialog: `choose`, `pick`, `preview` or `form`. */
        step: 'choose',

        /** The catalogue, once read. */
        templates: [],

        /** How reading the catalogue went: `idle` (not asked yet), `loading`, `ready` or `failed`. */
        templatesState: 'idle',

        /** What was typed in the search box of the picker. */
        templateSearch: '',

        /** The category the picker is narrowed to ("" for all). */
        templateCategory: '',

        /** The template on the preview: the line of the catalogue, then the whole template once it was read. Null when none. */
        template: null,

        /** The slug of the template on the preview, which is known before the template itself (a link names it). */
        templateSlug: '',

        /** How reading the template went: `idle`, `loading`, `ready` or `failed`. */
        templateState: 'idle',

        /** The template the form is for (`slug`, `name`), or null when the inventory starts from scratch. */
        formTemplate: null,

        /**
         * Counts the readings of a template, so that the answer to an older one is dropped: one since replaced, or made
         * useless because the dialog was closed or the person went on to the form.
         */
        templateRequest: 0,

        /** The element of the dialog, kept when the page opens (see `modalElement`); null until then. */
        modalNode: null,

        /**
         * The dialog. Taken when the page opens rather than asked of Alpine each time, for the same reason as the root (see
         * `rootElement` of `inventoryItems`): a call made from the scope of an element that has left the page cannot ask.
         */
        get modalElement() {
            return this.modalNode ?? this.$refs.modal;
        },

        /** Endpoint of the catalogue (GET), from `data-templates-url` on the component root. */
        get templatesUrl() {
            return this.rootElement.dataset.templatesUrl;
        },

        /** Endpoint of a template (GET), with `__id__` where its slug goes, from `data-template-url` on the component root. */
        get templateUrl() {
            return this.rootElement.dataset.templateUrl;
        },

        /** Whether the first choice (from scratch, or from a template) is on screen. */
        get onChoose() {
            return this.step === 'choose';
        },

        /** Whether the picker is on screen. */
        get onPick() {
            return this.step === 'pick';
        },

        /** Whether the preview of a template is on screen. */
        get onPreview() {
            return this.step === 'preview';
        },

        /** Whether the form is on screen. */
        get onForm() {
            return this.step === 'form';
        },

        /**
         * The classes the dialog needs for the step: a grid of templates or a list of fields needs room, so the dialog is wider
         * and scrolls inside its own frame, where the buttons stay in reach.
         */
        get dialogClass() {
            return this.onPick || this.onPreview ? 'modal-lg modal-dialog-scrollable' : '';
        },

        /** Whether the form is for a template. */
        get fromTemplate() {
            return this.formTemplate !== null;
        },

        /** The sentence that says which template the form is for ("" when it starts from scratch). */
        get fromTemplateLabel() {
            return this.formTemplate === null ? '' : t('templates.based_on', { name: this.formTemplate.name });
        },

        /** Whether the catalogue is being read. */
        get templatesLoading() {
            return this.templatesState === 'loading';
        },

        /** Whether the catalogue could not be read. */
        get templatesFailed() {
            return this.templatesState === 'failed';
        },

        /** Whether the catalogue was read. */
        get templatesReady() {
            return this.templatesState === 'ready';
        },

        /** Whether the catalogue was read and has nothing in it. */
        get templatesEmpty() {
            return this.templatesReady && this.templates.length === 0;
        },

        /** The templates that match the search and the category. */
        get visibleTemplates() {
            return filterTemplates(this.templates, this.templateSearch, this.templateCategory);
        },

        /** Whether the catalogue has templates, and none of them matches the search and the category. */
        get templatesNoMatch() {
            return this.templatesReady && this.templates.length > 0 && this.visibleTemplates.length === 0;
        },

        /** The buttons that narrow the picker to a category: "all", then each category of the catalogue. */
        get categoryChips() {
            return [{ category: '', label: t('templates.all') }, ...categoriesOf(this.templates)];
        },

        /** Whether the category buttons are worth showing: the catalogue has more than one category. */
        get showCategories() {
            return this.templatesReady && categoriesOf(this.templates).length > 1;
        },

        /** What the screen readers hear when the search or the category changes: how many templates are left. */
        get templatesAnnouncement() {
            return this.templatesReady && this.templates.length > 0
                ? tn('templates.results', this.visibleTemplates.length)
                : '';
        },

        /** The name of the template on the preview ("" while a link is still being read). */
        get previewName() {
            return this.template?.name ?? '';
        },

        /** The summary of the template on the preview. */
        get previewSummary() {
            return this.template?.summary ?? '';
        },

        /** The category of the template on the preview, by its label. */
        get previewCategory() {
            return this.template?.category_label ?? '';
        },

        /** The fields of the template on the preview (none until it was read). */
        get previewFields() {
            return this.template?.fields ?? [];
        },

        /** How many fields the template on the preview has. */
        get previewFieldCount() {
            return this.previewFields.length;
        },

        /** Whether the template on the preview is being read. */
        get previewLoading() {
            return this.templateState === 'loading';
        },

        /** Whether the template on the preview could not be read. */
        get previewFailed() {
            return this.templateState === 'failed';
        },

        /** Whether the preview can go on to the form: there is a template (its fields may still be on their way). */
        get canUseTemplate() {
            return (this.template ?? null) !== null;
        },

        /** What the screen readers hear while the catalogue is being read ("" otherwise). Its region is always on the page. */
        get templatesLoadingAnnouncement() {
            return this.templatesLoading ? this.loadingLabel : '';
        },

        /** What the screen readers hear while the template on the preview is being read ("" otherwise). */
        get previewLoadingAnnouncement() {
            return this.previewLoading ? this.loadingLabel : '';
        },

        /**
         * The line under a template of the picker that says how many fields it has.
         *
         * @param {TemplateSummary} entry
         * @returns {string}
         */
        fieldsLabel(entry) {
            return tn('inventories.fields', entry.fields_count);
        },

        /**
         * The value of `aria-pressed` for a category button.
         *
         * @param {CategoryChip} chip
         * @returns {string}
         */
        chipPressed(chip) {
            return chip.category === this.templateCategory ? 'true' : 'false';
        },

        /**
         * The look of a category button: filled when it is the one in force.
         *
         * @param {CategoryChip} chip
         * @returns {string}
         */
        chipClass(chip) {
            return chip.category === this.templateCategory ? 'btn-primary' : 'btn-outline-secondary';
        },

        /**
         * Narrows the picker to a category.
         *
         * @param {CategoryChip} chip
         * @returns {void}
         */
        chooseCategory(chip) {
            this.templateCategory = chip.category;
        },

        /**
         * Goes to a step of the dialog, and puts the cursor where it starts (the control marked `data-autofocus` in the
         * markup of the step). What the last step refused is forgotten.
         *
         * @param {'choose' | 'pick' | 'preview' | 'form'} step
         * @returns {void}
         */
        goTo(step) {
            this.step = step;
            this.errors = {};
            this.formError = '';
            this.$nextTick(() => this.focusStep());
        },

        /**
         * Puts the cursor in the control the current step starts with.
         *
         * @returns {void}
         */
        focusStep() {
            this.modalElement?.querySelector(`[data-step="${this.step}"] [data-autofocus]`)?.focus();
        },

        /**
         * Goes one step back along the path.
         *
         * @returns {void}
         */
        back() {
            if (this.busy) {
                return;
            }

            if (this.step === 'form') {
                if (this.formTemplate === null) {
                    this.goTo('choose');
                } else if (this.templateState === 'ready') {
                    this.goTo('preview');
                } else {
                    // The answer that was on its way when the form was opened was dropped (see `useTemplate`): ask again,
                    // or the preview would stay on its spinner.
                    void this.retryTemplate();
                }
            } else if (this.step === 'preview') {
                // An answer still on its way is of no use any more. The catalogue may not have been read yet (a link opened
                // the preview), and the picker is empty without it.
                this.templateRequest++;
                this.goTo('pick');
                this.loadTemplates();
            } else if (this.step === 'pick') {
                this.goTo('choose');
            }
        },

        /**
         * Starts an empty inventory: on to the form, with nothing in it (what a template had put there is taken out).
         *
         * @returns {void}
         */
        startFromScratch() {
            if (this.formTemplate !== null) {
                this.form = { name: '', description: '' };
                this.formTemplate = null;
            }

            this.goTo('form');
        },

        /**
         * Starts an inventory from a template: on to the picker, and the catalogue is read if it was not yet.
         *
         * @returns {Promise<void>}
         */
        async startFromTemplate() {
            this.goTo('pick');
            await this.loadTemplates();
        },

        /**
         * Reads the catalogue, once per page: nothing is asked when it was read or is being read. A failure is remembered
         * (`templatesFailed`) until the next reading, which the picker offers.
         *
         * @returns {Promise<void>}
         */
        async loadTemplates() {
            if (this.templatesState === 'loading' || this.templatesState === 'ready') {
                return;
            }

            this.templatesState = 'loading';
            // The notice that offers to try again is hidden now, and the button the person pressed with it: the cursor
            // would fall to the page behind the dialog.
            this.$nextTick(() => this.focusStep());

            try {
                const answer = await http.get(this.templatesUrl);

                this.templates = Array.isArray(answer?.data) ? answer.data : [];
                this.templatesState = 'ready';
            } catch (error) {
                this.templatesState = 'failed';
                this.fail(error);
            }
        },

        /**
         * Shows the preview of a template of the picker.
         *
         * @param {TemplateSummary} entry
         * @returns {Promise<void>}
         */
        async selectTemplate(entry) {
            await this.showTemplate(entry.slug, entry);
        },

        /**
         * Reads the template on the preview again, after a failure.
         *
         * @returns {Promise<void>}
         */
        async retryTemplate() {
            await this.showTemplate(this.templateSlug, this.template);
        },

        /**
         * Shows the preview of a template and reads it, for its fields. What is already known of it (its line in the
         * catalogue) is shown meanwhile. A template the server does not know (it was removed since the catalogue was read,
         * or a link names one that never existed) sends the person back to the picker, with a notice.
         *
         * @param {string} slug
         * @param {TemplateSummary | null} [known] What the catalogue says of it, when it was picked from there.
         * @returns {Promise<void>}
         */
        async showTemplate(slug, known = null) {
            const request = ++this.templateRequest;

            this.template = known;
            this.templateSlug = slug;
            this.templateState = 'loading';
            this.goTo('preview');

            try {
                // Silent: a 404 is told in its own words below.
                const answer = await http.get(fillUrl(this.templateUrl, slug), { silent: true });

                if (request === this.templateRequest) {
                    const data = answer?.data ?? null;

                    if (isTemplateBody(data)) {
                        this.template = data;
                        this.templateState = 'ready';
                    } else {
                        // An answer with no template in it is no answer: what the catalogue says of it stays, with the
                        // offer to read it again.
                        this.templateState = 'failed';
                    }
                }
            } catch (error) {
                if (request !== this.templateRequest) {
                    return;
                }

                if (error instanceof HttpError && error.status === 404) {
                    await this.templateGone();

                    return;
                }

                this.templateState = 'failed';
                this.fail(error);
            }
        },

        /**
         * Tells that a template does not exist, and goes back to the picker with a catalogue read again.
         *
         * @returns {Promise<void>}
         */
        async templateGone() {
            notify('warning', t('templates.error.unknown'));

            this.template = null;
            this.templateSlug = '';
            this.templateState = 'idle';
            this.templatesState = 'idle';

            await this.startFromTemplate();
        },

        /**
         * Goes on from the preview to the form, with the name and the description the template proposes. What was already
         * typed for this very template (the person came back to look at the fields) is left as it is.
         *
         * @returns {void}
         */
        useTemplate() {
            if ((this.template ?? null) === null) {
                return;
            }

            // The template can be used while its fields are still being read; that answer, whatever it is (a 404 would send
            // the person back to the picker), has nothing to say to the form.
            this.templateRequest++;

            if (this.formTemplate?.slug !== this.template.slug) {
                this.form = {
                    name: this.template.name.slice(0, MAX_NAME),
                    description: (this.template.summary ?? '').slice(0, MAX_DESCRIPTION),
                };
            }

            this.formTemplate = { slug: this.template.slug, name: this.template.name };
            this.goTo('form');
        },

        /**
         * Forgets the path taken: the dialog starts from its first step again. The catalogue is kept.
         *
         * @returns {void}
         */
        resetPicker() {
            this.templateRequest++;
            this.step = 'choose';
            this.templateSearch = '';
            this.templateCategory = '';
            this.template = null;
            this.templateSlug = '';
            this.templateState = 'idle';
            this.formTemplate = null;
        },

        /**
         * Opens the dialog on the template the address of the page names (`?template=<slug>`), and takes the parameter out
         * of the address so that a reload does not open it again. Whoever may not add inventories gets nothing but the
         * cleaner address. A slug that cannot be one, or that the server does not know, opens the picker with a notice.
         *
         * @returns {Promise<void>}
         */
        async openLinkedTemplate() {
            const slug = linkedTemplate(currentSearch());

            if (slug === null) {
                return;
            }

            replaceSearch(withoutLinkedTemplate(currentSearch()));

            if (!this.canCreate) {
                return;
            }

            this.resetForm();
            // The page was just opened, so nothing has the cursor, and the dialog would have nothing to give it back to when
            // it closes: the button that adds an inventory is where the person would have come from.
            this.$refs.newInventory?.focus();
            showModal(this.modalElement);

            if (isSlug(slug)) {
                await this.showTemplate(slug);
            } else {
                notify('warning', t('templates.error.unknown'));
                await this.startFromTemplate();
            }
        },
    };
}
