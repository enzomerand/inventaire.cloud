/**
 * The language the page is shown in, and formatters built for it.
 *
 * Values are formatted with `Intl` in the language of the page (`<html lang>`), so a French reader sees `1 234,50 €` and
 * an English one `€1,234.50` for the same stored value. Building a formatter is far more expensive than using it, and a
 * table formats hundreds of cells, so they are kept.
 */

/** @type {Map<string, Intl.NumberFormat | Intl.DateTimeFormat>} */
const formatters = new Map();

/**
 * The language of the page, as a BCP 47 tag; English when the page does not say or says something the browser rejects.
 *
 * @returns {string}
 */
export function pageLocale() {
    const language = document.documentElement.lang || 'en';

    try {
        return Intl.getCanonicalLocales(language)[0] ?? 'en';
    } catch {
        return 'en';
    }
}

/**
 * A number formatter for the language of the page.
 *
 * @param {Intl.NumberFormatOptions} options
 * @returns {Intl.NumberFormat}
 */
export function numberFormat(options) {
    const key = 'n:' + pageLocale() + JSON.stringify(options);

    if (!formatters.has(key)) {
        formatters.set(key, new Intl.NumberFormat(pageLocale(), options));
    }

    return /** @type {Intl.NumberFormat} */ (formatters.get(key));
}

/**
 * A date and time formatter for the language of the page.
 *
 * @param {Intl.DateTimeFormatOptions} options Without a `timeZone`, the dates are shown in the timezone of the browser.
 * @returns {Intl.DateTimeFormat}
 */
export function dateFormat(options) {
    // A formatter without a timezone of its own keeps the one the browser had when it was built: it is rebuilt when the
    // browser moves to another (a laptop that travelled while the page stayed open).
    const zone = options.timeZone === undefined ? new Date().getTimezoneOffset() : '';
    const key = 'd:' + pageLocale() + zone + JSON.stringify(options);

    if (!formatters.has(key)) {
        formatters.set(key, new Intl.DateTimeFormat(pageLocale(), options));
    }

    return /** @type {Intl.DateTimeFormat} */ (formatters.get(key));
}
