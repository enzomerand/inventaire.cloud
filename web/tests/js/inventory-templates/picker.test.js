import { beforeEach, describe, expect, it, vi } from 'vitest';

import workspaceInventories from '../../../resources/js/components/workspace-inventories.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { deferred } from '../support/inventory.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const TEMPLATES_URL = '/api/v1/templates';
const TEMPLATE_URL = '/api/v1/templates/__id__';

/** A line of the catalogue. */
function summary(slug, overrides = {}) {
    return {
        slug,
        category: 'home',
        category_label: 'Home',
        icon: 'home',
        name: 'Template ' + slug,
        summary: 'Summary of ' + slug,
        fields_count: 2,
        url: '/templates/' + slug,
        ...overrides,
    };
}

/** A template with its fields. */
function detail(slug, overrides = {}) {
    return {
        ...summary(slug),
        description: ['A paragraph.'],
        fields: [{ key: 'name', name: 'Name', type: 'text', type_label: 'Text', required: true, options: {} }],
        examples: [],
        ...overrides,
    };
}

describe('the template picker of the new inventory dialog', () => {
    let component;
    let modal;
    let focused;

    beforeEach(() => {
        // `clearMocks` forgets the calls, not what a mock answers: start every test from a mock that answers nothing.
        http.get.mockReset();
        http.post.mockReset();
        window.history.replaceState(null, '', '/');
        focused = [];
        modal = document.createElement('div');
        modal.innerHTML = `
            <div data-step="choose"><button data-autofocus></button></div>
            <div data-step="pick"><input data-autofocus></div>
            <div data-step="preview"><h3 tabindex="-1" data-autofocus></h3></div>
            <div data-step="form"><input data-autofocus></div>
        `;
        document.body.replaceChildren(modal);

        for (const control of modal.querySelectorAll('[data-autofocus]')) {
            control.addEventListener('focus', () => focused.push(control.closest('[data-step]').dataset.step));
        }

        component = workspaceInventories();
        component.$root = {
            dataset: {
                url: '/api/v1/workspaces/shop/inventories',
                inventoryUrl: '/app/w/shop/inventories/__id__',
                canCreate: '1',
                templatesUrl: TEMPLATES_URL,
                templateUrl: TEMPLATE_URL,
                loadingLabel: 'Loading…',
            },
        };
        component.$refs = { modal };
        component.$nextTick = (callback) => callback();
    });

    describe('the steps', () => {
        it('starts on the first choice', () => {
            expect(component.step).toBe('choose');
            expect(component.onChoose).toBe(true);
            expect(component.onPick || component.onPreview || component.onForm).toBe(false);
            expect(component.dialogClass).toBe('');
        });

        it('widens the dialog for the steps that need room', () => {
            component.step = 'pick';
            expect(component.dialogClass).toBe('modal-lg modal-dialog-scrollable');

            component.step = 'preview';
            expect(component.dialogClass).toBe('modal-lg modal-dialog-scrollable');

            component.step = 'form';
            expect(component.dialogClass).toBe('');
        });

        it('puts the cursor where each step starts', () => {
            component.goTo('pick');
            component.goTo('preview');
            component.goTo('form');
            component.goTo('choose');

            expect(focused).toEqual(['pick', 'preview', 'form', 'choose']);
        });

        it('forgets what the last step refused when it changes', () => {
            component.errors = { name: 'Taken.' };
            component.formError = 'Nope.';

            component.goTo('form');

            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
        });

        it('goes to the empty form from scratch, and back to the first choice', () => {
            component.startFromScratch();

            expect(component.step).toBe('form');
            expect(component.fromTemplate).toBe(false);
            expect(component.fromTemplateLabel).toBe('');

            component.back();

            expect(component.step).toBe('choose');

            component.back();

            expect(component.step).toBe('choose');
        });

        it('does not go back while the inventory is being created', () => {
            component.startFromScratch();
            component.busy = true;

            component.back();

            expect(component.step).toBe('form');
        });

        it('starts every opening of the dialog from the first step, with the catalogue kept', async () => {
            http.get.mockResolvedValue({ data: [summary('a')] });
            await component.startFromTemplate();
            component.templateSearch = 'abc';
            component.templateCategory = 'home';

            component.startCreate();

            expect(showModal).toHaveBeenCalledWith(modal);
            expect(component.step).toBe('choose');
            expect(component.templateSearch).toBe('');
            expect(component.templateCategory).toBe('');
            expect(component.templates).toHaveLength(1);
            expect(component.templatesReady).toBe(true);
        });
    });

    describe('the catalogue', () => {
        it('is read when the picker opens', async () => {
            http.get.mockResolvedValue({ data: [summary('a'), summary('b')] });

            const reading = component.startFromTemplate();

            expect(component.step).toBe('pick');
            expect(component.templatesLoading).toBe(true);

            await reading;

            expect(http.get).toHaveBeenCalledWith(TEMPLATES_URL);
            expect(component.templatesReady).toBe(true);
            expect(component.visibleTemplates.map((entry) => entry.slug)).toEqual(['a', 'b']);
        });

        it('is read once: neither when it is being read nor when it was', async () => {
            const reading = deferred();
            http.get.mockReturnValue(reading.promise);

            const first = component.loadTemplates();
            await component.loadTemplates();

            expect(http.get).toHaveBeenCalledTimes(1);

            reading.resolve({ data: [summary('a')] });
            await first;
            await component.startFromTemplate();

            expect(http.get).toHaveBeenCalledTimes(1);
        });

        it('is read again after a failure, which is shown unless the HTTP helper already did', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server down'));

            await component.startFromTemplate();

            expect(component.templatesFailed).toBe(true);
            expect(component.templatesReady).toBe(false);
            expect(notify).toHaveBeenCalledWith('danger', 'Server down');

            const reported = new HttpError(0, 'Network down', { kind: 'network' });
            reported.reported = true;
            http.get.mockRejectedValueOnce(reported);
            notify.mockClear();

            await component.loadTemplates();

            expect(component.templatesFailed).toBe(true);
            expect(notify).not.toHaveBeenCalled();

            http.get.mockResolvedValueOnce({ data: [summary('a')] });
            await component.loadTemplates();

            expect(component.templatesFailed).toBe(false);
            expect(component.templatesReady).toBe(true);
            expect(component.templates).toHaveLength(1);
        });

        it('puts the cursor back in the picker when it is read again, the button that asked for it being hidden by then', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server down'));
            await component.startFromTemplate();

            // The "Try again" button of the failure notice, which the notice hides as soon as the reading starts.
            const retry = document.createElement('button');
            modal.querySelector('[data-step="pick"]').append(retry);
            retry.focus();
            http.get.mockResolvedValueOnce({ data: [summary('a')] });

            await component.loadTemplates();

            expect(document.activeElement).toBe(modal.querySelector('[data-step="pick"] [data-autofocus]'));
        });

        it('is taken for empty when the server answers anything but a list', async () => {
            http.get.mockResolvedValue({ data: null });

            await component.loadTemplates();

            expect(component.templatesEmpty).toBe(true);
            expect(component.visibleTemplates).toEqual([]);
        });

        it('knows when nothing matches the search', async () => {
            http.get.mockResolvedValue({ data: [summary('a')] });
            await component.loadTemplates();

            expect(component.templatesNoMatch).toBe(false);

            component.templateSearch = 'zzz';

            expect(component.templatesNoMatch).toBe(true);
            expect(component.templatesEmpty).toBe(false);
        });

        it('announces its reading to the screen readers, and nothing otherwise', async () => {
            const reading = deferred();
            http.get.mockReturnValue(reading.promise);

            expect(component.templatesLoadingAnnouncement).toBe('');

            const first = component.loadTemplates();

            expect(component.templatesLoadingAnnouncement).toBe('Loading…');

            reading.resolve({ data: [summary('a')] });
            await first;

            expect(component.templatesLoadingAnnouncement).toBe('');
        });

        it('counts the fields of a template', () => {
            expect(component.fieldsLabel(summary('a', { fields_count: 1 }))).toBe('1 field');
            expect(component.fieldsLabel(summary('a', { fields_count: 12 }))).toBe('12 fields');
        });
    });

    describe('the category buttons', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({
                data: [
                    summary('a'),
                    summary('b', { category: 'it', category_label: 'IT' }),
                    summary('c', { category: 'it', category_label: 'IT' }),
                ],
            });
            await component.loadTemplates();
        });

        it('lists all the categories first, then each of the catalogue', () => {
            expect(component.showCategories).toBe(true);
            expect(component.categoryChips).toEqual([
                { category: '', label: 'All templates' },
                { category: 'home', label: 'Home' },
                { category: 'it', label: 'IT' },
            ]);
        });

        it('presses one, and says it is pressed', () => {
            const [all, home, it] = component.categoryChips;

            expect(component.chipPressed(all)).toBe('true');
            expect(component.chipClass(all)).toBe('btn-primary');
            expect(component.chipPressed(home)).toBe('false');
            expect(component.chipClass(home)).toBe('btn-outline-secondary');

            component.chooseCategory(it);

            expect(component.chipPressed(it)).toBe('true');
            expect(component.chipPressed(all)).toBe('false');
            expect(component.visibleTemplates.map((entry) => entry.slug)).toEqual(['b', 'c']);
        });

        it('are not shown for a single category', async () => {
            component.templates = [summary('a')];

            expect(component.showCategories).toBe(false);
        });

        it('tell how many templates are left', () => {
            expect(component.templatesAnnouncement).toBe('3 templates');

            component.templateSearch = 'Summary of b';

            expect(component.templatesAnnouncement).toBe('1 template');
        });

        it('tell nothing while there is nothing to count', () => {
            component.templatesState = 'loading';

            expect(component.templatesAnnouncement).toBe('');

            component.templatesState = 'ready';
            component.templates = [];

            expect(component.templatesAnnouncement).toBe('');
        });
    });

    describe('the preview', () => {
        it('shows what the catalogue says at once, then the whole template once it was read', async () => {
            const reading = deferred();
            http.get.mockReturnValue(reading.promise);
            const entry = summary('a');

            const selecting = component.selectTemplate(entry);

            expect(component.step).toBe('preview');
            expect(component.previewName).toBe('Template a');
            expect(component.previewSummary).toBe('Summary of a');
            expect(component.previewCategory).toBe('Home');
            expect(component.previewLoading).toBe(true);
            expect(component.previewFields).toEqual([]);
            expect(component.canUseTemplate).toBe(true);
            expect(http.get).toHaveBeenCalledWith('/api/v1/templates/a', { silent: true });

            reading.resolve({ data: detail('a') });
            await selecting;

            expect(component.previewLoading).toBe(false);
            expect(component.previewFieldCount).toBe(1);
            expect(component.previewFields[0].name).toBe('Name');
        });

        it('announces the reading of the template to the screen readers, and nothing otherwise', async () => {
            const reading = deferred();
            http.get.mockReturnValue(reading.promise);

            expect(component.previewLoadingAnnouncement).toBe('');

            const selecting = component.selectTemplate(summary('a'));

            expect(component.previewLoadingAnnouncement).toBe('Loading…');

            reading.resolve({ data: detail('a') });
            await selecting;

            expect(component.previewLoadingAnnouncement).toBe('');
        });

        it('has nothing to show or use while a link is being read', async () => {
            http.get.mockReturnValue(new Promise(() => {}));

            component.showTemplate('a');

            expect(component.step).toBe('preview');
            expect(component.previewName).toBe('');
            expect(component.previewCategory).toBe('');
            expect(component.canUseTemplate).toBe(false);

            component.useTemplate();

            expect(component.step).toBe('preview');
        });

        it('encodes the slug in the address it reads', async () => {
            http.get.mockResolvedValue({ data: detail('a') });

            await component.showTemplate('a b');

            expect(http.get).toHaveBeenCalledWith('/api/v1/templates/a%20b', { silent: true });
        });

        it('says it could not be read, and reads it again, from the same slug', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server down'));

            await component.showTemplate('a');

            expect(component.previewFailed).toBe(true);
            expect(component.previewLoading).toBe(false);
            expect(notify).toHaveBeenCalledWith('danger', 'Server down');

            http.get.mockResolvedValueOnce({ data: detail('a') });
            await component.retryTemplate();

            expect(component.previewFailed).toBe(false);
            expect(component.previewFieldCount).toBe(1);
            expect(http.get).toHaveBeenLastCalledWith('/api/v1/templates/a', { silent: true });
        });

        it('stays silent about a request that was cancelled', async () => {
            http.get.mockRejectedValueOnce(new DOMException('Aborted', 'AbortError'));

            await component.showTemplate('a');

            expect(notify).not.toHaveBeenCalled();
        });

        it('ignores the answer to a template the person has left', async () => {
            const slow = deferred();
            http.get.mockImplementation(async (url) => {
                if (url.endsWith('/a')) {
                    return slow.promise;
                }

                return url.endsWith('/b') ? { data: detail('b', { name: 'B' }) } : { data: [] };
            });
            const selecting = component.selectTemplate(summary('a'));

            component.back();
            await component.selectTemplate(summary('b'));

            slow.resolve({ data: detail('a', { name: 'A, late' }) });
            await selecting;

            expect(component.previewName).toBe('B');
            expect(component.previewFieldCount).toBe(1);
        });

        it('ignores the answer that comes once the dialog was closed', async () => {
            const slow = deferred();
            http.get.mockReturnValueOnce(slow.promise);
            const selecting = component.selectTemplate(summary('a'));

            component.resetForm();
            slow.resolve({ data: detail('a') });
            await selecting;

            expect(component.step).toBe('choose');
            expect(component.template).toBeNull();
            expect(component.previewLoading).toBe(false);
        });

        it.each([
            ['an empty answer', {}],
            ['an answer with no template', { data: null }],
            ['a template with no name', { data: { slug: 'a' } }],
        ])(
            'says it could not be read when the server sends %s, and never throws on "Use this template"',
            async (_label, answer) => {
                http.get.mockResolvedValueOnce(answer);

                await component.showTemplate('a');

                expect(component.previewFailed).toBe(true);
                expect(component.previewLoading).toBe(false);
                expect(component.canUseTemplate).toBe(false);
                expect(() => component.useTemplate()).not.toThrow();
                expect(component.step).toBe('preview');
            },
        );

        it('keeps what the catalogue says of a template when the server sends none of it, and reads it again on request', async () => {
            http.get.mockResolvedValueOnce({});

            await component.selectTemplate(summary('a'));

            expect(component.previewFailed).toBe(true);
            expect(component.previewName).toBe('Template a');
            expect(component.canUseTemplate).toBe(true);

            http.get.mockResolvedValueOnce({ data: detail('a') });
            await component.retryTemplate();

            expect(component.previewFailed).toBe(false);
            expect(component.previewFieldCount).toBe(1);
        });

        it('does not let the late 404 of a preview eject the person from the form they are filling', async () => {
            const slow = deferred();
            http.get.mockReturnValueOnce(slow.promise);
            const selecting = component.selectTemplate(summary('a'));

            // The template can be used while its fields are still being read.
            component.useTemplate();
            component.form.name = 'Typed';

            slow.reject(new HttpError(404, 'Not found'));
            await selecting;

            expect(component.step).toBe('form');
            expect(component.form.name).toBe('Typed');
            expect(notify).not.toHaveBeenCalled();
            expect(component.templatesState).toBe('idle');
            expect(http.get).toHaveBeenCalledTimes(1);
        });

        it('reads a template again when the person comes back from the form before its fields had arrived', async () => {
            const slow = deferred();
            http.get.mockReturnValueOnce(slow.promise);
            const selecting = component.selectTemplate(summary('a'));

            component.useTemplate();
            slow.resolve({ data: detail('a', { name: 'A, late' }) });
            await selecting;

            // The answer that came late was dropped: the preview must not stay on its spinner for good.
            http.get.mockResolvedValueOnce({ data: detail('a') });
            component.back();
            await vi.waitFor(() => expect(component.previewLoading).toBe(false));

            expect(component.step).toBe('preview');
            expect(component.previewFieldCount).toBe(1);
            expect(http.get).toHaveBeenCalledTimes(2);
        });

        it('goes back to the picker, and reads the catalogue if a link skipped it', async () => {
            http.get.mockResolvedValueOnce({ data: detail('a') });
            await component.showTemplate('a');

            http.get.mockResolvedValueOnce({ data: [summary('a')] });
            component.back();

            expect(component.step).toBe('pick');
            expect(component.templatesLoading).toBe(true);
            expect(http.get).toHaveBeenLastCalledWith(TEMPLATES_URL);
        });

        it('sends the person back to the picker, with a notice and a catalogue read again, when the template is gone', async () => {
            http.get.mockResolvedValueOnce({ data: [summary('a'), summary('gone')] });
            await component.loadTemplates();

            http.get.mockRejectedValueOnce(new HttpError(404, 'Not found'));
            http.get.mockResolvedValueOnce({ data: [summary('a')] });
            await component.selectTemplate(summary('gone'));

            expect(notify).toHaveBeenCalledWith('warning', 'This template does not exist. Pick one from the list.');
            expect(component.step).toBe('pick');
            expect(component.template).toBeNull();
            expect(component.previewFailed).toBe(false);
            expect(component.templates.map((entry) => entry.slug)).toEqual(['a']);
        });
    });

    describe('the form of a template', () => {
        it('starts with the name and the summary of the template', async () => {
            http.get.mockResolvedValue({ data: detail('a', { name: 'Home inventory', summary: 'Everything.' }) });
            await component.showTemplate('a');

            component.useTemplate();

            expect(component.step).toBe('form');
            expect(component.form).toEqual({ name: 'Home inventory', description: 'Everything.' });
            expect(component.fromTemplate).toBe(true);
            expect(component.fromTemplateLabel).toBe('Template: Home inventory');
        });

        it('keeps what was typed when the person comes back to the same template, and replaces it for another', async () => {
            http.get.mockResolvedValueOnce({ data: detail('a', { name: 'A' }) });
            await component.showTemplate('a');
            component.useTemplate();
            component.form.name = 'Typed';

            component.back();
            component.useTemplate();

            expect(component.form.name).toBe('Typed');

            http.get.mockResolvedValueOnce({ data: detail('b', { name: 'B' }) });
            await component.showTemplate('b');
            component.useTemplate();

            expect(component.form.name).toBe('B');
            expect(component.formTemplate).toEqual({ slug: 'b', name: 'B' });
        });

        it('is emptied when the person turns to starting from scratch', async () => {
            http.get.mockResolvedValueOnce({ data: detail('a') });
            await component.showTemplate('a');
            component.useTemplate();

            component.back();
            component.back();
            component.back();
            component.startFromScratch();

            expect(component.form).toEqual({ name: '', description: '' });
            expect(component.formTemplate).toBeNull();
        });

        it('keeps what was typed in a form from scratch when the person looks at the templates and comes back', () => {
            component.startFromScratch();
            component.form.name = 'Typed';

            component.back();
            component.startFromScratch();

            expect(component.form.name).toBe('Typed');
        });

        it('is created from the template, which the request names', async () => {
            http.get.mockResolvedValue({ data: detail('a', { name: 'A' }) });
            http.post.mockResolvedValue({ data: { id: 'new' } });
            await component.showTemplate('a');
            component.useTemplate();

            await component.submit();

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/inventories', {
                name: 'A',
                description: 'Summary of a',
                template: 'a',
            });
        });

        it('is created from scratch without naming any template', async () => {
            http.post.mockResolvedValue({ data: { id: 'new' } });
            component.startFromScratch();
            component.form.name = 'Garden';

            await component.submit();

            expect(http.post.mock.calls[0][1]).toStrictEqual({ name: 'Garden', description: null });
        });

        it('is submitted from its own step only', async () => {
            component.form.name = 'Garden';

            for (const step of ['choose', 'pick', 'preview']) {
                component.step = step;
                await component.submit();
            }

            expect(http.post).not.toHaveBeenCalled();
        });

        it('says the quota of fields is reached', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'field_limit_reached', body: { limit: 100 } }),
            );
            component.startFromScratch();
            component.form.name = 'Garden';

            await component.submit();

            expect(component.formError).toBe('Field limit reached (100 per inventory).');
            expect(component.busy).toBe(false);
        });

        it('shows a refusal about the template at the top of the dialog', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', { errors: { template: ['The selected template is invalid.'] } }),
            );
            component.startFromScratch();
            component.form.name = 'Garden';

            await component.submit();

            expect(component.formError).toBe('The selected template is invalid.');
            expect(component.errors).toEqual({ template: 'The selected template is invalid.' });
        });
    });

    describe('the link', () => {
        const linkTo = (search) => window.history.replaceState(null, '', '/app/w/shop' + search);

        it('opens nothing when the address names no template', async () => {
            linkTo('?view=cards');

            await component.openLinkedTemplate();

            expect(showModal).not.toHaveBeenCalled();
            expect(http.get).not.toHaveBeenCalled();
            expect(window.location.search).toBe('?view=cards');
        });

        it('opens the preview of the template it names, and takes the parameter out of the address', async () => {
            linkTo('?template=wine-cellar&view=cards');
            http.get.mockResolvedValue({ data: detail('wine-cellar') });

            await component.openLinkedTemplate();

            expect(showModal).toHaveBeenCalledWith(modal);
            expect(component.step).toBe('preview');
            expect(component.previewFieldCount).toBe(1);
            expect(window.location.search).toBe('?view=cards');
            expect(window.location.pathname).toBe('/app/w/shop');
        });

        it('gives the focus to the button that adds an inventory before it opens the dialog, so that closing the dialog gives it back there', async () => {
            linkTo('?template=wine-cellar');
            http.get.mockResolvedValue({ data: detail('wine-cellar') });

            const button = document.createElement('button');
            document.body.append(button);
            component.$refs.newInventory = button;
            // The dialog remembers the element that has the focus as it opens, and gives the focus back to it.
            let anchor = null;
            showModal.mockImplementationOnce(() => {
                anchor = document.activeElement;
            });

            await component.openLinkedTemplate();

            expect(anchor).toBe(button);
        });

        it('opens the dialog all the same when the page has no such button', async () => {
            linkTo('?template=wine-cellar');
            http.get.mockResolvedValue({ data: detail('wine-cellar') });

            await component.openLinkedTemplate();

            expect(showModal).toHaveBeenCalledWith(modal);
        });

        it('opens the dialog before the template has been read, so that the person sees it at once', async () => {
            linkTo('?template=wine-cellar');
            http.get.mockReturnValue(new Promise(() => {}));

            component.openLinkedTemplate();

            expect(showModal).toHaveBeenCalledWith(modal);
            expect(component.step).toBe('preview');
            expect(component.previewLoading).toBe(true);
        });

        it('opens the picker with a notice for a template the server does not know', async () => {
            linkTo('?template=nope');
            http.get.mockRejectedValueOnce(new HttpError(404, 'Not found'));
            http.get.mockResolvedValueOnce({ data: [summary('a')] });

            await component.openLinkedTemplate();

            expect(notify).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('warning', 'This template does not exist. Pick one from the list.');
            expect(component.step).toBe('pick');
            expect(component.templates).toHaveLength(1);
            expect(window.location.search).toBe('');
        });

        it.each(['', 'Wine Cellar', '../me', 'a'.repeat(61)])(
            'opens the picker without asking the server about %j',
            async (name) => {
                linkTo('?template=' + encodeURIComponent(name));
                http.get.mockResolvedValue({ data: [summary('a')] });

                await component.openLinkedTemplate();

                expect(http.get).toHaveBeenCalledTimes(1);
                expect(http.get).toHaveBeenCalledWith(TEMPLATES_URL);
                expect(notify).toHaveBeenCalledWith('warning', expect.any(String));
                expect(component.step).toBe('pick');
            },
        );

        it('opens nothing for a person who may not add inventories, but cleans the address', async () => {
            component.$root.dataset.canCreate = '0';
            linkTo('?template=wine-cellar');

            await component.openLinkedTemplate();

            expect(showModal).not.toHaveBeenCalled();
            expect(http.get).not.toHaveBeenCalled();
            expect(window.location.search).toBe('');
        });

        it('is looked for when the page opens, once the dialog is there', async () => {
            linkTo('?template=wine-cellar');
            http.get.mockImplementation(async (url) =>
                url === '/api/v1/workspaces/shop/inventories' ? { data: [] } : { data: detail('wine-cellar') },
            );

            await component.init();
            await vi.waitFor(() => expect(component.previewFieldCount).toBe(1));

            expect(showModal).toHaveBeenCalledWith(modal);
            expect(component.step).toBe('preview');
            expect(window.location.search).toBe('');
        });
    });
});
