/**
 * Helpers shared by the pages that drive forms and links from the markup: the validation errors of the API, and the
 * URL templates.
 */

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
