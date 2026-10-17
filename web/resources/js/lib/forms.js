/**
 * Helpers shared by the pages that drive forms and links from the markup: the validation errors of the API, the URL
 * templates and the wait a refusal for going too fast asks for.
 */
import { t } from './i18n.js';

/** Past this wait (two minutes), the time to wait is told in minutes rather than seconds. */
const LONG_WAIT_SECONDS = 120;

/**
 * Keeps the first message of each field of a 422 answer: forms show one message under each input.
 *
 * @param {Record<string, string[]>} errors The `errors` of an `HttpError` (field name to messages).
 * @returns {Record<string, string>} Field name to its first message.
 */
export function firstMessages(errors) {
    return Object.fromEntries(
        Object.entries(errors)
            .filter(([, messages]) => Array.isArray(messages) && messages.length > 0)
            .map(([name, messages]) => [name, messages[0]]),
    );
}

/**
 * Fills a URL template whose placeholder `__id__` stands for an identifier (the routes of the pages are written that way
 * in the markup, because the CSP build of Alpine does not allow Blade to inject values into expressions).
 *
 * @param {string} template For example `/app/w/__id__`.
 * @param {string} id The identifier to put in.
 * @returns {string}
 */
export function fillUrl(template, id) {
    return template.replace('__id__', encodeURIComponent(id));
}

/**
 * Tells how long to wait after a `429`, in minutes when it is long.
 *
 * @param {number | null} seconds The server's Retry-After, if it gave one.
 * @returns {string} A message of the interface; the general one when the wait is not known.
 */
export function throttledMessage(seconds) {
    if (!seconds) {
        return t('error.too_many_requests');
    }

    return seconds > LONG_WAIT_SECONDS
        ? t('login.error.throttled_minutes', { minutes: Math.ceil(seconds / 60) })
        : t('login.error.throttled', { seconds });
}
