import { beforeEach, describe, expect, it, vi } from 'vitest';

import workspaceInventories from '../../../resources/js/components/workspace-inventories.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { hideModal, onModalHidden, showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';

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

const URL = '/api/v1/workspaces/shop/inventories';

/** An inventory as `InventoryResource` describes it in a list. */
function inventory(id, overrides = {}) {
    return { id, name: 'Inventory ' + id, description: null, fields_count: 2, items_count: 12, ...overrides };
}

describe('the inventories of a workspace', () => {
    let component;
    let modal;

    beforeEach(() => {
        modal = document.createElement('div');
        component = workspaceInventories();
        // Alpine injects $root (the element carrying x-data and the data-* attributes), $refs and $nextTick.
        component.$root = {
            dataset: {
                url: URL,
                inventoryUrl: '/app/w/shop/inventories/__id__',
                canCreate: '1',
                loadingLabel: 'Loading…',
            },
        };
        component.$refs = { modal };
        component.$nextTick = (callback) => callback();
    });

    describe('reading the list', () => {
        it('announces the reading to the screen readers, and nothing once it is over', async () => {
            http.get.mockResolvedValue({ data: [inventory('a')] });

            const reading = component.init();

            expect(component.loadingAnnouncement).toBe('Loading…');

            await reading;

            expect(component.loadingAnnouncement).toBe('');
        });

        it('reads the inventories when the page opens', async () => {
            http.get.mockResolvedValue({ data: [inventory('a'), inventory('b')] });

            const reading = component.init();

            expect(component.loading).toBe(true);
            expect(component.empty).toBe(false);

            await reading;

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.items.map((item) => item.id)).toEqual(['a', 'b']);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);
        });

        it('forgets the form whenever the dialog is closed', async () => {
            http.get.mockResolvedValue({ data: [] });

            await component.init();

            expect(onModalHidden).toHaveBeenCalledWith(modal, expect.any(Function));

            component.form = { name: 'Half typed', description: '' };
            component.errors = { name: 'Taken.' };
            onModalHidden.mock.calls[0][1]();

            expect(component.form).toEqual({ name: '', description: '' });
            expect(component.errors).toEqual({});
        });

        it('knows when the workspace holds no inventory', async () => {
            http.get.mockResolvedValue({ data: [] });

            await component.init();

            expect(component.empty).toBe(true);
        });

        it('does not say the workspace is empty when the list could not be read, and reads it again on request', async () => {
            http.get.mockRejectedValueOnce(new HttpError(0, 'Network down', { kind: 'network' }));

            await component.init();

            expect(component.loading).toBe(false);
            expect(component.items).toEqual([]);
            expect(component.loadFailed).toBe(true);
            expect(component.empty).toBe(false);

            http.get.mockResolvedValue({ data: [] });
            const retrying = component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.empty).toBe(false);

            await retrying;

            expect(component.loadFailed).toBe(false);
            expect(component.empty).toBe(true);
        });

        it('tells why the list could not be read, unless the HTTP helper already did', async () => {
            http.get.mockRejectedValueOnce(new HttpError(409, 'Conflict', { code: 'odd' }));
            await component.load();

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');

            const reported = new HttpError(500, 'Server error');
            reported.reported = true;
            http.get.mockRejectedValueOnce(reported);
            notify.mockClear();
            await component.load();

            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('the cards', () => {
        it('links each inventory to its page', () => {
            expect(component.pageUrl(inventory('01HV'))).toBe('/app/w/shop/inventories/01HV');
        });

        it('counts the items and the fields of an inventory', () => {
            expect(component.countsLabel(inventory('a'))).toBe('12 items · 2 fields');
            expect(component.countsLabel(inventory('a', { items_count: 1, fields_count: 1 }))).toBe('1 item · 1 field');
            expect(component.countsLabel(inventory('a', { items_count: 0, fields_count: 0 }))).toBe(
                '0 items · 0 fields',
            );
        });

        it('offers to add an inventory to the people who may', () => {
            expect(component.canCreate).toBe(true);

            component.$root.dataset.canCreate = '0';

            expect(component.canCreate).toBe(false);
        });
    });

    describe('adding an inventory', () => {
        it('opens the dialog with an empty form', () => {
            component.form = { name: 'Old', description: 'Old' };
            component.errors = { name: 'Old.' };

            component.startCreate();

            expect(component.form).toEqual({ name: '', description: '' });
            expect(component.errors).toEqual({});
            expect(showModal).toHaveBeenCalledWith(modal);
        });

        it('cannot be sent without a name', async () => {
            component.form = { name: '   ', description: 'Something' };

            expect(component.canSubmit).toBe(false);

            await component.create();

            expect(http.post).not.toHaveBeenCalled();
        });

        it('creates the inventory, then takes the person to its page', async () => {
            http.post.mockResolvedValue({ data: inventory('01HV', { name: 'Tools' }) });
            component.form = { name: '  Tools ', description: ' Hand tools ' };

            await component.create();

            expect(http.post).toHaveBeenCalledWith(URL, { name: 'Tools', description: 'Hand tools' });
            expect(hideModal).toHaveBeenCalledWith(modal);
            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop/inventories/01HV');
        });

        it('sends no description when none was typed', async () => {
            http.post.mockResolvedValue({ data: inventory('01HV') });
            component.form = { name: 'Tools', description: '  ' };

            await component.create();

            expect(http.post).toHaveBeenCalledWith(URL, { name: 'Tools', description: null });
        });

        it('stays busy once it has navigated, so that a second click does nothing', async () => {
            http.post.mockResolvedValue({ data: inventory('01HV') });
            component.form = { name: 'Tools', description: '' };

            await component.create();
            await component.create();

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('shows the refusals under the inputs they are about', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: { name: ['The name is required.', 'More.'], description: ['Too long.'] },
                }),
            );
            component.form = { name: 'x', description: '' };

            await component.create();

            expect(component.errors).toEqual({ name: 'The name is required.', description: 'Too long.' });
            expect(component.formError).toBe('');
            expect(component.busy).toBe(false);
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('shows a refusal about something else at the top of the dialog', async () => {
            http.post.mockRejectedValue(new HttpError(422, 'Invalid.', { errors: { workspace: ['Gone.'] } }));
            component.form = { name: 'x', description: '' };

            await component.create();

            expect(component.formError).toBe('Gone.');
        });

        it('says the quota is reached', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'inventory_limit_reached', body: { limit: 100 } }),
            );
            component.form = { name: 'x', description: '' };

            await component.create();

            expect(component.formError).toBe('Inventory limit reached (100 per workspace).');
            expect(component.busy).toBe(false);
        });

        it('does not repeat what the HTTP helper already told', async () => {
            const error = new HttpError(0, 'Network down', { kind: 'network' });
            error.reported = true;
            http.post.mockRejectedValue(error);
            component.form = { name: 'x', description: '' };

            await component.create();

            expect(notify).not.toHaveBeenCalled();
            expect(component.formError).toBe('');
            expect(component.busy).toBe(false);
        });

        it('shows anything else as a toast, and stays silent when the request was cancelled', async () => {
            http.post.mockRejectedValueOnce(new HttpError(409, 'Conflict', { code: 'odd' }));
            component.form = { name: 'x', description: '' };
            await component.create();

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');

            notify.mockClear();
            http.post.mockRejectedValueOnce(new DOMException('Aborted', 'AbortError'));
            await component.create();

            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });
    });
});
