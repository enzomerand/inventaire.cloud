/**
 * The single HTTP entry point of the front end.
 *
 * Every action the interface performs goes through this helper so the web
 * pages, and any other client of the same JSON API, share one behaviour for
 * headers, CSRF, error handling and user feedback.
 *
 * Contract relied upon by the rest of the application:
 *
 * - Messages shown to people are never taken from a response body, with one
 *   exception: validation messages of a 422, which the server translates. Every
 *   other message is looked up by key with `t()` (see ./i18n.js), so the wording
 *   always follows the interface language and never leaks technical details.
 * - Failures throw `HttpError`. Common ones also get central feedback (toast,
 *   redirect or reload); pass `{ silent: true }` to handle them yourself.
 * - A failed request never resolves with a half-read or unparseable payload.
 */
import { t } from './i18n.js';
import { notify } from './notify.js';
import { redirectTo, reloadPage } from './page.js';

/** Time after which a request is abandoned (covers connecting and reading the body). */
export const DEFAULT_TIMEOUT_MS = 30_000;

/** The least time an upload is given before it is abandoned: a file of several megabytes on a slow connection takes longer than other requests. */
export const UPLOAD_TIMEOUT_MS = 120_000;

/** The time an upload is given on top of the time each 50 KB takes: the connection and the server's work on the file. */
const UPLOAD_BASE_MS = 60_000;

/** How much of a file goes up in a second, at the speed an upload is expected to have at least (50 KB/s). */
const UPLOAD_BYTES_PER_SECOND = 50 * 1024;

/**
 * The time an upload of a file is given before it is abandoned: a minute, plus a second for each 50 KB, and never less than
 * {@link UPLOAD_TIMEOUT_MS}. The largest file the server takes (20 MiB) is given about eight minutes, a photo of a few
 * megabytes the two minutes of the minimum.
 *
 * @param {number} bytes The size of the file.
 * @returns {number} Milliseconds.
 */
export function uploadTimeoutFor(bytes) {
    const size = Number.isFinite(bytes) && bytes > 0 ? bytes : 0;

    return Math.max(UPLOAD_TIMEOUT_MS, UPLOAD_BASE_MS + Math.ceil(size / UPLOAD_BYTES_PER_SECOND) * 1000);
}

/** Time a feedback toast stays readable before the page reloads or redirects. */
const NAVIGATION_DELAY_MS = 1500;

/** Translation keys of the statuses that have a dedicated message. */
const STATUS_MESSAGE_KEYS = {
    401: 'error.unauthenticated',
    403: 'error.forbidden',
    404: 'error.not_found',
    419: 'error.session_expired',
    429: 'error.too_many_requests',
};

/** Guards against several failing requests scheduling several navigations. */
let navigationScheduled = false;

/** In-flight CSRF refresh shared by concurrent 419 responses. */
let csrfRefresh = null;

/**
 * Error thrown for every failed request.
 *
 * Callers that need to react to a specific failure, typically a 422 on a form,
 * catch it and read `status`, `errors` or `code`.
 */
export class HttpError extends Error {
    /**
     * @param {number} status HTTP status code, or 0 when no usable response was received.
     * @param {string} message Message that is safe to show: translated by the client or, for a 422, by the server.
     * @param {object} [details]
     * @param {'http' | 'network' | 'timeout' | 'unexpected_response'} [details.kind] What went wrong at transport level.
     * @param {Record<string, string[]>} [details.errors] Field name to validation messages (422 only).
     * @param {unknown} [details.body] Parsed response body, for callers needing extra fields.
     * @param {string | number | null} [details.code] Machine-readable error code sent by the API (`body.code`).
     * @param {number | null} [details.retryAfter] Seconds to wait before retrying (429 only).
     */
    constructor(status, message, { kind = 'http', errors = {}, body = null, code = null, retryAfter = null } = {}) {
        super(message);
        this.name = 'HttpError';
        this.status = status;
        this.kind = kind;
        this.errors = errors;
        this.body = body;
        this.code = code;
        this.retryAfter = retryAfter;
        /** True once the helper showed the user a message for this failure; callers should not repeat it. */
        this.reported = false;
    }
}

