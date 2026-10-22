import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it, vi } from 'vitest';

/*
 * The service worker is a classic script that the server serves after a line that configures it (`self.INVENTAIRE_SW`). Here it is
 * evaluated like the browser does, in a scope made of fakes: the events it listens to are captured and fired by hand, and the
 * caches, the network and the registration are doubles that record what the worker asks of them.
 */

const SOURCE = readFileSync(resolve(import.meta.dirname, '../../../resources/js/sw/service-worker.js'), 'utf8');

const ORIGIN = 'https://inventaire.test';

/** The few things of `Request` the worker reads or builds. */
class FakeRequest {
    constructor(url, init = {}) {
        this.url = url;
        this.init = init;
        this.mode = init.mode ?? 'cors';
        this.method = init.method ?? 'GET';
    }
}

/** The few things of `Response` the worker reads or builds. */
class FakeResponse {
    constructor(label, status = 200) {
        this.label = label;
        this.status = status;
    }

    static error() {
        return new FakeResponse('network error', 0);
    }
}

/**
 * A scope the worker can run in.
 *
 * @param {object} [options]
 * @param {string} [options.version] The version the server wrote in the configuration.
 * @param {string} [options.offlineUrl] The address of the offline page the server wrote in the configuration.
 * @param {string[]} [options.existing] The names of the caches that already exist.
 * @returns {object} The scope, its doubles and the handlers the worker registered.
 */
function createWorker({ version = 'v1', offlineUrl = '/offline', existing = [] } = {}) {
    const handlers = {};
    const order = [];
    const stores = new Map(existing.map((name) => [name, makeCache(name)]));

    function makeCache(name) {
        const entries = new Map();

        return {
            name,
            entries,
            add: vi.fn(async (request) => {
                order.push('add');
                entries.set(request.url, new FakeResponse(`offline page of ${name}`));
            }),
            put: vi.fn(async () => {}),
        };
    }

    const caches = {
        open: vi.fn(async (name) => {
            if (!stores.has(name)) {
                stores.set(name, makeCache(name));
            }

            return stores.get(name);
        }),
        keys: vi.fn(async () => [...stores.keys()]),
        delete: vi.fn(async (name) => stores.delete(name)),
        match: vi.fn(async (url, options = {}) => {
            const names = options.cacheName ? [options.cacheName] : [...stores.keys()];

            return names.map((name) => stores.get(name)?.entries.get(url)).find(Boolean);
        }),
    };

    const scope = {
        INVENTAIRE_SW: { version, offlineUrl },
        addEventListener: (type, handler) => {
            handlers[type] = handler;
        },
        skipWaiting: vi.fn(async () => {
            order.push('skipWaiting');
        }),
        clients: {
            claim: vi.fn(async () => {
                order.push('claim');
            }),
        },
        registration: {},
    };
    const network = vi.fn();

    new Function('self', 'caches', 'fetch', 'Request', 'Response', SOURCE)(
        scope,
        caches,
        network,
        FakeRequest,
        FakeResponse,
    );

    return { scope, caches, stores, network, handlers, order };
}

/** An `install` or `activate` event, which keeps the promise the worker asks to wait for. */
function lifecycleEvent() {
    const event = {
        waitUntil: vi.fn((promise) => {
            event.pending = promise;
        }),
    };

    return event;
}

/** A `fetch` event for a request: it keeps the promises the worker asks to wait for (`waitUntil`) in `extended`. */
function fetchEvent(url, init = {}) {
    const event = {
        request: new FakeRequest(url, init),
        extended: [],
        respondWith: vi.fn(),
        waitUntil: vi.fn((promise) => {
            event.extended.push(promise);
        }),
    };

    return event;
}

/** The navigation of a person to a page. */
function navigation(path = '/app', init = {}) {
    return fetchEvent(ORIGIN + path, { mode: 'navigate', ...init });
}

/** What the worker answered to a fetch event. */
async function answerOf(event) {
    expect(event.respondWith).toHaveBeenCalledTimes(1);

    return event.respondWith.mock.calls[0][0];
}

describe('the worker file', () => {
    it('is a classic script: no import, no export', () => {
        expect(SOURCE).not.toMatch(/^\s*(import|export)\b/m);
    });

    it('listens to the three events it needs, and nothing else', () => {
        expect(Object.keys(createWorker().handlers).sort()).toEqual(['activate', 'fetch', 'install']);
    });
});

