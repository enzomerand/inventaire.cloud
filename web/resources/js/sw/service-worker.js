/**
 * Service worker of the application.
 *
 * What it does, and all it does: when a page cannot be fetched because the network is down, it shows the page `/offline` in place of
 * the browser's error page. That page is the only thing it ever stores.
 *
 * What it never does: store a page of the application, an answer of the API or a file. Those are private (the data of an account, on a
 * device that may be shared), and an old copy of them would be worse than an error. So the network always comes first, and only the
 * requests that load a page (navigations) go through the worker: every other request (scripts, styles, images, the API) is left to the
 * browser, which is also why this file has no cache of assets to keep up to date.
 *
 * This is a classic script, not a module: the browser runs it as it is, and `/sw.js` (ServiceWorkerScript) writes
 * `self.INVENTAIRE_SW = { version, offlineUrl }` in front of it. The version names the cache, so that a new version keeps its own copy of
 * the offline page and deletes the ones of the versions before it.
 */
(() => {
    'use strict';

    const { version, offlineUrl } = self.INVENTAIRE_SW;

    /** Every cache of this application starts with this, so that the worker never touches one that belongs to something else. */
    const CACHE_PREFIX = 'inventaire-';

    /** The cache of this version of the worker: it holds the offline page and nothing else. */
    const CACHE_NAME = `${CACHE_PREFIX}offline-${version}`;

    /**
     * Keeps a fresh copy of the offline page, skipping the HTTP cache of the browser so that the copy is the page of this version.
     *
     * @returns {Promise<void>} Rejects when the page cannot be fetched or is not a success: the worker then does not install.
     */
    async function keepOfflinePage() {
        const cache = await caches.open(CACHE_NAME);

        await cache.add(new Request(offlineUrl, { cache: 'reload' }));
    }

    /**
     * Deletes the caches of the other versions of the worker. Caches that do not start with the prefix are not ours and stay.
     *
     * @returns {Promise<void>}
     */
    async function forgetOldCaches() {
        const names = await caches.keys();

        await Promise.all(
            names
                .filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
                .map((name) => caches.delete(name)),
        );
    }

    /**
     * Lets the browser ask the server for a page while the worker starts, instead of after: the worker then adds no waiting to a navigation.
     *
     * @returns {Promise<void>}
     */
    async function preloadNavigations() {
        if (self.registration.navigationPreload) {
            await self.registration.navigationPreload.enable();
        }
    }

    /**
     * The answer to a navigation: the one of the network, or the offline page when there is no network.
     *
     * Only a request that fails (the promise of the fetch rejects) is a missing network: an error status of the server (a 404, a 500, a
     * 503 in maintenance) is a page like any other and is shown as it is.
     *
     * @param {FetchEvent} event The navigation.
     * @returns {Promise<Response>}
     */
    async function answerNavigation(event) {
        try {
            const preloaded = await event.preloadResponse;

            return preloaded || (await fetch(event.request));
        } catch {
            const offline = await caches.match(offlineUrl, { cacheName: CACHE_NAME });

            return offline || Response.error();
        }
    }

    /**
     * Whether the worker answers this request: the loading of a page (a GET navigation), except for the files of the API.
     *
     * A link to a file of the API (an export, an attachment) is a navigation too, but its answer is a download and not a page: when it
     * fails, the offline page must not take the place of the browser's own message, and the request goes out as the browser made it.
     *
     * @param {Request} request The request of the event.
     * @returns {boolean}
     */
    function answers(request) {
        return (
            request.mode === 'navigate' &&
            request.method === 'GET' &&
            !new URL(request.url).pathname.startsWith('/api/')
        );
    }

    self.addEventListener('install', (event) => {
        // The new worker takes over at once, instead of waiting for every tab of the old one to close.
        event.waitUntil(keepOfflinePage().then(() => self.skipWaiting()));
    });

    self.addEventListener('activate', (event) => {
        event.waitUntil(Promise.all([forgetOldCaches(), preloadNavigations()]).then(() => self.clients.claim()));
    });

    self.addEventListener('fetch', (event) => {
        // Not answering is what leaves a request to the browser.
        if (answers(event.request)) {
            event.respondWith(answerNavigation(event));
        }
    });
})();