/**
 * @typedef {object} RequestOptions
 * @property {Record<string, string | number | boolean | null | undefined>} [params] Query string parameters; null and undefined are skipped.
 * @property {Record<string, string>} [headers] Extra headers, overriding the defaults.
 * @property {AbortSignal} [signal] Lets the caller cancel the request; cancellation rejects with the raw AbortError, silently.
 * @property {number} [timeout] Milliseconds before giving up; defaults to DEFAULT_TIMEOUT_MS, 0 disables it.
 * @property {'json' | 'blob' | 'text'} [responseType] How to read a successful body; defaults to "json".
 * @property {boolean} [silent] Skip the central feedback (toast, redirect, reload) and handle the error yourself.
 * @property {unknown} [data] Body of a request whose verb takes none by convention (`delete`, when the API wants a confirmation); `post`, `put` and `patch` take it as an argument instead.
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
 * Reads a `<meta name="...">` value.
 *
 * @param {string} name
 * @returns {string | null}
 */
function readMeta(name) {
    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || null;
}

/**
 * Reads the CSRF token Laravel keeps in the (readable) XSRF-TOKEN cookie. The
 * cookie is rewritten on every response, so unlike the page's meta tag it
 * follows token rotation after login or logout.
 *
 * @returns {string | null}
 */
function readXsrfCookie() {
    const prefix = 'XSRF-TOKEN=';
    const entry = document.cookie.split('; ').find((cookie) => cookie.startsWith(prefix));

    if (!entry) {
        return null;
    }

    try {
        return decodeURIComponent(entry.slice(prefix.length));
    } catch {
        return null;
    }
}

/**
 * Builds the headers sent with every request.
 *
 * The CSRF token and locale are read at call time, not import time, because
 * both can change while the page lives.
 *
 * @param {unknown} data Request body, if any.
 * @param {Record<string, string>} [extra] Caller-provided headers.
 * @returns {Record<string, string>}
 */
