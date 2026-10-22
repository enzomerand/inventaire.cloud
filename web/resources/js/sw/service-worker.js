/**
 * Service worker of the application.
 *
 * What it does, and all it does: when a page cannot be fetched because the network is down, it shows the page `/offline` in place of
 * the browser's error page. That page is the only thing it ever stores.
 *
 * What it never does: store a page of the application, an answer of the API or a file. Those are private (the data of an account, on a
 * device that may be shared), and an old copy of them would be worse than an error. So the network always comes first, and only the
 * requests that load a page (navigations) go through the worker: every other request (scripts, styles, images, the API) is left to the
 * browser, which is also why this file has no cache of assets to keep up to date. Navigation preload is not used either: the browser
 * would ask the server for every navigation the worker does not answer (a download, a form) on top of the worker's own request.
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

    /** The path of a file shown by a share link: the attachments and thumbnails of its items. */
    const SHARED_FILE = /^\/s\/[^/]+\/items\//;

    /** The restoration of the offline page that is under way, null when none is. */
    let restoring = null;

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
     * The copy of the offline page in the cache of this version. The copy was written with a `Vary` (the language, the cookies) that
     * the request asking for it does not have to match, which `ignoreVary` allows.
     *
     * @returns {Promise<Response | undefined>} Undefined when the cache has no copy.
     */
    function cachedOfflinePage() {
        return caches.match(offlineUrl, { cacheName: CACHE_NAME, ignoreVary: true });
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
     * Puts the offline page back in the cache of this version when the browser has evicted it (a device short of room, or a person who
     * cleared the site data without removing the worker): without it the worker could only answer an offline navigation with the error page
     * of the browser. It runs once the page of a navigation has arrived, so that it neither waits for the network nor blocks the page, and
     * it never fails: the next navigation tries again. Two navigations that find the page missing share one request.
     *
     * @returns {Promise<void>}
     */
    function restoreOfflinePage() {
        restoring ??= (async () => {
            try {
                if (!(await cachedOfflinePage())) {
                    await keepOfflinePage();
                }
            } catch {
                // The offline page is a bonus, and the person is reading the page they asked for.
            } finally {
                restoring = null;
            }
        })();

        return restoring;
    }

    /**
     * The answer to a navigation: the one of the network, or the offline page when there is no network.
     *
     * Only a request that fails (the promise of the fetch rejects) is a missing network: an error status of the server (a 404, a 500, a
     * 503 in maintenance) is a page like any other and is shown as it is, and proves that the network works: the offline page is then
     * checked, and restored when it is missing, without making the person wait.
     *
     * @param {FetchEvent} event The navigation.
     * @returns {Promise<Response>}
     */
    async function answerNavigation(event) {
        let page;

        try {
            page = await fetch(event.request);
        } catch {
            return (await cachedOfflinePage()) || Response.error();
        }

        // Still allowed here (the answer is not settled yet), but the page must never fail because of the repair.
        try {
            event.waitUntil(restoreOfflinePage());
        } catch {
            // The next navigation repairs it.
        }

        return page;
    }

    /**
     * Whether the path is one of a file: the files of the API (an export, an attachment), and the ones a public share link shows (the
     * attachments and the thumbnails of its items, `/s/{token}/items/...`; the page of the link itself, `/s/{token}`, is a page).
     *
     * @param {string} pathname
     * @returns {boolean}
     */
    function isFile(pathname) {
        return pathname.startsWith('/api/') || SHARED_FILE.test(pathname);
    }

    /**
     * Whether the worker answers this request: the loading of a page (a GET navigation), except for the files.
     *
     * A link to a file (an export, an attachment) is a navigation too, but its answer is a download and not a page: when it fails, the
     * offline page must not take the place of the browser's own message, and the request goes out as the browser made it.
     *
     * @param {Request} request The request of the event.
     * @returns {boolean}
     */
    function answers(request) {
        return request.mode === 'navigate' && request.method === 'GET' && !isFile(new URL(request.url).pathname);
    }

    self.addEventListener('install', (event) => {
        // The new worker takes over at once, instead of waiting for every tab of the old one to close.
        event.waitUntil(keepOfflinePage().then(() => self.skipWaiting()));
    });

    self.addEventListener('activate', (event) => {
        event.waitUntil(forgetOldCaches().then(() => self.clients.claim()));
    });

    self.addEventListener('fetch', (event) => {
        // Not answering is what leaves a request to the browser.
        if (answers(event.request)) {
            event.respondWith(answerNavigation(event));
        }
    });
})();
