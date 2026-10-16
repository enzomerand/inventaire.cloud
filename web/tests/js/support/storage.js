/**
 * A `localStorage` for the tests: the one the test environment has is a stub without methods (Node's, which hides jsdom's).
 */
import { vi } from 'vitest';

/**
 * A storage that keeps its entries in memory, like the browser's, until the test ends.
 *
 * @returns {Storage}
 */
export function memoryStorage() {
    const entries = new Map();

    return {
        get length() {
            return entries.size;
        },
        key: (index) => [...entries.keys()][index] ?? null,
        getItem: (key) => entries.get(String(key)) ?? null,
        setItem: (key, value) => {
            entries.set(String(key), String(value));
        },
        removeItem: (key) => {
            entries.delete(String(key));
        },
        clear: () => entries.clear(),
    };
}

/**
 * Gives the test an empty storage (put back on its own by the test runner when the test ends).
 *
 * @returns {Storage}
 */
export function useMemoryStorage() {
    const storage = memoryStorage();

    vi.stubGlobal('localStorage', storage);

    return storage;
}

/**
 * Gives the test a storage that refuses every access, as a private window or blocked site data do.
 *
 * @returns {void}
 */
export function useRefusingStorage() {
    const refuse = () => {
        throw new DOMException('The operation is insecure.', 'SecurityError');
    };

    vi.stubGlobal('localStorage', { getItem: refuse, setItem: refuse, removeItem: refuse, clear: refuse });
}
