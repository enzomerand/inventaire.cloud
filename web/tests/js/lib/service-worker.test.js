import { describe, expect, it, vi } from 'vitest';

import { registerServiceWorker } from '../../../resources/js/lib/service-worker.js';

/**
 * A window that keeps its listeners, to fire `load` when the test says so.
 *
 * @param {object} [options]
 * @param {boolean} [options.secure] Whether the page is in a secure context.
 * @param {string} [options.readyState] The state of the document.
 * @returns {object} The window.
 */
function fakeWindow({ secure = true, readyState = 'loading' } = {}) {
    const listeners = {};

    return {
        isSecureContext: secure,
        document: { readyState },
        addEventListener: vi.fn((type, handler) => {
            listeners[type] = handler;
        }),
        fire: (type) => listeners[type](),
    };
}

/**
 * A navigator that has service workers, and the function that registers them.
 *
 * @param {Function} [register] What `serviceWorker.register` does.
 * @returns {object} The navigator.
 */
function fakeNavigator(register = vi.fn(async () => ({}))) {
    return { serviceWorker: { register } };
}

/** Lets the promises that are waiting settle. */
async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('registering the service worker', () => {
    it('registers /sw.js for the whole site once the page has loaded, not before', async () => {
        const nav = fakeNavigator();
        const win = fakeWindow();

        registerServiceWorker(nav, win, true);

        expect(nav.serviceWorker.register).not.toHaveBeenCalled();

        win.fire('load');
        await settle();

        expect(nav.serviceWorker.register).toHaveBeenCalledTimes(1);
        expect(nav.serviceWorker.register).toHaveBeenCalledWith('/sw.js', { scope: '/' });
    });

    it('registers at once when the page has already loaded', async () => {
        const nav = fakeNavigator();
        const win = fakeWindow({ readyState: 'complete' });

        registerServiceWorker(nav, win, true);
        await settle();

        expect(nav.serviceWorker.register).toHaveBeenCalledWith('/sw.js', { scope: '/' });
        expect(win.addEventListener).not.toHaveBeenCalled();
    });

    it('does nothing in a browser without service workers', () => {
        const win = fakeWindow();

        registerServiceWorker({}, win, true);

        expect(win.addEventListener).not.toHaveBeenCalled();
    });

    it('does nothing outside a secure context (plain HTTP)', () => {
        const nav = fakeNavigator();
        const win = fakeWindow({ secure: false });

        registerServiceWorker(nav, win, true);

        expect(win.addEventListener).not.toHaveBeenCalled();
        expect(nav.serviceWorker.register).not.toHaveBeenCalled();
    });

    it('ignores a registration that fails, without a word', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const nav = fakeNavigator(
            vi.fn(() => Promise.reject(new DOMException('The operation is insecure.', 'SecurityError'))),
        );
        const win = fakeWindow();

        registerServiceWorker(nav, win, true);
        win.fire('load');
        await settle();

        expect(nav.serviceWorker.register).toHaveBeenCalledTimes(1);
        expect(warn).not.toHaveBeenCalled();
        expect(error).not.toHaveBeenCalled();
    });

    it('ignores a browser that refuses at once, by throwing', async () => {
        const nav = fakeNavigator(
            vi.fn(() => {
                throw new TypeError('Failed to register a ServiceWorker');
            }),
        );
        const win = fakeWindow();

        registerServiceWorker(nav, win, true);
        win.fire('load');
        await settle();

        expect(nav.serviceWorker.register).toHaveBeenCalledTimes(1);
    });

    it('does nothing in a development build: the worker is for the production one', async () => {
        const nav = fakeNavigator();
        const win = fakeWindow({ readyState: 'complete' });

        registerServiceWorker(nav, win, false);
        await settle();

        expect(win.addEventListener).not.toHaveBeenCalled();
        expect(nav.serviceWorker.register).not.toHaveBeenCalled();
    });

    it('registers only in a production build by default (Vitest is not one)', async () => {
        const nav = fakeNavigator();
        const win = fakeWindow({ readyState: 'complete' });

        registerServiceWorker(nav, win);
        await settle();

        expect(import.meta.env.PROD).toBe(false);
        expect(nav.serviceWorker.register).not.toHaveBeenCalled();
    });

    it('works with the navigator and the window of the page by default', () => {
        // jsdom has no service workers: nothing to register, and nothing that throws.
        expect(() => registerServiceWorker()).not.toThrow();
        expect(() => registerServiceWorker(navigator, window, true)).not.toThrow();
    });
});
