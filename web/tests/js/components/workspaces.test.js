import { beforeEach, describe, expect, it, vi } from 'vitest';

import workspaces from '../../../resources/js/components/workspaces.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { reloadPage } from '../../../resources/js/lib/page.js';
import { createWorkspacesStore } from '../../../resources/js/stores/workspaces.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key, replacements = {}) => {
        const messages = {
            'workspaces.role.owner': 'Owner',
            'workspaces.role.editor': 'Editor',
            'workspaces.role.viewer': 'Viewer',
            'workspaces.rename_named': 'Rename :name',
            'workspaces.delete_named': 'Delete :name',
            'workspaces.confirm_delete': 'Delete :name and everything in it?',
            'workspaces.notice.created': 'Created',
            'workspaces.notice.renamed': 'Renamed',
            'workspaces.notice.deleted': 'Deleted',
            'workspaces.error.last': 'Keep one',
        };

        return Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(':' + name, value),
            messages[key] ?? key,
        );
    },
}));

const MINE = { id: 'mine', name: 'Mine', role: 'owner', current: true };
const SHARED = { id: 'shared', name: 'Shared', role: 'viewer', current: false };

/** What `GET /api/v1/workspaces` answers for a list of interface items. */
function listing(items) {
    return { data: items.map((item) => ({ id: item.id, name: item.name, role: item.role, is_current: item.current })) };
}

