import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_TIMEOUT_MS, MAX_VISIBLE, createToastsStore } from '../../../resources/js/stores/toasts.js';

describe('toasts store', () => {
    let store;

    beforeEach(() => {
        vi.useFakeTimers();
        store = createToastsStore();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('pushes a toast and returns its id', () => {
        const id = store.push('success', 'Saved');

        expect(store.items).toEqual([{ id, type: 'success', message: 'Saved' }]);
    });

    it('gives each toast a distinct id', () => {
        expect(store.push('info', 'a')).not.toBe(store.push('info', 'b'));
    });

    it('falls back to info for an unknown type', () => {
        store.push('mystery', 'Hello');

        expect(store.items[0].type).toBe('info');
    });

    it('dismisses a toast by id and ignores unknown ids', () => {
        const first = store.push('info', 'first', { timeout: 0 });
        store.push('info', 'second', { timeout: 0 });

        store.dismiss(first);
        store.dismiss(999);

        expect(store.items.map((toast) => toast.message)).toEqual(['second']);
    });

    it('auto-dismisses after the default timeout', () => {
        store.push('info', 'bye');

        vi.advanceTimersByTime(DEFAULT_TIMEOUT_MS - 1);
        expect(store.items).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(store.items).toHaveLength(0);
    });

    it('honours a custom timeout', () => {
        store.push('info', 'quick', { timeout: 100 });

        vi.advanceTimersByTime(100);

        expect(store.items).toHaveLength(0);
    });

    it('keeps a toast with no timeout until it is closed', () => {
        const id = store.push('danger', 'sticky', { timeout: 0 });

        vi.advanceTimersByTime(10 * DEFAULT_TIMEOUT_MS);
        expect(store.items).toHaveLength(1);

        store.dismiss(id);
        expect(store.items).toHaveLength(0);
    });

    it('cancels the timer when dismissed early so a reused id is never hit', () => {
        const clear = vi.spyOn(globalThis, 'clearTimeout');
        const id = store.push('info', 'early');

        store.dismiss(id);

        expect(clear).toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('drops the oldest toasts beyond the visible limit', () => {
        for (let i = 0; i < MAX_VISIBLE + 2; i++) {
            store.push('info', `toast ${i}`, { timeout: 0 });
        }

        expect(store.items).toHaveLength(MAX_VISIBLE);
        expect(store.items[0].message).toBe('toast 2');
    });

    it('splits toasts between polite and assertive live regions', () => {
        store.push('success', 'ok', { timeout: 0 });
        store.push('info', 'fyi', { timeout: 0 });
        store.push('warning', 'careful', { timeout: 0 });
        store.push('danger', 'broken', { timeout: 0 });

        expect(store.polite.map((toast) => toast.message)).toEqual(['ok', 'fyi']);
        expect(store.assertive.map((toast) => toast.message)).toEqual(['careful', 'broken']);
    });
});
