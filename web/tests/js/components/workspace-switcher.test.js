import { beforeEach, describe, expect, it, vi } from 'vitest';

import workspaceSwitcher from '../../../resources/js/components/workspace-switcher.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';
import { createWorkspacesStore } from '../../../resources/js/stores/workspaces.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { put: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));

const LIST = [
    { id: 'personal', name: 'Espace personnel', role: 'owner', current: false, owner: null },
    { id: 'shop', name: 'Shop', role: 'owner', current: true, owner: null },
    { id: 'shared', name: 'Shared', role: 'viewer', current: false, owner: 'Grace Hopper' },
    { id: 'hers', name: 'Espace personnel', role: 'editor', current: false, owner: 'Camille Martin' },
];

describe('workspace switcher', () => {
    let component;
    let store;

    beforeEach(() => {
        store = createWorkspacesStore();
        component = workspaceSwitcher();
        // Alpine injects $root (the element carrying x-data and the data-* attributes) and $store.
        component.$root = {
            dataset: {
                url: '/api/v1/me/current-workspace',
                workspaceUrl: '/app/w/__id__',
                workspaces: JSON.stringify(LIST),
                ownerLabel: 'Owner:',
            },
        };
        component.$store = { workspaces: store };
    });

    describe('showing the workspaces', () => {
        it('seeds the shared store with the list the server rendered', () => {
            component.init();

            expect(store.items).toEqual(LIST);
            expect(component.workspaces).toEqual(LIST);
        });

        it('names the current workspace, and nothing while there is none', () => {
            expect(component.currentName).toBe('');

            component.init();

            expect(component.currentName).toBe('Shop');
        });

        it('follows the store when another part of the page changes it', () => {
            component.init();

            store.replace([{ id: 'new', name: 'Renamed', role: 'owner', current: true }]);

            expect(component.currentName).toBe('Renamed');
            expect(component.workspaces).toHaveLength(1);
        });

        it('highlights the current workspace in the dropdown', () => {
            expect(component.itemClass(LIST[1])).toBe('dropdown-item active');
            expect(component.itemClass(LIST[0])).toBe('dropdown-item');
        });
    });

    describe('telling workspaces of the same name apart', () => {
        it('gives the owner of a workspace that is somebody else, and nothing for the ones of the person', () => {
            component.init();

            expect(component.ownerOf(LIST[3])).toBe('Camille Martin');
            expect(component.ownerOf(LIST[2])).toBe('Grace Hopper');
            expect(component.ownerOf(LIST[0])).toBe('');
            expect(component.ownerOf(LIST[1])).toBe('');
        });

        it('gives nothing for a workspace the server did not name an owner for', () => {
            component.init();

            expect(component.ownerOf({ id: 'unknown', name: 'Elsewhere', role: 'viewer', current: false })).toBe('');
        });

        it('still names the owners once the workspaces page has filled the store again from the API, which does not carry them', () => {
            component.init();

            store.replace([
                { id: 'personal', name: 'Espace personnel', role: 'owner', current: false },
                { id: 'hers', name: 'Espace personnel', role: 'editor', current: true },
            ]);

            expect(component.ownerOf(store.items[1])).toBe('Camille Martin');
            expect(component.ownerOf(store.items[0])).toBe('');
        });

        it("gives the owner of the current workspace for the button of the header, when it is somebody else's", () => {
            component.init();

            expect(component.currentOwner).toBe('');

            store.replace(LIST.map((item) => ({ ...item, current: item.id === 'hers' })));

            expect(component.currentOwner).toBe('Camille Martin');
        });

        it('gives no owner for the button while there is no current workspace', () => {
            expect(component.currentOwner).toBe('');

            component.init();
            store.replace(LIST.map((item) => ({ ...item, current: false })));

            expect(component.currentOwner).toBe('');
        });

        it("puts the name and the owner of the current workspace in the tooltip of the button, only when it is somebody else's", () => {
            component.init();

            expect(component.currentTitle).toBeNull();

            store.replace(LIST.map((item) => ({ ...item, current: item.id === 'hers' })));

            expect(component.currentTitle).toBe('Espace personnel (Owner: Camille Martin)');
        });

        it('mutes the line of the owner, except in the highlighted entry where it would not read on its background', () => {
            expect(component.ownerClass(LIST[3])).toBe('d-block small text-secondary');
            expect(component.ownerClass({ ...LIST[3], current: true })).toBe('d-block small');
        });
    });

    describe('choosing a workspace', () => {
        it('tells the API then goes to the page of the workspace', async () => {
            http.put.mockResolvedValue({ data: {} });

            await component.select(LIST[0]);

            expect(http.put).toHaveBeenCalledWith('/api/v1/me/current-workspace', { workspace: 'personal' });
            expect(redirectTo).toHaveBeenCalledWith('/app/w/personal');
            expect(reloadPage).not.toHaveBeenCalled();
        });

        it('does nothing for the workspace that is already current', async () => {
            await component.select(LIST[1]);

            expect(http.put).not.toHaveBeenCalled();
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('ignores clicks while a change is in flight', async () => {
            http.put.mockResolvedValue({ data: {} });

            await Promise.all([component.select(LIST[0]), component.select(LIST[2])]);

            expect(http.put).toHaveBeenCalledTimes(1);
            expect(redirectTo).toHaveBeenCalledTimes(1);
        });

        it('shows the translated validation message of a workspace the person no longer belongs to', async () => {
            http.put.mockRejectedValue(
                new HttpError(422, 'The given data was invalid.', {
                    errors: { workspace: ['Espace de travail invalide.'] },
                }),
            );

            await component.select(LIST[0]);

            expect(notify).toHaveBeenCalledWith('danger', 'Espace de travail invalide.');
            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('does not repeat what the HTTP helper already told', async () => {
            const error = new HttpError(0, 'Network down', { kind: 'network' });
            error.reported = true;
            http.put.mockRejectedValue(error);

            await component.select(LIST[0]);

            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('stays silent when the request was cancelled', async () => {
            http.put.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.select(LIST[0]);

            expect(notify).not.toHaveBeenCalled();
            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });
    });
});
