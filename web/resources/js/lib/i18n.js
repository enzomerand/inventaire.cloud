/**
 * Client-side message lookup.
 *
 * User-facing strings must be translated by the server (the single source of
 * truth for locales), so JavaScript never hardcodes display text. The layout
 * embeds the strings the scripts need in a JSON <script> element, and this
 * module reads them lazily.
 */

/** Id of the <script type="application/json"> element rendered by Blade. */
const MESSAGES_ELEMENT_ID = 'client-messages';

/** @type {Record<string, string> | null} */
let messages = null;

/**
 * Parses the embedded messages once. A missing or malformed element yields an
 * empty map: the UI then shows the message key, which is ugly but never breaks
 * a request flow.
 *
 * @returns {Record<string, string>}
 */
function loadMessages() {
    if (messages !== null) {
        return messages;
    }

    const element = document.getElementById(MESSAGES_ELEMENT_ID);

    try {
        messages = element ? JSON.parse(element.textContent ?? '{}') : {};
    } catch {
        messages = {};
    }

    return messages;
}

/**
 * Returns the translated message for a key, or the key itself when the server
 * did not provide it (makes a missing translation visible instead of blank).
 *
 * @param {string} key Message identifier, e.g. "error.generic".
 * @returns {string}
 */
export function t(key) {
    return loadMessages()[key] ?? key;
}