describe('installing', () => {
    it('keeps a fresh copy of the offline page in the cache of its version, then takes over at once', async () => {
        const worker = createWorker();
        const event = lifecycleEvent();

        worker.handlers.install(event);
        await event.pending;

        expect(event.waitUntil).toHaveBeenCalledTimes(1);
        expect(worker.caches.open).toHaveBeenCalledWith('inventaire-offline-v1');

        const cache = worker.stores.get('inventaire-offline-v1');
        const [request] = cache.add.mock.calls[0];

        expect(cache.add).toHaveBeenCalledTimes(1);
        expect(request).toBeInstanceOf(FakeRequest);
        expect(request.url).toBe('/offline');
        // Not the copy of the HTTP cache of the browser: the page of this version of the application.
        expect(request.init).toEqual({ cache: 'reload' });
        expect(worker.scope.skipWaiting).toHaveBeenCalledTimes(1);
        expect(worker.order).toEqual(['add', 'skipWaiting']);
    });

    it('does not install, and does not take over, when the page cannot be kept', async () => {
        const worker = createWorker();
        const event = lifecycleEvent();
        const cache = await worker.caches.open('inventaire-offline-v1');
        cache.add.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        worker.handlers.install(event);

        await expect(event.pending).rejects.toThrow('Failed to fetch');
        expect(worker.scope.skipWaiting).not.toHaveBeenCalled();
    });

    it('names the cache and the page after the configuration the server wrote in front of it', async () => {
        const worker = createWorker({ version: '0123456789abcdef', offlineUrl: '/hors-ligne' });
        const event = lifecycleEvent();

        worker.handlers.install(event);
        await event.pending;

        expect(worker.caches.open).toHaveBeenCalledWith('inventaire-offline-0123456789abcdef');
        expect(worker.stores.get('inventaire-offline-0123456789abcdef').add.mock.calls[0][0].url).toBe('/hors-ligne');
    });
});

describe('activating', () => {
    it('deletes the caches of the other versions, and only those of the application', async () => {
        const worker = createWorker({
            existing: [
                'inventaire-offline-v0',
                'inventaire-offline-v1',
                'inventaire-something-else',
                'workbox-precache',
                'inventaire',
            ],
        });
        const event = lifecycleEvent();

        worker.handlers.activate(event);
        await event.pending;

        expect([...worker.stores.keys()].sort()).toEqual(['inventaire', 'inventaire-offline-v1', 'workbox-precache']);
        expect(worker.caches.delete).toHaveBeenCalledWith('inventaire-offline-v0');
        expect(worker.caches.delete).toHaveBeenCalledWith('inventaire-something-else');
        expect(worker.caches.delete).toHaveBeenCalledTimes(2);
    });

    it('takes control of the open pages once the old caches are gone', async () => {
        const worker = createWorker({ existing: ['inventaire-offline-v0'] });
        const event = lifecycleEvent();

        worker.handlers.activate(event);
        await event.pending;

        expect(worker.caches.delete).toHaveBeenCalledWith('inventaire-offline-v0');
        expect(worker.scope.clients.claim).toHaveBeenCalledTimes(1);
    });

    it('does not use navigation preload: it would double the requests of the navigations the worker leaves alone', async () => {
        const enable = vi.fn(async () => {});
        const worker = createWorker();
        worker.scope.registration = { navigationPreload: { enable } };
        const event = lifecycleEvent();

        worker.handlers.activate(event);
        await event.pending;

        expect(enable).not.toHaveBeenCalled();
        expect(SOURCE).not.toMatch(/navigationPreload|preloadResponse/);
    });
});