describe('workspaces page', () => {
    let component;
    let store;

    beforeEach(() => {
        store = createWorkspacesStore();
        store.replace([MINE, SHARED]);
        component = workspaces();
        // Alpine injects $root (the element carrying x-data and the data-* attributes), $store, $refs and $nextTick.
        component.$root = {
            dataset: {
                url: '/api/v1/workspaces',
                currentUrl: '/api/v1/me/current-workspace',
                workspaceUrl: '/app/w/__id__',
            },
        };
        component.$store = { workspaces: store };
        component.$refs = {};
        component.$nextTick = (callback) => callback();
        window.location.hash = '';
    });

    describe('showing the workspaces', () => {
        it('reads them from the shared store', () => {
            expect(component.items).toEqual([MINE, SHARED]);
        });

        it('links each workspace to its page', () => {
            expect(component.pageUrl(MINE)).toBe('/app/w/mine');
            expect(component.pageUrl(SHARED)).toBe('/app/w/shared');
        });

        it('lets only an owner rename and delete', () => {
            expect(component.canManage(MINE)).toBe(true);
            expect(component.canManage(SHARED)).toBe(false);
            expect(component.canManage({ id: 'x', name: 'X', role: 'editor', current: false })).toBe(false);
        });

        it('offers to delete a workspace to its owner, but never the last one of the person', () => {
            const second = { id: 'second', name: 'Second', role: 'owner', current: false };
            store.replace([MINE, second]);

            expect(component.canDelete(MINE)).toBe(true);
            expect(component.canDelete(second)).toBe(true);

            store.replace([MINE, SHARED]);

            expect(component.canDelete(SHARED)).toBe(false);

            store.replace([MINE]);

            expect(component.canDelete(MINE)).toBe(false);
            expect(component.canManage(MINE)).toBe(true);
        });

        it('labels roles and actions with the name of the workspace', () => {
            expect(component.roleLabel(MINE)).toBe('Owner');
            expect(component.roleLabel(SHARED)).toBe('Viewer');
            expect(component.renameLabel(MINE)).toBe('Rename Mine');
            expect(component.deleteLabel(MINE)).toBe('Delete Mine');
            expect(component.confirmText(MINE)).toBe('Delete Mine and everything in it?');
        });

        it('puts the cursor in the creation box when the page was opened from "New workspace"', () => {
            const focus = vi.fn();
            component.$refs = { name: { focus } };
            window.location.hash = '#create';

            component.init();

            expect(focus).toHaveBeenCalledTimes(1);
        });

        it('leaves the cursor alone otherwise', () => {
            const focus = vi.fn();
            component.$refs = { name: { focus } };

            component.init();

            expect(focus).not.toHaveBeenCalled();
        });
    });

    describe('creating', () => {
        it('sends the trimmed name, tells it worked and shows the list as the server now holds it', async () => {
            const created = { id: 'new', name: 'Warehouse', role: 'owner', current: true };
            http.post.mockResolvedValue({ data: {} });
            http.get.mockResolvedValue(listing([{ ...MINE, current: false }, SHARED, created]));
            component.newName = '  Warehouse ';

            await component.create();

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces', { name: 'Warehouse' });
            expect(http.get).toHaveBeenCalledWith('/api/v1/workspaces');
            expect(notify).toHaveBeenCalledWith('success', 'Created');
            expect(store.items.map((item) => item.name)).toEqual(['Mine', 'Shared', 'Warehouse']);
            expect(store.current.id).toBe('new');
            expect(component.newName).toBe('');
            expect(component.busy).toBe(false);
        });

        it('can be submitted only with a name', () => {
            component.newName = '   ';
            expect(component.canCreate).toBe(false);

            component.newName = 'Shop';
            expect(component.canCreate).toBe(true);

            component.busy = true;
            expect(component.canCreate).toBe(false);
        });

        it('shows the translated validation message of a refused name and keeps what was typed', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'The given data was invalid.', { errors: { name: ['Le nom est trop long.'] } }),
            );
            component.newName = 'x'.repeat(101);

            await component.create();

            expect(notify).toHaveBeenCalledWith('danger', 'Le nom est trop long.');
            expect(http.get).not.toHaveBeenCalled();
            expect(component.newName).toBe('x'.repeat(101));
            expect(component.busy).toBe(false);
        });

        it('ignores a second submit while one is in flight', async () => {
            http.post.mockResolvedValue({ data: {} });
            http.get.mockResolvedValue(listing([MINE]));
            component.newName = 'Shop';

            await Promise.all([component.create(), component.create()]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });
    });

    describe('renaming', () => {
        it('opens the box with the current name, one workspace at a time', () => {
            component.startRename(MINE);

            expect(component.isEditing(MINE)).toBe(true);
            expect(component.isEditing(SHARED)).toBe(false);
            expect(component.editName).toBe('Mine');
        });

        it('closes the box without saving', () => {
            component.startRename(MINE);
            component.cancelRename();

            expect(component.isEditing(MINE)).toBe(false);
            expect(http.patch).not.toHaveBeenCalled();
        });

        it('saves the trimmed name, then shows the new list and closes the box', async () => {
            http.patch.mockResolvedValue({ data: {} });
            http.get.mockResolvedValue(listing([{ ...MINE, name: 'Home' }, SHARED]));
            component.startRename(MINE);
            component.editName = ' Home ';

            await component.saveRename(MINE);

            expect(http.patch).toHaveBeenCalledWith('/api/v1/workspaces/mine', { name: 'Home' });
            expect(notify).toHaveBeenCalledWith('success', 'Renamed');
            expect(store.items[0].name).toBe('Home');
            expect(component.isEditing(MINE)).toBe(false);
        });

        it('keeps the box open when the name is refused', async () => {
            http.patch.mockRejectedValue(
                new HttpError(422, 'Invalid', { errors: { name: ['Le nom est obligatoire.'] } }),
            );
            component.startRename(MINE);
            component.editName = '';

            await component.saveRename(MINE);

            expect(notify).toHaveBeenCalledWith('danger', 'Le nom est obligatoire.');
            expect(component.isEditing(MINE)).toBe(true);
        });

        it('closes a pending deletion confirmation when a rename starts', () => {
            component.askDelete(MINE);
            component.startRename(MINE);

            expect(component.isConfirming(MINE)).toBe(false);
        });
    });

    describe('deleting', () => {
        it('asks for a confirmation first, for one workspace at a time', () => {
            component.askDelete(MINE);

            expect(component.isConfirming(MINE)).toBe(true);
            expect(component.isConfirming(SHARED)).toBe(false);
            expect(http.delete).not.toHaveBeenCalled();

            component.cancelDelete();

            expect(component.isConfirming(MINE)).toBe(false);
        });

        it('closes a rename box when a deletion is asked', () => {
            component.startRename(MINE);
            component.askDelete(MINE);

            expect(component.isEditing(MINE)).toBe(false);
        });

        it('deletes once confirmed, then shows the list as the server now holds it', async () => {
            http.delete.mockResolvedValue(null);
            http.get.mockResolvedValue(listing([{ ...SHARED, role: 'owner', current: true }]));
            component.askDelete(MINE);

            await component.confirmDelete(MINE);

            expect(http.delete).toHaveBeenCalledWith('/api/v1/workspaces/mine');
            expect(notify).toHaveBeenCalledWith('success', 'Deleted');
            expect(store.items.map((item) => item.id)).toEqual(['shared']);
            expect(store.current.id).toBe('shared');
            expect(component.isConfirming(MINE)).toBe(false);
        });

        it('says why the last workspace cannot be deleted, and keeps the confirmation', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'last_workspace' }));
            component.askDelete(MINE);

            await component.confirmDelete(MINE);

            expect(notify).toHaveBeenCalledWith('danger', 'Keep one');
            expect(store.items).toHaveLength(2);
            expect(component.isConfirming(MINE)).toBe(true);
            expect(component.busy).toBe(false);
        });

        it('shows the message of any other refusal', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Une erreur est survenue.'));
            component.askDelete(MINE);

            await component.confirmDelete(MINE);

            expect(notify).toHaveBeenCalledWith('danger', 'Une erreur est survenue.');
        });

        it('does not repeat what the HTTP helper already told (forbidden, not found...)', async () => {
            const error = new HttpError(403, 'Forbidden');
            error.reported = true;
            http.delete.mockRejectedValue(error);

            await component.confirmDelete(MINE);

            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('choosing the workspace to work in', () => {
        it('tells the API then reloads the page', async () => {
            http.put.mockResolvedValue({ data: {} });

            await component.makeCurrent(SHARED);

            expect(http.put).toHaveBeenCalledWith('/api/v1/me/current-workspace', { workspace: 'shared' });
            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('does nothing for the current workspace', async () => {
            await component.makeCurrent(MINE);

            expect(http.put).not.toHaveBeenCalled();
        });

        it('stays on the page and says why when it is refused', async () => {
            http.put.mockRejectedValue(
                new HttpError(422, 'Invalid', { errors: { workspace: ['Espace de travail invalide.'] } }),
            );

            await component.makeCurrent(SHARED);

            expect(notify).toHaveBeenCalledWith('danger', 'Espace de travail invalide.');
            expect(reloadPage).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });
    });

    it('stays silent when a request was cancelled', async () => {
        http.delete.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

        await component.confirmDelete(MINE);

        expect(notify).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    it('tells about a list it could not read after a change that worked', async () => {
        http.post.mockResolvedValue({ data: {} });
        http.get.mockRejectedValue(new HttpError(0, 'Network down', { kind: 'network' }));
        component.newName = 'Shop';

        await component.create();

        expect(notify).toHaveBeenCalledWith('success', 'Created');
        expect(notify).toHaveBeenCalledWith('danger', 'Network down');
        expect(component.busy).toBe(false);
    });
});
