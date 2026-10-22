/**
 * Registration of the service worker (served by `/sw.js`, see `resources/js/sw/service-worker.js`).
 */

/**
 * Registers the service worker once the page has loaded, so that it never competes with the page for the network.
 *
 * Nothing happens where there is no worker to register: a development build (the worker would keep an offline page of the machine of
 * the developer, and `composer dev` and the tests would meet it), a browser without service workers, or a page that is not in a secure
 * context (plain HTTP, except on localhost). A registration that fails (a private window, a browser setting, a network error) is
 * ignored: the application works without the worker, which only adds the offline page.
 *
 * @returns {void}
 */
export function registerServiceWorker() {
    if (!import.meta.env.PROD || !('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }

    const register = async () => {
        try {
            await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        } catch {
            // The offline page is a bonus: there is nothing to tell the person.
        }
    };

    // A script that runs after the load event (a module imported late) would wait for an event that already happened.
    if (window.document.readyState === 'complete') {
        register();
    } else {
        window.addEventListener('load', register, { once: true });
    }
}
