import { beforeEach, describe, expect, it } from 'vitest';

import toasts from '../../../resources/js/components/toasts.js';
import { createToastsStore } from '../../../resources/js/stores/toasts.js';

describe('toasts component', () => {
    let store;
    let component;

    beforeEach(() => {
        store = createToastsStore();
        component = toasts();
        // Alpine injects $store; the test provides the same shape.
        component.$store = { toasts: store };
    });

    it('exposes the store lists for the two live regions', () => {
        store.push('success', 'ok', { timeout: 0 });
        store.push('danger', 'broken', { timeout: 0 });

        expect(component.polite.map((toast) => toast.message)).toEqual(['ok']);
        expect(component.assertive.map((toast) => toast.message)).toEqual(['broken']);
    });

    it('maps a toast level to a Tabler border class', () => {
        expect(component.levelClass({ id: 1, type: 'warning', message: '' })).toBe('border-warning');
    });

    it('dismisses through the store', () => {
        const id = store.push('info', 'bye', { timeout: 0 });

        component.dismiss(id);

        expect(store.items).toHaveLength(0);
    });
});