describe('a navigation', () => {
    it('is answered by the network when it works', async () => {
        const worker = createWorker();
        const page = new FakeResponse('the page');
        worker.network.mockResolvedValue(page);
        const event = navigation('/app/w/abc');

        worker.handlers.fetch(event);

        expect(await answerOf(event)).toBe(page);
        expect(worker.network).toHaveBeenCalledWith(event.request);
    });

    it('shows a page of the server that is an error as it is: only a missing network is "offline"', async () => {
        const worker = createWorker();
        const down = new FakeResponse('maintenance', 503);
        worker.network.mockResolvedValue(down);
        await worker.caches.open('inventaire-offline-v1');
        const event = navigation();

        worker.handlers.fetch(event);

        expect(await answerOf(event)).toBe(down);
    });

    it('is answered with the offline page when the network fails', async () => {
        const worker = createWorker();
        const install = lifecycleEvent();
        worker.handlers.install(install);
        await install.pending;
        worker.network.mockRejectedValue(new TypeError('Failed to fetch'));
        const event = navigation('/app/w/abc/inventories/xyz');

        worker.handlers.fetch(event);
        const answer = await answerOf(event);

        expect(answer.label).toBe('offline page of inventaire-offline-v1');
        // The page was cached with `Vary: Accept-Language, Cookie`: the request of the navigation does not have to match it.
        expect(worker.caches.match).toHaveBeenCalledWith('/offline', {
            cacheName: 'inventaire-offline-v1',
            ignoreVary: true,
        });
    });

    it('is a network error, the browser own page, when there is no network and no copy of the offline page', async () => {
        const worker = createWorker();
        worker.network.mockRejectedValue(new TypeError('Failed to fetch'));
        const event = navigation();

        worker.handlers.fetch(event);
        const answer = await answerOf(event);

        expect(answer).toBeInstanceOf(FakeResponse);
        expect(answer.label).toBe('network error');
    });

    it('never stores anything but the offline page', async () => {
        const worker = createWorker();
        const install = lifecycleEvent();
        worker.handlers.install(install);
        await install.pending;
        worker.network.mockResolvedValue(new FakeResponse('the page'));

        const event = navigation('/app/account');
        worker.handlers.fetch(event);
        await answerOf(event);

        const cache = worker.stores.get('inventaire-offline-v1');

        expect([...cache.entries.keys()]).toEqual(['/offline']);
        expect(cache.put).not.toHaveBeenCalled();
        expect(worker.stores.size).toBe(1);
    });
});

describe('the copy of the offline page', () => {
    /** A worker that is installed, then whose cache loses the page (the browser evicted it). */
    async function evicted() {
        const worker = createWorker();
        const install = lifecycleEvent();

        worker.handlers.install(install);
        await install.pending;

        const cache = worker.stores.get('inventaire-offline-v1');

        cache.entries.clear();
        cache.add.mockClear();

        return { worker, cache };
    }

    it('is put back after a navigation that reached the server, without making the page wait for it', async () => {
        const { worker, cache } = await evicted();
        let release;
        cache.add.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    release = () => {
                        cache.entries.set('/offline', new FakeResponse('offline page again'));
                        resolve();
                    };
                }),
        );
        const page = new FakeResponse('the page');
        worker.network.mockResolvedValue(page);
        const event = navigation('/app');

        worker.handlers.fetch(event);

        // The answer is the page, while the copy is still being fetched.
        expect(await answerOf(event)).toBe(page);
        expect(event.waitUntil).toHaveBeenCalledTimes(1);
        await vi.waitFor(() => expect(cache.add).toHaveBeenCalledTimes(1));
        expect(cache.add.mock.calls[0][0].url).toBe('/offline');
        expect(cache.add.mock.calls[0][0].init).toEqual({ cache: 'reload' });

        release();
        await event.extended[0];

        expect(cache.entries.get('/offline').label).toBe('offline page again');
    });

    it('is put back after a page that is an error of the server too: the network works', async () => {
        const { worker, cache } = await evicted();
        const down = new FakeResponse('maintenance', 503);
        worker.network.mockResolvedValue(down);
        const event = navigation('/app');

        worker.handlers.fetch(event);

        expect(await answerOf(event)).toBe(down);
        await event.extended[0];
        expect(cache.add).toHaveBeenCalledTimes(1);
    });

    it('is left alone when it is still there', async () => {
        const { worker, cache } = await evicted();
        cache.entries.set('/offline', new FakeResponse('offline page'));
        worker.network.mockResolvedValue(new FakeResponse('the page'));
        const event = navigation('/app');

        worker.handlers.fetch(event);
        await answerOf(event);
        await event.extended[0];

        expect(cache.add).not.toHaveBeenCalled();
        expect(worker.caches.match).toHaveBeenCalledWith('/offline', {
            cacheName: 'inventaire-offline-v1',
            ignoreVary: true,
        });
    });

    it('never fails the navigation when it cannot be put back', async () => {
        const { worker, cache } = await evicted();
        cache.add.mockRejectedValue(new TypeError('Failed to fetch'));
        const page = new FakeResponse('the page');
        worker.network.mockResolvedValue(page);
        const event = navigation('/app');

        worker.handlers.fetch(event);

        expect(await answerOf(event)).toBe(page);
        await expect(event.extended[0]).resolves.toBeUndefined();
        expect(cache.add).toHaveBeenCalledTimes(1);
    });

    it('is not fetched twice by navigations that find it missing at the same time', async () => {
        const { worker, cache } = await evicted();
        let release;
        cache.add.mockImplementation(
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );
        worker.network.mockResolvedValue(new FakeResponse('the page'));
        const first = navigation('/app');
        const second = navigation('/app/account');

        worker.handlers.fetch(first);
        worker.handlers.fetch(second);
        await answerOf(first);
        await answerOf(second);
        await vi.waitFor(() => expect(cache.add).toHaveBeenCalledTimes(1));
        release();
        await Promise.all([...first.extended, ...second.extended]);

        expect(cache.add).toHaveBeenCalledTimes(1);
    });

    it('is not touched when there is no network: the page of the cache is the answer', async () => {
        const { worker, cache } = await evicted();
        worker.network.mockRejectedValue(new TypeError('Failed to fetch'));
        const event = navigation('/app');

        worker.handlers.fetch(event);

        expect((await answerOf(event)).label).toBe('network error');
        expect(event.waitUntil).not.toHaveBeenCalled();
        expect(cache.add).not.toHaveBeenCalled();
    });
});

