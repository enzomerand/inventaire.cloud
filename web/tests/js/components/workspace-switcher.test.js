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
    { id: 'personal', name: 'Espace personnel', role: 'owner', current: false },
    { id: 'shop', name: 'Shop', role: 'owner', current: true },
    { id: 'shared', name: 'Shared', role: 'viewer', current: false },
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
