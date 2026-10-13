/**
 * The single HTTP entry point of the front end.
 *
 * Every action the interface performs goes through this helper so the web
 * pages, and any future client of the same JSON API, share one behaviour for
 * headers, CSRF, error handling and user feedback.
 */
import { t } from './i18n.js';
import { notify } from './notify.js';
import { reloadPage } from './page.js';

/** Time the "session expired" toast stays readable before the page reloads. */
const SESSION_EXPIRED_RELOAD_DELAY_MS = 1500;

/** Guards against several concurrent 419 responses scheduling several reloads. */
let reloadScheduled = false;

/**
 * Error thrown for every failed request (HTTP error status or no response).
 *
 * Callers that need to react to a specific failure, typically a 422 on a form,
 * catch it and read `status` and `errors`.
 */
export class HttpError extends Error {
    /**
     * @param {number} status HTTP status code, or 0 when no response was received.
     * @param {string} message Human-readable message, already translated when it comes from the client.
     * @param {object} [details]
     * @param {Record<string, string[]>} [details.errors] Field name to validation messages (422 only).
     * @param {unknown} [details.body] Parsed response body, for callers needing extra fields.
     */
    constructor(status, message, { errors = {}, body = null } = {}) {
        super(message);
        this.name = 'HttpError';
        this.status = status;
        this.errors = errors;
        this.body = body;
    }
}

/**
 * @typedef {object} RequestOptions
 * @property {Record<string, string | number | boolean | null | undefined>} [params] Query string parameters; null and undefined are skipped.
 * @property {Record<string, string>} [headers] Extra headers, overriding the defaults.
 * @property {AbortSignal} [signal] Lets the caller cancel the request.
 */

/**
 * Appends query parameters to a URL.
 *
 * @param {string} url
 * @param {RequestOptions['params']} [params]
 * @returns {string}
 */
function withQuery(url, params) {
    if (!params) {
        return url;
    }

    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined) {
            query.append(key, String(value));
        }
    }

    const serialized = query.toString();

    if (serialized === '') {
        return url;
    }

    return url + (url.includes('?') ? '&' : '?') + serialized;
}

/**
 * Builds the headers sent with every request.
 *
 * The CSRF token and locale are read at call time, not import time, because
 * both can change while the page lives (token rotation, language switch).
 *
 * @param {boolean} hasBody Whether a JSON body is attached.
 * @param {Record<string, string>} [extra] Caller-provided headers.
 * @returns {Record<string, string>}
 */
function buildHeaders(hasBody, extra = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        headers['X-CSRF-TOKEN'] = csrfToken;
    }

    const locale = document.documentElement.lang;
    if (locale) {
        headers['Accept-Language'] = locale;
    }

    if (hasBody) {
        headers['Content-Type'] = 'application/json';
    }

    return { ...headers, ...extra };
}

/**
 * Reads a response body as JSON, tolerating empty and non-JSON bodies (proxy
 * error pages, for instance) so error handling never throws a parse error.
 *
 * @param {Response} response
 * @returns {Promise<any>} Parsed body, or null when there is nothing to parse.
 */
async function parseBody(response) {
    const text = await response.text();

    if (text === '') {
        return null;
    }

    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

/**
 * Handles an expired session or CSRF token (HTTP 419): tells the user, then
 * reloads so the page comes back with a fresh token.
 *
 * @returns {void}
 */
function handleSessionExpired() {
    notify('warning', t('error.session_expired'));

    if (reloadScheduled) {
        return;
    }

    reloadScheduled = true;
    setTimeout(() => {
        reloadScheduled = false;
        reloadPage();
    }, SESSION_EXPIRED_RELOAD_DELAY_MS);
}

/**
 * Turns a failed response into an HttpError, applying the central feedback
 * rules: 419 and 5xx are reported here, everything else (notably 422 and 4xx
 * business errors) is left to the caller, which knows the context.
 *
 * @param {number} status
 * @param {any} body Parsed response body.
 * @returns {never}
 */
function fail(status, body) {
    const serverMessage = typeof body?.message === 'string' && body.message !== '' ? body.message : null;

    if (status === 419) {
        handleSessionExpired();
        throw new HttpError(status, t('error.session_expired'), { body });
    }

    if (status >= 500) {
        const message = t('error.generic');
        notify('danger', message);
        throw new HttpError(status, message, { body });
    }

    const errors = status === 422 && body?.errors && typeof body.errors === 'object' ? body.errors : {};

    throw new HttpError(status, serverMessage ?? t('error.generic'), { errors, body });
}

/**
 * Sends a request and resolves with the parsed JSON response.
 *
 * @param {string} method HTTP verb.
 * @param {string} url
 * @param {RequestOptions & { data?: unknown }} [options] `data` is serialized as the JSON body.
 * @returns {Promise<any>} Parsed JSON, or null for 204 and empty bodies.
 * @throws {HttpError} On any non-2xx status or when the network is unreachable.
 */
async function request(method, url, { data, params, headers, signal } = {}) {
    const hasBody = data !== undefined;

    let response;

    try {
        response = await fetch(withQuery(url, params), {
            method,
            headers: buildHeaders(hasBody, headers),
            body: hasBody ? JSON.stringify(data) : undefined,
            credentials: 'same-origin',
            signal,
        });
    } catch (error) {
        // A cancelled request is a decision of the caller, not a failure to report.
        if (error?.name === 'AbortError') {
            throw error;
        }

        const message = t('error.network');
        notify('danger', message);
        throw new HttpError(0, message);
    }

    if (response.status === 204) {
        return null;
    }

    const body = await parseBody(response);

    if (!response.ok) {
        fail(response.status, body);
    }

    return body;
}

/**
 * The HTTP client used by every page script.
 *
 * Body-less verbs take `(url, options)`; verbs with a body take
 * `(url, data, options)`.
 */
export const http = {
    /**
     * @param {string} url
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    get: (url, options) => request('GET', url, options),

    /**
     * @param {string} url
     * @param {unknown} [data] JSON body.
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    post: (url, data, options) => request('POST', url, { ...options, data }),

    /**
     * @param {string} url
     * @param {unknown} [data] JSON body.
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    put: (url, data, options) => request('PUT', url, { ...options, data }),

    /**
     * @param {string} url
     * @param {unknown} [data] JSON body.
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    patch: (url, data, options) => request('PATCH', url, { ...options, data }),

    /**
     * @param {string} url
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    delete: (url, options) => request('DELETE', url, options),
};