describe('what is left to the browser', () => {
    /** Fires a fetch event and says whether the worker answered it. */
    function answered(event) {
        const worker = createWorker();
        worker.handlers.fetch(event);

        return event.respondWith.mock.calls.length > 0;
    }

    it.each([
        ['a script', { mode: 'no-cors' }, '/build/assets/app.js'],
        ['a request of the API from the page', { mode: 'cors' }, '/api/v1/inventories'],
        ['an image', { mode: 'no-cors' }, '/icons/icon-192.png'],
        ['the manifest', { mode: 'cors' }, '/manifest.webmanifest'],
        ['a same-origin request of a page', { mode: 'same-origin' }, '/app'],
    ])('does not touch %s', (_name, init, path) => {
        expect(answered(fetchEvent(ORIGIN + path, init))).toBe(false);
    });

    it('does not touch the submission of a form (a navigation that is not a GET)', () => {
        expect(answered(fetchEvent(`${ORIGIN}/app/account`, { mode: 'navigate', method: 'POST' }))).toBe(false);
        expect(answered(fetchEvent(`${ORIGIN}/app/account`, { mode: 'navigate', method: 'DELETE' }))).toBe(false);
    });

    it('does not touch the link to a file of the API (an export, an attachment): it is a download, not a page', () => {
        expect(answered(fetchEvent(`${ORIGIN}/api/v1/exports/01HX/download`, { mode: 'navigate' }))).toBe(false);
    });

    it.each([
        ['an attachment', '/s/AbC123_token/items/01HX/attachments/01HY'],
        ['a thumbnail', '/s/AbC123_token/items/01HX/attachments/01HY/thumbnail'],
    ])('does not touch the link to %s of a share link: it is a download, not a page', (_name, path) => {
        expect(answered(fetchEvent(ORIGIN + path, { mode: 'navigate' }))).toBe(false);
    });

    it('answers the navigations to the pages of the application, even the ones whose path only looks like a file', () => {
        expect(answered(fetchEvent(`${ORIGIN}/apiary`, { mode: 'navigate' }))).toBe(true);
        expect(answered(fetchEvent(`${ORIGIN}/docs/api`, { mode: 'navigate' }))).toBe(true);
        // The page of a share link is a page; only the files it shows are not.
        expect(answered(fetchEvent(`${ORIGIN}/s/AbC123_token`, { mode: 'navigate' }))).toBe(true);
        expect(answered(fetchEvent(`${ORIGIN}/s/AbC123_token/`, { mode: 'navigate' }))).toBe(true);
        expect(answered(fetchEvent(`${ORIGIN}/app/w/abc/items/01HX`, { mode: 'navigate' }))).toBe(true);
    });
});
