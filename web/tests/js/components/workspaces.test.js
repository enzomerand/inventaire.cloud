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
            'workspaces.confirm_delete_subscription': 'Its Pro subscription ends at once.',
            'workspaces.notice.created': 'Created',
            'workspaces.notice.renamed': 'Renamed',
            'workspaces.notice.deleted': 'Deleted',
            'workspaces.error.last': 'Keep one',
            'workspaces.photo_entry_named': 'Photo entry for :name',
            'workspaces.notice.photo_entry_on': 'Photo entry on for :name',
            'workspaces.notice.photo_entry_off': 'Photo entry off for :name',
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

        it('warns, before a workspace with a live subscription is deleted, that the subscription ends at once', () => {
            component.$root.dataset.liveSubscriptions = JSON.stringify(['mine']);

            component.init();

            expect(component.confirmText(MINE)).toBe(
                'Delete Mine and everything in it? Its Pro subscription ends at once.',
            );
            // A workspace the page does not list has none.
            expect(component.confirmText({ ...MINE, id: 'other', name: 'Other' })).toBe(
                'Delete Other and everything in it?',
            );
        });

        it('does not warn on a page that lists none, or that does not say', () => {
            component.init();

            expect(component.confirmText(MINE)).toBe('Delete Mine and everything in it?');

            component.$root.dataset.liveSubscriptions = '[]';
            component.init();

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

    describe('the photo entry', () => {
        const URL = '/api/v1/workspaces/__id__/photo-entry';

        /** A checkbox the way the browser hands it to the change handler. */
        const box = (checked) => ({ target: { checked } });

        /** Renders the page the way the server does, with the consent of the owned workspaces (or null). */
        function render(consents) {
            component.$root.dataset.photoEntryUrl = URL;

            if (consents !== undefined) {
                component.$root.dataset.photoEntry = JSON.stringify(consents);
            }

            component.init();
        }

        it('is not offered when the page says nothing, or says null', () => {
            for (const json of [undefined, 'null']) {
                component.$root.dataset.photoEntry = json;
                component.init();

                expect(component.photoEntry).toBeNull();
                expect(component.canPhotoEntry(MINE)).toBe(false);
            }
        });

        it('is offered, for the workspaces the person owns only, when the instance offers it', () => {
            render({ mine: true });

            expect(component.canPhotoEntry(MINE)).toBe(true);
            expect(component.canPhotoEntry(SHARED)).toBe(false);
            expect(component.canPhotoEntry({ id: 'editing', name: 'Editing', role: 'editor', current: false })).toBe(
                false,
            );
        });

        it('starts from the consent the page was rendered with, a workspace the page does not know having none', () => {
            render({ mine: true, other: false });

            expect(component.photoEntryOn(MINE)).toBe(true);
            expect(component.photoEntryOn({ id: 'other', name: 'Other', role: 'owner', current: false })).toBe(false);
            expect(component.photoEntryOn({ id: 'new', name: 'New', role: 'owner', current: false })).toBe(false);
        });

        it('says it has nothing on when the instance does not offer it', () => {
            render('null');

            expect(component.photoEntryOn(MINE)).toBe(false);
        });

        it('names the switch of each workspace', () => {
            render({ mine: false });

            expect(component.photoEntryLabel(MINE)).toBe('Photo entry for Mine');
        });

        describe('on an instance that sells plans', () => {
            /** Renders the page with the ULIDs of the workspaces whose plan includes the photo entry (null: the instance sells no plan). */
            function renderPlans(consents, unlocked) {
                component.$root.dataset.billingUrl = '/app/w/__id__/billing';
                component.$root.dataset.photoEntryUnlocked = JSON.stringify(unlocked);
                render(consents);
            }

            it('has no workspace locked when the page says nothing, or says null', () => {
                render({ mine: true });

                expect(component.photoEntryLockedByPlan(MINE)).toBe(false);
                expect(component.photoEntryDisabled(MINE)).toBe(false);
                expect(component.photoEntryHintIds(MINE)).toBe('workspace-photo-entry-hint-mine');

                renderPlans({ mine: true }, null);

                expect(component.photoEntryLockedByPlan(MINE)).toBe(false);
            });

            it('shows the switch of a workspace on Free off and disabled, with the hint that leads to its subscription', () => {
                renderPlans({ mine: true }, []);

                expect(component.canPhotoEntry(MINE)).toBe(true);
                expect(component.photoEntryLockedByPlan(MINE)).toBe(true);
                expect(component.photoEntryDisabled(MINE)).toBe(true);
                // The owner's choice is kept, but the switch does not show the feature on while the plan leaves it out.
                expect(component.photoEntryOn(MINE)).toBe(false);
                expect(component.photoEntryHintIds(MINE)).toBe(
                    'workspace-photo-entry-hint-mine workspace-photo-entry-plan-mine',
                );
                expect(component.billingUrl(MINE)).toBe('/app/w/mine/billing');
            });

            it('leaves the workspaces on a plan that includes it alone', () => {
                renderPlans({ mine: true, other: true }, ['mine']);

                expect(component.photoEntryLockedByPlan(MINE)).toBe(false);
                expect(component.photoEntryDisabled(MINE)).toBe(false);
                expect(component.photoEntryOn(MINE)).toBe(true);
            });

            it('locks a workspace the page does not know, as one created since it was rendered (it is on Free)', () => {
                const created = { id: 'new', name: 'Warehouse', role: 'owner', current: true };
                renderPlans({ mine: true }, ['mine']);

                expect(component.photoEntryLockedByPlan(created)).toBe(true);
                expect(component.photoEntryDisabled(created)).toBe(true);
                expect(component.photoEntryOn(created)).toBe(false);
                expect(component.photoEntryHintIds(created)).toBe(
                    'workspace-photo-entry-hint-new workspace-photo-entry-plan-new',
                );
            });

            it('keeps the disabled switch where it is, without asking the server', async () => {
                renderPlans({ mine: true }, []);
                const checkbox = box(true);

                await component.togglePhotoEntry(MINE, checkbox);

                expect(http.put).not.toHaveBeenCalled();
                expect(checkbox.target.checked).toBe(false);
            });
        });

        it('turns it on for that workspace, shows what the server answers, and says so', async () => {
            render({ mine: false, other: false });
            http.put.mockResolvedValue({ data: { enabled: true } });
            const checkbox = box(true);

            await component.togglePhotoEntry(MINE, checkbox);

            expect(http.put).toHaveBeenCalledWith('/api/v1/workspaces/mine/photo-entry', { enabled: true });
            expect(component.photoEntry).toEqual({ mine: true, other: false });
            expect(checkbox.target.checked).toBe(true);
            expect(notify).toHaveBeenCalledWith('success', 'Photo entry on for Mine');
            expect(component.busy).toBe(false);
        });

        it('turns it off, and says so', async () => {
            render({ mine: true });
            http.put.mockResolvedValue({ data: { enabled: false } });
            const checkbox = box(false);

            await component.togglePhotoEntry(MINE, checkbox);

            expect(http.put).toHaveBeenCalledWith('/api/v1/workspaces/mine/photo-entry', { enabled: false });
            expect(component.photoEntryOn(MINE)).toBe(false);
            expect(checkbox.target.checked).toBe(false);
            expect(notify).toHaveBeenCalledWith('success', 'Photo entry off for Mine');
        });

        it('shows what the server answers, whatever the switch said', async () => {
            render({ mine: false });
            http.put.mockResolvedValue({ data: { enabled: false } });
            const checkbox = box(true);

            await component.togglePhotoEntry(MINE, checkbox);

            expect(component.photoEntryOn(MINE)).toBe(false);
            expect(checkbox.target.checked).toBe(false);
        });

        it('puts the switch back and says why when the change could not be saved', async () => {
            render({ mine: false });
            http.put.mockRejectedValue(new HttpError(500, 'Server down'));
            const checkbox = box(true);

            await component.togglePhotoEntry(MINE, checkbox);

            expect(component.photoEntryOn(MINE)).toBe(false);
            expect(checkbox.target.checked).toBe(false);
            expect(notify).toHaveBeenCalledWith('danger', 'Server down');
            expect(component.busy).toBe(false);
        });

        it('takes the switches off the page when the instance no longer offers the feature', async () => {
            render({ mine: false });
            http.put.mockRejectedValue(
                new HttpError(409, 'Not available: ask its administrator.', { code: 'photo_entry_unavailable' }),
            );
            const checkbox = box(true);

            await component.togglePhotoEntry(MINE, checkbox);

            expect(component.photoEntry).toBeNull();
            expect(component.canPhotoEntry(MINE)).toBe(false);
            expect(checkbox.target.checked).toBe(false);
            expect(notify).toHaveBeenCalledWith('danger', 'Not available: ask its administrator.');
        });

        it('keeps the switches for another conflict', async () => {
            render({ mine: false });
            http.put.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'something_else' }));

            await component.togglePhotoEntry(MINE, box(true));

            expect(component.canPhotoEntry(MINE)).toBe(true);
        });

        it('does not repeat what the HTTP helper already told the person', async () => {
            render({ mine: false });
            const failure = new HttpError(0, 'Network error', { kind: 'network' });
            failure.reported = true;
            http.put.mockRejectedValue(failure);

            await component.togglePhotoEntry(MINE, box(true));

            expect(notify).not.toHaveBeenCalled();
        });

        it('stays silent when a request was cancelled', async () => {
            render({ mine: false });
            http.put.mockRejectedValue(new DOMException('Aborted', 'AbortError'));
            const checkbox = box(true);

            await component.togglePhotoEntry(MINE, checkbox);

            expect(notify).not.toHaveBeenCalled();
            expect(checkbox.target.checked).toBe(false);
            expect(component.busy).toBe(false);
        });

        it('ignores a change made while another is being saved, and keeps the switch where it was', async () => {
            render({ mine: true });
            component.busy = true;
            const checkbox = box(false);

            await component.togglePhotoEntry(MINE, checkbox);

            expect(http.put).not.toHaveBeenCalled();
            expect(checkbox.target.checked).toBe(true);
        });

        it('keeps the consent when the list is read again after another change', async () => {
            render({ mine: true });
            http.get.mockResolvedValue(listing([MINE, SHARED]));

            await component.refresh();

            expect(component.photoEntryOn(MINE)).toBe(true);
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