function buildHeaders(data, extra = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const xsrfToken = readXsrfCookie();
    const metaToken = readMeta('csrf-token');
    if (xsrfToken) {
        headers['X-XSRF-TOKEN'] = xsrfToken;
    } else if (metaToken) {
        headers['X-CSRF-TOKEN'] = metaToken;
    }

    const locale = document.documentElement.lang;
    if (locale) {
        headers['Accept-Language'] = locale;
    }

    // A FormData body needs the browser to set the multipart boundary itself.
    if (data !== undefined && !(data instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    return { ...headers, ...extra };
}

/**
 * Serializes a request body.
 *
 * @param {unknown} data
 * @returns {string | FormData | undefined}
 */
function serializeBody(data) {
    if (data === undefined || data instanceof FormData) {
        return data;
    }

    return JSON.stringify(data);
}

/**
 * Parses JSON, returning null instead of throwing.
 *
 * @param {string} text
 * @returns {any}
 */
function tryParseJson(text) {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

/**
 * Converts a Retry-After header (delay in seconds or an HTTP date) into seconds.
 *
 * @param {string | null} header
 * @returns {number | null}
 */
function parseRetryAfter(header) {
    if (header === null || header.trim() === '') {
        return null;
    }

    if (/^\d+$/.test(header.trim())) {
        return Number(header);
    }

    const date = Date.parse(header);

    return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000));
}

/**
 * Chooses the message of an HTTP failure: the server's own wording is only
 * trusted for 422 validation messages; everything else comes from translation keys.
 *
 * @param {number} status
 * @param {any} body
 * @returns {string}
 */
function messageFor(status, body) {
    if (status === 422 && typeof body?.message === 'string' && body.message !== '') {
        return body.message;
    }

    if (STATUS_MESSAGE_KEYS[status]) {
        return t(STATUS_MESSAGE_KEYS[status]);
    }

    return status >= 500 ? t('error.server') : t('error.generic');
}

/**
 * Builds the HttpError for a non-2xx response.
 *
 * @param {Response} response
 * @param {any} body Parsed body, or null when it was not JSON.
 * @returns {HttpError}
 */
function failureFrom(response, body) {
    const { status } = response;
    const code = body?.code;

    return new HttpError(status, messageFor(status, body), {
        errors: status === 422 && body?.errors && typeof body.errors === 'object' ? body.errors : {},
        body,
        code: typeof code === 'string' || typeof code === 'number' ? code : null,
        retryAfter: status === 429 ? parseRetryAfter(response.headers.get('Retry-After')) : null,
    });
}

/**
 * Reads the raw body of a response from the network: text for JSON, text and
 * error bodies, a Blob for downloads. This is the only part that can fail
 * because the connection dropped, so it is the only part that runs inside the
 * network error handling of `attempt()`.
 *
 * @param {Response} response
 * @param {'json' | 'blob' | 'text'} responseType
 * @returns {Promise<string | Blob | null>}
 */
async function readBody(response, responseType) {
    if (response.status === 204 || response.status === 205) {
        return null;
    }

    return response.ok && responseType === 'blob' ? response.blob() : response.text();
}

/**
 * Turns a fully read response into the value the caller asked for, or throws
 * HttpError for failed statuses and for successful ones whose payload is not
 * what was asked for.
 *
 * @param {Response} response
 * @param {string | Blob | null} body What `readBody()` returned.
 * @param {'json' | 'blob' | 'text'} responseType
 * @returns {any}
 */
function interpretResponse(response, body, responseType) {
    if (!response.ok) {
        throw failureFrom(response, tryParseJson(body));
    }

    // No content, a download or plain text: nothing to parse.
    if (body === null || responseType !== 'json') {
        return body;
    }

    if (body === '') {
        return null;
    }

    try {
        return JSON.parse(body);
    } catch {
        // Typically an HTML page from a proxy, a captive portal or a misrouted URL.
        throw new HttpError(response.status, t('error.unexpected_response'), { kind: 'unexpected_response' });
    }
}

/**
 * Forwards a caller's AbortSignal to the controller owned by the request.
 *
 * @param {AbortSignal | undefined} signal
 * @param {AbortController} controller
 * @returns {() => void} Removes the listener once the request is over.
 */
function forwardAbort(signal, controller) {
    if (!signal) {
        return () => {};
    }

    if (signal.aborted) {
        controller.abort(signal.reason);

        return () => {};
    }

    const onAbort = () => controller.abort(signal.reason);
    signal.addEventListener('abort', onAbort, { once: true });

    return () => signal.removeEventListener('abort', onAbort);
}

/**
 * Performs one HTTP exchange, without any user feedback.
 *
 * Only the fetch call and the reading of the body run inside the try block, so
 * a dropped connection becomes a network failure. The URL, headers and body are
 * built beforehand, and the response is interpreted afterwards: a bug in that
 * code surfaces as itself instead of masquerading as a network error.
 *
 * @param {string} method
 * @param {string} url
 * @param {RequestOptions & { data?: unknown }} options
 * @returns {Promise<any>}
 * @throws {HttpError | Error} HttpError for every failure; the caller's own AbortError when it cancelled.
 */
async function attempt(method, url, options) {
    const { data, params, headers, signal, timeout = DEFAULT_TIMEOUT_MS, responseType = 'json' } = options;

    const target = withQuery(url, params);
    const init = {
        method,
        headers: buildHeaders(data, headers),
        body: serializeBody(data),
        credentials: 'same-origin',
    };

    const controller = new AbortController();
    const stopForwarding = forwardAbort(signal, controller);
    let timedOut = false;
    const timer =
        timeout > 0
            ? setTimeout(() => {
                  timedOut = true;
                  controller.abort();
              }, timeout)
            : null;

    let response;
    let body;

    try {
        response = await fetch(target, { ...init, signal: controller.signal });
        body = await readBody(response, responseType);
    } catch (error) {
        if (timedOut) {
            throw new HttpError(0, t('error.timeout'), { kind: 'timeout' });
        }

        // A cancelled request is a decision of the caller, not a failure to report.
        if (signal?.aborted || error?.name === 'AbortError') {
            throw error;
        }

        throw new HttpError(0, t('error.network'), { kind: 'network' });
    } finally {
        clearTimeout(timer);
        stopForwarding();
    }

    return interpretResponse(response, body, responseType);
}

/**
 * Asks the server for a fresh CSRF token (it comes back in the XSRF-TOKEN
 * cookie). Concurrent callers share one request.
 *
 * @returns {Promise<boolean>} True when a new token was obtained.
 */
function refreshCsrfToken() {
    const url = readMeta('csrf-refresh-url');

    if (!url) {
        return Promise.resolve(false);
    }

    csrfRefresh ??= attempt('GET', url, { responseType: 'text' })
        .then(
            () => true,
            () => false,
        )
        .finally(() => {
            csrfRefresh = null;
        });

    return csrfRefresh;
}

/**
 * Runs an action after the feedback toast had time to be read, at most once at
 * a time. Exported for the components that make `silent` calls and handle the
 * failures themselves: a definitive 419 (the page is stale) is answered with
 * the same delayed reload as everywhere else.
 *
 * @param {() => void} action Reload or redirect.
 * @returns {void}
 */
export function scheduleNavigation(action) {
    if (navigationScheduled) {
        return;
    }

    navigationScheduled = true;
    setTimeout(() => {
        navigationScheduled = false;
        action();
    }, NAVIGATION_DELAY_MS);
}

/**
 * Sends the user to the login page, or reloads when none is advertised (the
 * server then decides what an unauthenticated visitor sees).
 *
 * @returns {void}
 */
function leaveExpiredSession() {
    const loginUrl = readMeta('login-url');

    if (loginUrl) {
        redirectTo(loginUrl);
    } else {
        reloadPage();
    }
}

/**
 * Whether a failure gets central feedback. Validation errors and other client
 * errors are left to the caller, which knows the context.
 *
 * @param {HttpError} error
 * @returns {boolean}
 */
function needsFeedback(error) {
    return error.kind !== 'http' || error.status in STATUS_MESSAGE_KEYS || error.status >= 500;
}

/**
 * Applies the central feedback rules to a failure.
 *
 * @param {HttpError} error
 * @param {boolean} silent When true nothing is shown or navigated.
 * @returns {HttpError} The same error, flagged as reported when feedback was given.
 */
function report(error, silent) {
    if (silent || !needsFeedback(error)) {
        return error;
    }

    // Statuses with a dedicated message are expected conditions (warning); the rest are errors.
    notify(error.status in STATUS_MESSAGE_KEYS ? 'warning' : 'danger', error.message);
    error.reported = true;
    leaveStalePage(error);

    return error;
}

/**
 * Sends the page where a failure that makes it stale calls for, after the feedback had time to be read: a reload for an expired CSRF
 * token (419), the login page (or a reload) for a session that ended (401). Any other failure leaves the page alone. The helper does
 * this for every call that is not silent; exported for the components that make `silent` calls and handle the failures themselves,
 * so that a page whose session is gone is not left standing with buttons that can only fail.
 *
 * @param {HttpError} error
 * @returns {boolean} Whether the page is about to reload or leave.
 */
export function leaveStalePage(error) {
    if (error.status === 419) {
        scheduleNavigation(reloadPage);

        return true;
    }

    if (error.status === 401) {
        scheduleNavigation(leaveExpiredSession);

        return true;
    }

    return false;
}

/**
 * Sends a request and resolves with the parsed response.
 *
 * A 419 (expired CSRF token) is retried once after refreshing the token, which
 * is invisible to the user; only a second failure is reported.
 *
 * @param {string} method HTTP verb.
 * @param {string} url
 * @param {RequestOptions & { data?: unknown }} [options] `data` is serialized as the JSON body (FormData is sent as is).
 * @returns {Promise<any>} Parsed JSON (or blob/text), or null for 204 and empty bodies.
 * @throws {HttpError} On any non-2xx status, unusable payload, timeout or network failure.
 */
async function request(method, url, options = {}) {
    try {
        return await attempt(method, url, options);
    } catch (error) {
        if (!(error instanceof HttpError)) {
            throw error;
        }

        let failure = error;

        if (error.status === 419 && (await refreshCsrfToken())) {
            try {
                return await attempt(method, url, options);
            } catch (retryError) {
                if (!(retryError instanceof HttpError)) {
                    throw retryError;
                }

                failure = retryError;
            }
        }

        throw report(failure, options.silent === true);
    }
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
     * @param {unknown} [data] JSON body, or FormData for uploads.
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    post: (url, data, options) => request('POST', url, { ...options, data }),

    /**
     * @param {string} url
     * @param {unknown} [data] JSON body, or FormData for uploads.
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    put: (url, data, options) => request('PUT', url, { ...options, data }),

    /**
     * @param {string} url
     * @param {unknown} [data] JSON body, or FormData for uploads.
     * @param {RequestOptions} [options]
     * @returns {Promise<any>}
     */
    patch: (url, data, options) => request('PATCH', url, { ...options, data }),

    /**
     * @param {string} url
     * @param {RequestOptions} [options] `data` sends a JSON body, for the few deletions that need a confirmation.
     * @returns {Promise<any>}
     */
    delete: (url, options) => request('DELETE', url, options),
};
