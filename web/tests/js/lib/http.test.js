import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn(), redirectTo: vi.fn() }));

const MESSAGES = {
    'error.generic': 'Generic failure',
    'error.network': 'Network down',
    'error.timeout': 'Too slow',
    'error.server': 'Server trouble',
    'error.session_expired': 'Session expired',
    'error.unauthenticated': 'Please sign in',
    'error.forbidden': 'Not allowed',
    'error.not_found': 'Not found',
    'error.too_many_requests': 'Slow down',
    'error.unexpected_response': 'Unexpected answer',
};

/** Builds a fetch Response carrying a JSON body. */
function jsonResponse(body, status = 200, headers = {}) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', ...headers },
    });
}

/**
 * A fetch that never answers and rejects like the real one when aborted, even
 * when the signal was aborted before the call.
 */
function hangUntilAborted(signal) {
    return new Promise((_resolve, reject) => {
        const abort = () => reject(new DOMException('Aborted', 'AbortError'));

        if (signal.aborted) {
            abort();
        } else {
            signal.addEventListener('abort', abort);
        }
    });
}

/** Sets or clears (value = null) a browser cookie. */
function setCookie(name, value) {
    document.cookie =
        value === null ? `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT` : `${name}=${encodeURIComponent(value)}`;
}

describe('http helper', () => {
    const fetchMock = vi.fn();
    let http;
    let HttpError;
    let leaveStalePage;
    let DEFAULT_TIMEOUT_MS;
    let notify;
    let reloadPage;
    let redirectTo;

    beforeEach(async () => {
        // Fresh modules each time: the helper keeps module-level state (pending
        // navigation, shared CSRF refresh) that must not leak between cases.
        vi.resetModules();
        ({ http, HttpError, DEFAULT_TIMEOUT_MS, leaveStalePage } = await import('../../../resources/js/lib/http.js'));
        ({ notify } = await import('../../../resources/js/lib/notify.js'));
        ({ reloadPage, redirectTo } = await import('../../../resources/js/lib/page.js'));

        document.head.innerHTML = '<meta name="csrf-token" content="meta-token">';
        document.body.innerHTML = `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>`;
        document.documentElement.lang = 'fr';
        setCookie('XSRF-TOKEN', null);
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        fetchMock.mockReset();
    });

    /** Makes every fetch call answer with a fresh response built by `build`. */
    function answerWith(build) {
        fetchMock.mockImplementation(() => Promise.resolve(build()));
    }

    describe('request building', () => {
        it('sends the standard headers and resolves the parsed JSON', async () => {
            answerWith(() => jsonResponse({ ok: true }));

            await expect(http.get('/api/ping')).resolves.toEqual({ ok: true });

            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/api/ping');
            expect(init.method).toBe('GET');
            expect(init.credentials).toBe('same-origin');
            expect(init.body).toBeUndefined();
            expect(init.headers).toEqual({
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': 'meta-token',
                'Accept-Language': 'fr',
            });
        });

        it('prefers the decoded XSRF-TOKEN cookie over the meta token', async () => {
            setCookie('XSRF-TOKEN', 'abc==/+value');
            answerWith(() => jsonResponse({}));

            await http.get('/x');

            const { headers } = fetchMock.mock.calls[0][1];
            expect(headers['X-XSRF-TOKEN']).toBe('abc==/+value');
            expect(headers).not.toHaveProperty('X-CSRF-TOKEN');
        });

        it('follows token rotation between two requests', async () => {
            answerWith(() => jsonResponse({}));

            setCookie('XSRF-TOKEN', 'first');
            await http.get('/x');
            setCookie('XSRF-TOKEN', 'second');
            await http.get('/x');

            expect(fetchMock.mock.calls.map(([, init]) => init.headers['X-XSRF-TOKEN'])).toEqual(['first', 'second']);
        });

        it('serializes the body as JSON for verbs that carry one', async () => {
            answerWith(() => jsonResponse({ id: 1 }, 201));

            await http.post('/items', { name: 'Chair' });
            await http.put('/items/1', { name: 'Table' });
            await http.patch('/items/1', { name: 'Desk' });

            expect(fetchMock.mock.calls.map(([, init]) => init.method)).toEqual(['POST', 'PUT', 'PATCH']);
            expect(fetchMock.mock.calls[0][1].body).toBe('{"name":"Chair"}');
            expect(fetchMock.mock.calls[0][1].headers['Content-Type']).toBe('application/json');
        });

        it('sends FormData untouched, without a JSON content type', async () => {
            answerWith(() => jsonResponse({}));
            const form = new FormData();
            form.append('file', new Blob(['x']), 'x.txt');

            await http.post('/upload', form);

            const init = fetchMock.mock.calls[0][1];
            expect(init.body).toBe(form);
            expect(init.headers).not.toHaveProperty('Content-Type');
        });

        it('appends query parameters and skips empty ones', async () => {
            answerWith(() => jsonResponse([]));

            await http.get('/items', { params: { page: 2, q: 'a b', empty: null } });

            expect(fetchMock.mock.calls[0][0]).toBe('/items?page=2&q=a+b');
        });

        it('lets the caller override headers', async () => {
            answerWith(() => jsonResponse({}));

            await http.get('/export', { headers: { Accept: 'text/csv' } });

            expect(fetchMock.mock.calls[0][1].headers.Accept).toBe('text/csv');
        });

        it('lets a programming error surface as itself instead of a network failure', async () => {
            const circular = {};
            circular.self = circular;

            const error = await http.post('/items', circular).catch((e) => e);

            expect(error).toBeInstanceOf(TypeError);
            expect(error).not.toBeInstanceOf(HttpError);
            expect(fetchMock).not.toHaveBeenCalled();
            expect(notify).not.toHaveBeenCalled();
        });

        it('does not send a body on delete', async () => {
            answerWith(() => new Response(null, { status: 204 }));

            await http.delete('/items/1');

            expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
            expect(fetchMock.mock.calls[0][1].body).toBeUndefined();
        });

        it('sends a body with a delete that asks for one (a confirmation code), as JSON', async () => {
            answerWith(() => new Response(null, { status: 204 }));

            await http.delete('/me', { data: { code: '123456' } });

            const init = fetchMock.mock.calls[0][1];
            expect(init.method).toBe('DELETE');
            expect(init.body).toBe('{"code":"123456"}');
            expect(init.headers['Content-Type']).toBe('application/json');
        });
    });

    describe('successful responses', () => {
        it('resolves null on 204 without feedback', async () => {
            answerWith(() => new Response(null, { status: 204 }));

            await expect(http.put('/locale', { locale: 'en' })).resolves.toBeNull();
            expect(notify).not.toHaveBeenCalled();
        });

        it('resolves null for an empty 2xx body', async () => {
            answerWith(() => new Response('', { status: 201 }));

            await expect(http.post('/items', {})).resolves.toBeNull();
        });

        it('reads text and blob responses on request', async () => {
            answerWith(() => new Response('a,b', { status: 200, headers: { 'Content-Type': 'text/csv' } }));
            await expect(http.get('/export.csv', { responseType: 'text' })).resolves.toBe('a,b');

            answerWith(() => new Response('PDF', { status: 200, headers: { 'Content-Type': 'application/pdf' } }));
            const blob = await http.get('/report.pdf', { responseType: 'blob' });
            expect(blob).toBeInstanceOf(Blob);
            expect(blob.size).toBe(3);
        });

        it('throws unexpected_response when a 2xx body is not JSON', async () => {
            answerWith(() => new Response('<html>Welcome to the hotel wifi</html>', { status: 200 }));

            const error = await http.get('/items').catch((e) => e);

            expect(error).toBeInstanceOf(HttpError);
            expect(error.kind).toBe('unexpected_response');
            expect(error.status).toBe(200);
            expect(error.message).toBe('Unexpected answer');
            expect(error.reported).toBe(true);
            expect(notify).toHaveBeenCalledWith('danger', 'Unexpected answer');
        });

        it('turns a failure while reading the body into a network error', async () => {
            fetchMock.mockResolvedValue({
                ok: true,
                status: 200,
                headers: new Headers(),
                text: () => Promise.reject(new TypeError('terminated')),
            });

            const error = await http.get('/items').catch((e) => e);

            expect(error).toBeInstanceOf(HttpError);
            expect(error.status).toBe(0);
            expect(error.kind).toBe('network');
            expect(notify).toHaveBeenCalledWith('danger', 'Network down');
        });

        it('also guards the body of error responses', async () => {
            fetchMock.mockResolvedValue({
                ok: false,
                status: 500,
                headers: new Headers(),
                text: () => Promise.reject(new TypeError('terminated')),
            });

            const error = await http.get('/items').catch((e) => e);

            expect(error.status).toBe(0);
            expect(notify).toHaveBeenCalledWith('danger', 'Network down');
        });
    });

    describe('validation errors (422)', () => {
        it('exposes the server message, the field errors and the code, without feedback', async () => {
            answerWith(() =>
                jsonResponse(
                    {
                        message: 'The locale field is invalid.',
                        code: 'validation_failed',
                        errors: { locale: ['Invalid locale.'] },
                    },
                    422,
                ),
            );

            const error = await http.put('/locale', { locale: 'xx' }).catch((e) => e);

            expect(error).toBeInstanceOf(HttpError);
            expect(error.status).toBe(422);
            expect(error.message).toBe('The locale field is invalid.');
            expect(error.errors).toEqual({ locale: ['Invalid locale.'] });
            expect(error.code).toBe('validation_failed');
            expect(error.reported).toBe(false);
            expect(notify).not.toHaveBeenCalled();
        });

        it('falls back to the generic message when the 422 has none', async () => {
            answerWith(() => jsonResponse({}, 422));

            const error = await http.post('/items', {}).catch((e) => e);

            expect(error.message).toBe('Generic failure');
            expect(error.errors).toEqual({});
        });
    });

    describe('central feedback', () => {
        it('never surfaces a raw body message outside 422', async () => {
            answerWith(() => jsonResponse({ message: 'SQLSTATE[HY000] secret detail', code: 'db_down' }, 500));

            const error = await http.get('/items').catch((e) => e);

            expect(error.message).toBe('Server trouble');
            expect(error.code).toBe('db_down');
            expect(error.body).toEqual({ message: 'SQLSTATE[HY000] secret detail', code: 'db_down' });
            expect(notify).toHaveBeenCalledWith('danger', 'Server trouble');
        });

        it.each([
            [403, 'Not allowed'],
            [404, 'Not found'],
        ])('reports %i with a translated warning', async (status, message) => {
            answerWith(() => jsonResponse({ message: 'raw server text' }, status));

            const error = await http.get('/x').catch((e) => e);

            expect(error.status).toBe(status);
            expect(error.message).toBe(message);
            expect(error.reported).toBe(true);
            expect(notify).toHaveBeenCalledWith('warning', message);
        });

        it('leaves other client errors to the caller with a generic message', async () => {
            answerWith(() => jsonResponse({ message: 'Conflict with raw text', code: 'duplicate' }, 409));

            const error = await http.post('/items', {}).catch((e) => e);

            expect(error.status).toBe(409);
            expect(error.message).toBe('Generic failure');
            expect(error.code).toBe('duplicate');
            expect(notify).not.toHaveBeenCalled();
        });

        it('survives a non-JSON error body', async () => {
            answerWith(() => new Response('<html>Bad gateway</html>', { status: 502 }));

            const error = await http.get('/items').catch((e) => e);

            expect(error.status).toBe(502);
            expect(error.body).toBeNull();
            expect(notify).toHaveBeenCalledWith('danger', 'Server trouble');
        });

        it('reports a network failure', async () => {
            fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

            const error = await http.get('/items').catch((e) => e);

            expect(error.status).toBe(0);
            expect(error.kind).toBe('network');
            expect(notify).toHaveBeenCalledWith('danger', 'Network down');
        });

        it.each([
            ['401', () => jsonResponse({}, 401)],
            ['403', () => jsonResponse({}, 403)],
            ['404', () => jsonResponse({}, 404)],
            ['419', () => jsonResponse({}, 419)],
            ['429', () => jsonResponse({}, 429)],
            ['500', () => jsonResponse({}, 500)],
            ['unexpected body', () => new Response('<html></html>', { status: 200 })],
        ])('stays quiet for %s when silent', async (_label, build) => {
            vi.useFakeTimers();
            answerWith(build);

            const error = await http.get('/x', { silent: true }).catch((e) => e);
            await vi.runAllTimersAsync();

            expect(error).toBeInstanceOf(HttpError);
            expect(error.reported).toBe(false);
            expect(notify).not.toHaveBeenCalled();
            expect(reloadPage).not.toHaveBeenCalled();
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('stays quiet for network and timeout failures when silent', async () => {
            vi.useFakeTimers();
            fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

            const network = await http.get('/x', { silent: true }).catch((e) => e);
            expect(network.kind).toBe('network');

            fetchMock.mockImplementationOnce((_url, init) => hangUntilAborted(init.signal));
            const pending = http.get('/x', { silent: true, timeout: 10 }).catch((e) => e);
            await vi.advanceTimersByTimeAsync(10);
            const timeout = await pending;

            expect(timeout.kind).toBe('timeout');
            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('401 unauthenticated', () => {
        it('toasts, then redirects to the login URL advertised by the page', async () => {
            vi.useFakeTimers();
            document.head.insertAdjacentHTML('beforeend', '<meta name="login-url" content="/login">');
            answerWith(() => jsonResponse({ message: 'Unauthenticated.' }, 401));

            const error = await http.get('/me').catch((e) => e);

            expect(error.status).toBe(401);
            expect(error.message).toBe('Please sign in');
            expect(notify).toHaveBeenCalledWith('warning', 'Please sign in');
            expect(redirectTo).not.toHaveBeenCalled();

            await vi.runAllTimersAsync();

            expect(redirectTo).toHaveBeenCalledWith('/login');
            expect(reloadPage).not.toHaveBeenCalled();
        });

        it('reloads when the page advertises no login URL', async () => {
            vi.useFakeTimers();
            answerWith(() => jsonResponse({}, 401));

            await http.get('/me').catch(() => {});
            await vi.runAllTimersAsync();

            expect(reloadPage).toHaveBeenCalledTimes(1);
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('schedules a single navigation for concurrent failures', async () => {
            vi.useFakeTimers();
            answerWith(() => jsonResponse({}, 401));

            await Promise.allSettled([http.get('/a'), http.get('/b')]);
            await vi.runAllTimersAsync();

            expect(reloadPage).toHaveBeenCalledTimes(1);
        });
    });

    describe('leaving a stale page after a silent call', () => {
        it('reloads after the time to read a message when the CSRF token is stale (419)', () => {
            vi.useFakeTimers();

            leaveStalePage(new HttpError(419, 'Session expired'));

            expect(reloadPage).not.toHaveBeenCalled();

            vi.runAllTimers();

            expect(reloadPage).toHaveBeenCalledTimes(1);
            expect(notify).not.toHaveBeenCalled();
        });

        it('sends the person to the login page when the session is over (401), or reloads when there is none', () => {
            vi.useFakeTimers();
            document.head.insertAdjacentHTML('beforeend', '<meta name="login-url" content="/login">');

            leaveStalePage(new HttpError(401, 'Please sign in'));
            vi.runAllTimers();

            expect(redirectTo).toHaveBeenCalledWith('/login');
            expect(reloadPage).not.toHaveBeenCalled();
        });

        it('leaves the page alone for any other failure', () => {
            vi.useFakeTimers();

            for (const status of [0, 403, 404, 422, 429, 500]) {
                leaveStalePage(new HttpError(status, 'No'));
            }

            vi.runAllTimers();

            expect(reloadPage).not.toHaveBeenCalled();
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('schedules one navigation at a time, like the helper does for the calls that are not silent', () => {
            vi.useFakeTimers();

            leaveStalePage(new HttpError(419, 'Session expired'));
            leaveStalePage(new HttpError(419, 'Session expired'));
            vi.runAllTimers();

            expect(reloadPage).toHaveBeenCalledTimes(1);
        });
    });

    describe('419 expired CSRF token', () => {
        beforeEach(() => {
            document.head.insertAdjacentHTML('beforeend', '<meta name="csrf-refresh-url" content="/csrf-token">');
        });

        it('refreshes the token and replays the request once, silently', async () => {
            const responses = [
                jsonResponse({ message: 'CSRF token mismatch.' }, 419),
                new Response(null, { status: 204 }),
                jsonResponse({ id: 7 }, 201),
            ];
            fetchMock.mockImplementation(() => Promise.resolve(responses.shift()));

            await expect(http.post('/items', { name: 'Chair' })).resolves.toEqual({ id: 7 });

            expect(fetchMock.mock.calls.map(([url, init]) => `${init.method} ${url}`)).toEqual([
                'POST /items',
                'GET /csrf-token',
                'POST /items',
            ]);
            expect(fetchMock.mock.calls[2][1].body).toBe('{"name":"Chair"}');
            expect(notify).not.toHaveBeenCalled();
            expect(reloadPage).not.toHaveBeenCalled();
        });

        it('sends the rotated token on the replay', async () => {
            let call = 0;
            fetchMock.mockImplementation(() => {
                call += 1;
                if (call === 2) {
                    setCookie('XSRF-TOKEN', 'rotated');
                }

                return Promise.resolve(call === 1 ? jsonResponse({}, 419) : new Response(null, { status: 204 }));
            });
            setCookie('XSRF-TOKEN', 'stale');

            await http.post('/items', {});

            expect(fetchMock.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBe('stale');
            expect(fetchMock.mock.calls[2][1].headers['X-XSRF-TOKEN']).toBe('rotated');
        });

        it('reports session expiry and reloads only when the replay fails again', async () => {
            vi.useFakeTimers();
            const responses = [jsonResponse({}, 419), new Response(null, { status: 204 }), jsonResponse({}, 419)];
            fetchMock.mockImplementation(() => Promise.resolve(responses.shift()));

            const error = await http.post('/items', {}).catch((e) => e);

            expect(error.status).toBe(419);
            expect(error.message).toBe('Session expired');
            expect(fetchMock).toHaveBeenCalledTimes(3);
            expect(notify).toHaveBeenCalledWith('warning', 'Session expired');
            expect(reloadPage).not.toHaveBeenCalled();

            await vi.runAllTimersAsync();

            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('gives up without replaying when the refresh fails', async () => {
            vi.useFakeTimers();
            const responses = [jsonResponse({}, 419), jsonResponse({}, 500)];
            fetchMock.mockImplementation(() => Promise.resolve(responses.shift()));

            const error = await http.post('/items', {}).catch((e) => e);
            await vi.runAllTimersAsync();

            expect(error.status).toBe(419);
            expect(fetchMock).toHaveBeenCalledTimes(2);
            expect(notify).toHaveBeenCalledTimes(1);
            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('reports immediately when the page advertises no refresh URL', async () => {
            vi.useFakeTimers();
            document.querySelector('meta[name="csrf-refresh-url"]').remove();
            answerWith(() => jsonResponse({}, 419));

            const error = await http.post('/items', {}).catch((e) => e);
            await vi.runAllTimersAsync();

            expect(error.status).toBe(419);
            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('warning', 'Session expired');
            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('shares one refresh between concurrent requests', async () => {
            let items = 0;
            fetchMock.mockImplementation((url) => {
                if (url === '/csrf-token') {
                    return Promise.resolve(new Response(null, { status: 204 }));
                }

                items += 1;

                return Promise.resolve(items <= 2 ? jsonResponse({}, 419) : jsonResponse({ ok: true }));
            });

            await Promise.all([http.post('/a', {}), http.post('/b', {})]);

            expect(fetchMock.mock.calls.filter(([url]) => url === '/csrf-token')).toHaveLength(1);
        });
    });

    describe('429 too many requests', () => {
        it('exposes Retry-After in seconds and warns the user', async () => {
            answerWith(() => jsonResponse({ message: 'Too Many Attempts.' }, 429, { 'Retry-After': '42' }));

            const error = await http.post('/items', {}).catch((e) => e);

            expect(error.status).toBe(429);
            expect(error.retryAfter).toBe(42);
            expect(error.message).toBe('Slow down');
            expect(notify).toHaveBeenCalledWith('warning', 'Slow down');
        });

        it('understands an HTTP date in Retry-After', async () => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
            answerWith(() => jsonResponse({}, 429, { 'Retry-After': 'Thu, 01 Jan 2026 00:01:30 GMT' }));

            const error = await http.get('/x').catch((e) => e);

            expect(error.retryAfter).toBe(90);
        });

        it('leaves retryAfter null when the header is missing or unreadable', async () => {
            answerWith(() => jsonResponse({}, 429));
            expect((await http.get('/x').catch((e) => e)).retryAfter).toBeNull();

            answerWith(() => jsonResponse({}, 429, { 'Retry-After': 'soon' }));
            expect((await http.get('/x').catch((e) => e)).retryAfter).toBeNull();
        });
    });

    describe('timeout and cancellation', () => {
        beforeEach(() => {
            vi.useFakeTimers();
            fetchMock.mockImplementation((_url, init) => hangUntilAborted(init.signal));
        });

        it('gives up after the default timeout and reports it', async () => {
            const pending = http.get('/slow').catch((e) => e);

            await vi.advanceTimersByTimeAsync(DEFAULT_TIMEOUT_MS - 1);
            expect(notify).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(1);
            const error = await pending;

            expect(error).toBeInstanceOf(HttpError);
            expect(error.kind).toBe('timeout');
            expect(error.status).toBe(0);
            expect(error.message).toBe('Too slow');
            expect(notify).toHaveBeenCalledWith('danger', 'Too slow');
        });

        it('honours a custom timeout', async () => {
            const pending = http.get('/slow', { timeout: 100 }).catch((e) => e);

            await vi.advanceTimersByTimeAsync(100);

            expect((await pending).kind).toBe('timeout');
        });

        it('covers the time spent reading the body', async () => {
            fetchMock.mockImplementation((_url, init) =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    headers: new Headers(),
                    text: () => hangUntilAborted(init.signal),
                }),
            );

            const pending = http.get('/slow-body', { timeout: 50 }).catch((e) => e);
            await vi.advanceTimersByTimeAsync(50);

            expect((await pending).kind).toBe('timeout');
        });

        it('rethrows the raw AbortError, silently, when the caller cancels', async () => {
            const caller = new AbortController();
            const pending = http.get('/slow', { signal: caller.signal }).catch((e) => e);

            caller.abort();
            const error = await pending;

            expect(error).not.toBeInstanceOf(HttpError);
            expect(error.name).toBe('AbortError');
            expect(notify).not.toHaveBeenCalled();
        });

        it('does not even start when the caller signal is already aborted', async () => {
            const caller = new AbortController();
            caller.abort();

            const error = await http.get('/slow', { signal: caller.signal }).catch((e) => e);

            expect(error.name).toBe('AbortError');
            expect(notify).not.toHaveBeenCalled();
        });

        it('does not leave a timer behind after a successful request', async () => {
            fetchMock.mockImplementation(() => Promise.resolve(jsonResponse({ ok: true })));

            await http.get('/fast');

            expect(vi.getTimerCount()).toBe(0);
        });
    });

    describe('the time an upload is given', () => {
        let uploadTimeoutFor;
        let UPLOAD_TIMEOUT_MS;

        beforeEach(async () => {
            ({ uploadTimeoutFor, UPLOAD_TIMEOUT_MS } = await import('../../../resources/js/lib/http.js'));
        });

        it('is at least two minutes, whatever the file', () => {
            expect(UPLOAD_TIMEOUT_MS).toBe(120_000);
            expect(uploadTimeoutFor(0)).toBe(120_000);
            expect(uploadTimeoutFor(100)).toBe(120_000);
            expect(uploadTimeoutFor(2 * 1024 * 1024)).toBe(120_000);
        });

        it('is a minute plus a second for each 50 KB once that is more', () => {
            // 6 MiB is 123 steps of 50 KB (rounded up).
            expect(uploadTimeoutFor(6 * 1024 * 1024)).toBe(60_000 + 123 * 1000);
            // The largest file the server takes, 20 MiB: 410 steps.
            expect(uploadTimeoutFor(20 * 1024 * 1024)).toBe(60_000 + 410 * 1000);
        });

        it('is the minimum for a size that is not one', () => {
            expect(uploadTimeoutFor(Number.NaN)).toBe(120_000);
            expect(uploadTimeoutFor(-5)).toBe(120_000);
        });
    });
});
