import { beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryHeader from '../../../resources/js/components/inventory-header.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { hideModal, onModalHidden, showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const URL = '/api/v1/inventories/tools';

describe('the header of an inventory', () => {
    let component;
    let editModal;
    let deleteModal;

    beforeEach(() => {
        editModal = document.createElement('div');
        deleteModal = document.createElement('div');
        component = inventoryHeader();
        component.$root = {
            dataset: {
                url: URL,
                workspacePage: '/app/w/shop',
                name: 'Tools',
                description: 'Hand tools',
                canUpdate: '1',
                canDelete: '1',
                canManageFields: '1',
            },
        };
        component.$refs = { editModal, deleteModal };
        component.$nextTick = (callback) => callback();
        document.title = 'Tools · inventaire.cloud';
        component.init();
    });

    describe('what the page shows', () => {
        it('starts with the name and the description the page rendered', () => {
            expect(component.name).toBe('Tools');
            expect(component.description).toBe('Hand tools');
            expect(component.hasDescription).toBe(true);
        });

        it('has no description to show when there is none', () => {
            component.$root.dataset.description = '';
            component.init();

            expect(component.hasDescription).toBe(false);
        });

        it('offers what the person may do, and nothing else', () => {
            expect(component.canUpdate).toBe(true);
            expect(component.canDelete).toBe(true);
            expect(component.canManageFields).toBe(true);

            Object.assign(component.$root.dataset, { canUpdate: '0', canDelete: '0', canManageFields: '0' });

            expect(component.canUpdate).toBe(false);
            expect(component.canDelete).toBe(false);
            expect(component.canManageFields).toBe(false);
        });

        it('forgets the form and the count whenever a dialog is closed', () => {
            component.errors = { name: 'Taken.' };
            component.formError = 'Oops';
            component.itemsCount = 4;

            onModalHidden.mock.calls.find(([element]) => element === editModal)[1]();
            onModalHidden.mock.calls.find(([element]) => element === deleteModal)[1]();

            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
            expect(component.itemsCount).toBeNull();
        });
    });

    describe('renaming and describing', () => {
        it('opens the dialog with the current name and description', () => {
            component.errors = { name: 'Old.' };

            component.startEdit();

            expect(component.form).toEqual({ name: 'Tools', description: 'Hand tools' });
            expect(component.errors).toEqual({});
            expect(showModal).toHaveBeenCalledWith(editModal);
        });

        it('cannot be saved without a name', async () => {
            component.form = { name: ' ', description: '' };

            expect(component.canSubmit).toBe(false);

            await component.save();

            expect(http.patch).not.toHaveBeenCalled();
        });

        it('sends the changes and shows what the API answers', async () => {
            http.patch.mockResolvedValue({ data: { name: 'Workshop', description: null } });
            component.form = { name: ' Workshop ', description: '  ' };

            await component.save();

            expect(http.patch).toHaveBeenCalledWith(URL, { name: 'Workshop', description: null });
            expect(component.name).toBe('Workshop');
            expect(component.description).toBe('');
            expect(component.hasDescription).toBe(false);
            expect(hideModal).toHaveBeenCalledWith(editModal);
            expect(notify).toHaveBeenCalledWith('success', 'Inventory updated.');
            expect(component.busy).toBe(false);
        });

        it('puts the new name in the title of the tab, before the name of the application', async () => {
            http.patch.mockResolvedValue({ data: { name: 'Workshop', description: null } });
            component.form = { name: 'Workshop', description: '' };

            await component.save();

            expect(document.title).toBe('Workshop · inventaire.cloud');
        });

        it('titles the tab with the name alone when it had no application name', () => {
            document.title = 'Tools';
            component.name = 'Workshop';

            component.retitle();

            expect(document.title).toBe('Workshop');
        });

        it('shows the refusals under the inputs they are about', async () => {
            http.patch.mockRejectedValue(new HttpError(422, 'Invalid.', { errors: { name: ['Too long.'] } }));
            component.form = { name: 'x', description: '' };

            await component.save();

            expect(component.errors).toEqual({ name: 'Too long.' });
            expect(component.formError).toBe('');
            expect(component.name).toBe('Tools');
            expect(hideModal).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('shows a refusal about something else at the top of the dialog', async () => {
            http.patch.mockRejectedValue(new HttpError(422, 'Invalid.', { errors: { other: ['Odd.'] } }));
            component.form = { name: 'x', description: '' };

            await component.save();

            expect(component.formError).toBe('Odd.');
        });

        it('shows the message of a 422 that names no input', async () => {
            http.patch.mockRejectedValue(new HttpError(422, 'The given data was invalid.'));
            component.form = { name: 'x', description: '' };

            await component.save();

            expect(component.formError).toBe('The given data was invalid.');
        });

        it('shows other failures as a toast, but not the ones the HTTP helper already told', async () => {
            http.patch.mockRejectedValueOnce(new HttpError(409, 'Conflict', { code: 'odd' }));
            component.form = { name: 'x', description: '' };
            await component.save();

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');

            notify.mockClear();
            const reported = new HttpError(403, 'Not allowed');
            reported.reported = true;
            http.patch.mockRejectedValueOnce(reported);
            await component.save();

            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('deleting', () => {
        it('reads how many items go with the inventory, then asks for the confirmation', async () => {
            http.get.mockResolvedValue({ data: { items_count: 12 } });

            await component.askDelete();

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.itemsCount).toBe(12);
            expect(component.confirmText).toBe('Delete Tools and its 12 items?');
            expect(showModal).toHaveBeenCalledWith(deleteModal);
            expect(http.delete).not.toHaveBeenCalled();
        });

        it('states the count in the singular, and for none', async () => {
            http.get.mockResolvedValueOnce({ data: { items_count: 1 } });
            await component.askDelete();

            expect(component.confirmText).toBe('Delete Tools and its 1 item?');

            http.get.mockResolvedValueOnce({ data: { items_count: 0 } });
            await component.askDelete();

            expect(component.confirmText).toBe('Delete Tools and its 0 items?');
        });

        it('has nothing to ask until the count is known', () => {
            expect(component.confirmText).toBe('');
        });

        it('does not open the dialog when the count could not be read', async () => {
            http.get.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'odd' }));

            await component.askDelete();

            expect(showModal).not.toHaveBeenCalled();
            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');
            expect(component.busy).toBe(false);
        });

        it('deletes once confirmed, then goes back to the workspace', async () => {
            http.delete.mockResolvedValue(null);

            await component.confirmDelete();

            expect(http.delete).toHaveBeenCalledWith(URL);
            expect(hideModal).toHaveBeenCalledWith(deleteModal);
            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
        });

        it('stays on the page, free to try again, when the deletion fails', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'odd' }));

            await component.confirmDelete();

            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');
        });

        it('ignores a second click while the deletion is in flight', async () => {
            http.delete.mockResolvedValue(null);

            await Promise.all([component.confirmDelete(), component.confirmDelete()]);

            expect(http.delete).toHaveBeenCalledTimes(1);
        });
    });
});
