/**
 * Registration of the service worker (served by `/sw.js`, see `resources/js/sw/service-worker.js`).
 */

/**
 * Registers the service worker once the page has loaded, so that it never competes with the page for the network.
 *
 * Nothing happens where there is no worker to register: a browser without service workers, or a page that is not in a secure context (plain
 * HTTP, except on localhost). A registration that fails (a private window, a browser setting, a network error) is ignored: the application
 * works without the worker, which only adds the offline page.
 *
 * @param {Navigator} [nav] The navigator, a parameter for the tests.
 * @param {Window} [win] The window, a parameter for the tests.
 * @returns {void}
 */
export function registerServiceWorker(nav = navigator, win = window) {
    if (!('serviceWorker' in nav) || !win.isSecureContext) {
        return;
    }

    const register = async () => {
        try {
            await nav.serviceWorker.register('/sw.js', { scope: '/' });
        } catch {
            // The offline page is a bonus: there is nothing to tell the person.
        }
    };

    // A script that runs after the load event (a module imported late) would wait for an event that already happened.
    if (win.document.readyState === 'complete') {
        register();
    } else {
        win.addEventListener('load', register, { once: true });
    }
}
