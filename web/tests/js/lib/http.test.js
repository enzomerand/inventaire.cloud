import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { HttpError, http } from '../../../resources/js/lib/http.js';
import { resetMessages } from '../../../resources/js/lib/i18n.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));

/**
 * Builds a fetch Response carrying a JSON body.
 */
function jsonResponse(body, status = 200) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('http helper', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="token-123">';
        document.body.innerHTML =
            '<script type="application/json" id="client-messages">' +
            JSON.stringify({
                'error.generic': 'Generic failure',
                'error.network': 'Network down',
                'error.session_expired': 'Session expired',
            }) +
            '</script>';
        document.documentElement.lang = 'fr';
        resetMessages();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        fetchMock.mockReset();
    });

    it('sends the standard headers and resolves the parsed JSON', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ ok: true }));

        const result = await http.get('/api/ping');

        expect(result).toEqual({ ok: true });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/ping');
        expect(init.method).toBe('GET');
        expect(init.credentials).toBe('same-origin');
        expect(init.body).toBeUndefined();
        expect(init.headers).toEqual({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': 'token-123',
            'Accept-Language': 'fr',
        });
    });

    it('serializes the body as JSON for verbs that carry one', async () => {
        // A Response body can be read once, so each call needs a fresh one.
        fetchMock.mockImplementation(() => Promise.resolve(jsonResponse({ id: 1 }, 201)));

        await http.post('/items', { name: 'Chair' });
        await http.put('/items/1', { name: 'Table' });
        await http.patch('/items/1', { name: 'Desk' });

        expect(fetchMock.mock.calls.map(([, init]) => init.method)).toEqual(['POST', 'PUT', 'PATCH']);
        expect(fetchMock.mock.calls[0][1].body).toBe('{"name":"Chair"}');
        expect(fetchMock.mock.calls[0][1].headers['Content-Type']).toBe('application/json');
    });

    it('appends query parameters and skips empty ones', async () => {
        fetchMock.mockResolvedValue(jsonResponse([]));

        await http.get('/items', { params: { page: 2, q: 'a b', empty: null } });

        expect(fetchMock.mock.calls[0][0]).toBe('/items?page=2&q=a+b');
    });

    it('does not send a body on delete', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 204 }));

        await http.delete('/items/1');

        expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
        expect(fetchMock.mock.calls[0][1].body).toBeUndefined();
    });

    it('resolves null on 204 without a toast', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 204 }));

        await expect(http.put('/locale', { locale: 'en' })).resolves.toBeNull();
        expect(notify).not.toHaveBeenCalled();
    });

    it('throws an HttpError with the field errors on 422 and leaves feedback to the caller', async () => {
        fetchMock.mockResolvedValue(
            jsonResponse({ message: 'The given data was invalid.', errors: { locale: ['Invalid locale.'] } }, 422),
        );

        const error = await http.put('/locale', { locale: 'xx' }).catch((e) => e);

        expect(error).toBeInstanceOf(HttpError);
        expect(error.status).toBe(422);
        expect(error.message).toBe('The given data was invalid.');
        expect(error.errors).toEqual({ locale: ['Invalid locale.'] });
        expect(notify).not.toHaveBeenCalled();
    });

    it('shows a toast then reloads the page on 419', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue(jsonResponse({ message: 'CSRF token mismatch.' }, 419));

        const error = await http.post('/items', {}).catch((e) => e);

        expect(error).toBeInstanceOf(HttpError);
        expect(error.status).toBe(419);
        expect(notify).toHaveBeenCalledWith('warning', 'Session expired');
        expect(reloadPage).not.toHaveBeenCalled();

        vi.runAllTimers();

        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('schedules a single reload when several requests expire together', async () => {
        vi.useFakeTimers();
        fetchMock.mockImplementation(() => Promise.resolve(jsonResponse({}, 419)));

        await Promise.allSettled([http.get('/a'), http.get('/b')]);
        vi.runAllTimers();

        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('shows a generic error toast on 5xx and throws', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ message: 'SQLSTATE[HY000] internal detail' }, 500));

        const error = await http.get('/items').catch((e) => e);

        expect(error).toBeInstanceOf(HttpError);
        expect(error.status).toBe(500);
        expect(error.message).toBe('Generic failure');
        expect(notify).toHaveBeenCalledWith('danger', 'Generic failure');
    });

    it('survives a non-JSON error body', async () => {
        fetchMock.mockResolvedValue(new Response('<html>Bad gateway</html>', { status: 502 }));

        const error = await http.get('/items').catch((e) => e);

        expect(error).toBeInstanceOf(HttpError);
        expect(error.status).toBe(502);
        expect(notify).toHaveBeenCalledWith('danger', 'Generic failure');
    });

    it('shows a network error toast when fetch rejects', async () => {
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

        const error = await http.get('/items').catch((e) => e);

        expect(error).toBeInstanceOf(HttpError);
        expect(error.status).toBe(0);
        expect(error.message).toBe('Network down');
        expect(notify).toHaveBeenCalledWith('danger', 'Network down');
    });

    it('rethrows aborted requests untouched and silently', async () => {
        const abort = new DOMException('Aborted', 'AbortError');
        fetchMock.mockRejectedValue(abort);

        await expect(http.get('/items')).rejects.toBe(abort);
        expect(notify).not.toHaveBeenCalled();
    });

    it('keeps other client errors for the caller without a toast', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ message: 'Forbidden.' }, 403));

        const error = await http.get('/secret').catch((e) => e);

        expect(error.status).toBe(403);
        expect(error.message).toBe('Forbidden.');
        expect(notify).not.toHaveBeenCalled();
    });
});
