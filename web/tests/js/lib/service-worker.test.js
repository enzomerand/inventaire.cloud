import { afterEach, describe, expect, it, vi } from 'vitest';

import { registerServiceWorker } from '../../../resources/js/lib/service-worker.js';

/**
 * Puts a window that keeps its listeners (to fire `load` when the test says so) and a navigator that has service workers in the
 * place of the ones of the page (the globals are put back by the configuration of Vitest, the build by `afterEach`).
 *
 * @param {object} [options]
 * @param {boolean} [options.secure] Whether the page is in a secure context.
 * @param {string} [options.readyState] The state of the document.
 * @param {Function} [options.register] What `serviceWorker.register` does.
 * @param {boolean} [options.serviceWorker] Whether the browser has service workers at all.
 * @param {boolean} [options.production] Whether this is the production build.
 * @returns {{ win: object, nav: object }} The window (with `fire`) and the navigator.
 */
function stubPage({
    secure = true,
    readyState = 'loading',
    register = vi.fn(async () => ({})),
    serviceWorker = true,
    production = true,
} = {}) {
    const listeners = {};
    const win = {
        isSecureContext: secure,
        document: { readyState },
        addEventListener: vi.fn((type, handler) => {
            listeners[type] = handler;
        }),
        fire: (type) => listeners[type](),
    };
    const nav = serviceWorker ? { serviceWorker: { register } } : {};

    vi.stubEnv('PROD', production);
    vi.stubGlobal('window', win);
    vi.stubGlobal('navigator', nav);

    return { win, nav };
}

/** Lets the promises that are waiting settle. */
async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('registering the service worker', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('registers /sw.js for the whole site once the page has loaded, not before', async () => {
        const { win, nav } = stubPage();

        registerServiceWorker();

        expect(nav.serviceWorker.register).not.toHaveBeenCalled();

        win.fire('load');
        await settle();

        expect(nav.serviceWorker.register).toHaveBeenCalledTimes(1);
        expect(nav.serviceWorker.register).toHaveBeenCalledWith('/sw.js', { scope: '/' });
    });

    it('registers at once when the page has already loaded', async () => {
        const { win, nav } = stubPage({ readyState: 'complete' });

        registerServiceWorker();
        await settle();

        expect(nav.serviceWorker.register).toHaveBeenCalledWith('/sw.js', { scope: '/' });
        expect(win.addEventListener).not.toHaveBeenCalled();
    });

    it('does nothing in a browser without service workers', () => {
        const { win } = stubPage({ serviceWorker: false });

        registerServiceWorker();

        expect(win.addEventListener).not.toHaveBeenCalled();
    });

    it('does nothing outside a secure context (plain HTTP)', () => {
        const { win, nav } = stubPage({ secure: false });

        registerServiceWorker();

        expect(win.addEventListener).not.toHaveBeenCalled();
        expect(nav.serviceWorker.register).not.toHaveBeenCalled();
    });

    it('ignores a registration that fails, without a word', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const { win, nav } = stubPage({
            register: vi.fn(() => Promise.reject(new DOMException('The operation is insecure.', 'SecurityError'))),
        });

        registerServiceWorker();
        win.fire('load');
        await settle();

        expect(nav.serviceWorker.register).toHaveBeenCalledTimes(1);
        expect(warn).not.toHaveBeenCalled();
        expect(error).not.toHaveBeenCalled();
    });

    it('does nothing in a development build: the worker is for the production one', async () => {
        const { win, nav } = stubPage({ readyState: 'complete', production: false });

        registerServiceWorker();
        await settle();

        expect(win.addEventListener).not.toHaveBeenCalled();
        expect(nav.serviceWorker.register).not.toHaveBeenCalled();
    });
});
