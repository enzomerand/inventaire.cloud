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
 * A message may hold placeholders written `:name`, like the server's own
 * translations; they are replaced by the matching entry of `replacements`. The
 * message is read once, from left to right: what a value holds (a field named
 * `:value`, a `$&`) is never taken for a placeholder of its own, and a
 * placeholder nobody gave a value for is left as it is.
 *
 * @param {string} key Message identifier, e.g. "error.generic".
 * @param {Record<string, string | number>} [replacements] Values for the placeholders of the message.
 * @returns {string}
 */
export function t(key, replacements = {}) {
    const message = loadMessages()[key] ?? key;

    return message.replace(/:(\w+)/g, (placeholder, name) =>
        Object.hasOwn(replacements, name) ? String(replacements[name]) : placeholder,
    );
}

/**
 * Returns the translated message for a key, in the plural form that fits a count.
 *
 * A message with plural forms is several keys: `key.one`, `key.other` and, for the languages that need them, `key.few`,
 * `key.many`, `key.two`, `key.zero`. The form is chosen by the plural rules of the language of the page; a language
 * whose form has no message falls back to `key.other`. `:count` is always filled in.
 *
 * @param {string} key Message identifier without the plural suffix, e.g. "inventories.items".
 * @param {number} count The number the message is about.
 * @param {Record<string, string | number>} [replacements] Values for the other placeholders of the message.
 * @returns {string}
 */
export function tn(key, count, replacements = {}) {
    let category = 'other';

    try {
        category = new Intl.PluralRules(document.documentElement.lang || 'en').select(count);
    } catch {
        // A language tag the browser does not know: the "other" form is always provided.
    }

    const chosen = `${key}.${category}` in loadMessages() ? `${key}.${category}` : `${key}.other`;

    return t(chosen, { count, ...replacements });
}
