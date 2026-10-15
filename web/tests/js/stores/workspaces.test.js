import { beforeEach, describe, expect, it } from 'vitest';

import { createWorkspacesStore, fromApi } from '../../../resources/js/stores/workspaces.js';

describe('workspaces store', () => {
    let store;

    beforeEach(() => {
        store = createWorkspacesStore();
    });

    it('starts empty, with no current workspace', () => {
        expect(store.items).toEqual([]);
        expect(store.current).toBeNull();
    });

    it('replaces the whole list', () => {
        store.replace([{ id: 'a', name: 'One', role: 'owner', current: false }]);
        store.replace([
            { id: 'b', name: 'Two', role: 'viewer', current: false },
            { id: 'c', name: 'Three', role: 'owner', current: true },
        ]);

        expect(store.items.map((item) => item.id)).toEqual(['b', 'c']);
    });

    it('knows the current workspace', () => {
        store.replace([
            { id: 'a', name: 'One', role: 'owner', current: false },
            { id: 'b', name: 'Two', role: 'viewer', current: true },
        ]);

        expect(store.current.name).toBe('Two');
    });

    it('has no current workspace when none is marked', () => {
        store.replace([{ id: 'a', name: 'One', role: 'owner', current: false }]);

        expect(store.current).toBeNull();
    });

    it('is isolated from the stores made before it', () => {
        store.replace([{ id: 'a', name: 'One', role: 'owner', current: true }]);

        expect(createWorkspacesStore().items).toEqual([]);
    });
});

describe('fromApi', () => {
    it('keeps what the interface uses of a workspace described by the API', () => {
        expect(
            fromApi({
                id: '01abc',
                name: 'Shop',
                role: 'editor',
                is_current: true,
                created_at: '2026-10-15T10:00:00+00:00',
            }),
        ).toEqual({ id: '01abc', name: 'Shop', role: 'editor', current: true });
    });

    it('reads a workspace that is not current as such', () => {
        expect(fromApi({ id: '01abc', name: 'Shop', role: 'owner', is_current: false }).current).toBe(false);
        expect(fromApi({ id: '01abc', name: 'Shop', role: 'owner' }).current).toBe(false);
    });
});
