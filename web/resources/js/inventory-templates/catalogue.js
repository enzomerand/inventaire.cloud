/**
 * Pure helpers of the template picker: the slug of a template, the text search over the catalogue, the category chips and
 * the `?template=` parameter of the address of the page.
 *
 * Nothing here touches the page or the network, so the picker (see `picker.js`) stays thin and these rules are tested alone.
 */

/**
 * @typedef {object} TemplateSummary A template, as `GET /api/v1/templates` lists it (localized by the server).
 * @property {string} slug
 * @property {string} category
 * @property {string} category_label
 * @property {string} icon
 * @property {string} name
 * @property {string} summary
 * @property {number} fields_count
 * @property {string} url
 */

/**
 * @typedef {object} TemplateField A field of a template, as `GET /api/v1/templates/{slug}` describes it.
 * @property {string} key
 * @property {string} name
 * @property {string} type
 * @property {string} type_label
 * @property {boolean} required
 */

/**
 * @typedef {TemplateSummary & { description?: string[], fields?: TemplateField[] }} Template A template, with its fields once read.
 */

/**
 * @typedef {object} CategoryChip A button of the category filter.
 * @property {string} category The category it selects ("" for all of them).
 * @property {string} label
 */

/** What a slug looks like: the name of a file of the catalogue (lowercase words joined by hyphens). */
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** The longest slug of the catalogue. */
export const MAX_SLUG_LENGTH = 60;

/** The name of the query parameter that opens the dialog on a template. */
export const LINK_PARAMETER = 'template';

/**
 * Whether a text can be the slug of a template. A text that cannot is never sent to the server: it cannot name one.
 *
 * @param {string} value
 * @returns {boolean}
 */
export function isSlug(value) {
    return value.length <= MAX_SLUG_LENGTH && SLUG.test(value);
}

/**
 * A text as the search compares it: lowercase, without accents, with single spaces. "Éléments" and "elements" match.
 *
 * @param {string} text
 * @returns {string}
 */
export function normalizeText(text) {
    return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/\s+/g, ' ').trim();
}

/**
 * The templates that match a search and a category. A template matches the search when every word typed is found in its
 * name or its summary (in any order); an empty search or category keeps everything.
 *
 * @param {TemplateSummary[]} templates The catalogue, in the order the server sent it.
 * @param {string} search What was typed.
 * @param {string} category The category to keep ("" for all).
 * @returns {TemplateSummary[]}
 */
export function filterTemplates(templates, search, category) {
    const words = normalizeText(search).split(' ').filter(Boolean);

    return templates.filter((template) => {
        if (category !== '' && template.category !== category) {
            return false;
        }

        const text = normalizeText(`${template.name} ${template.summary}`);

        return words.every((word) => text.includes(word));
    });
}

/**
 * The categories the catalogue has, in the order they first appear, with their translated label.
 *
 * @param {TemplateSummary[]} templates
 * @returns {CategoryChip[]}
 */
export function categoriesOf(templates) {
    const seen = new Map();

    for (const template of templates) {
        if (!seen.has(template.category)) {
            seen.set(template.category, { category: template.category, label: template.category_label });
        }
    }

    return [...seen.values()];
}

/**
 * The value of the `template` parameter of a query string, or null when the address carries none.
 *
 * @param {string} search A query string, with or without its leading `?`.
 * @returns {string | null}
 */
export function linkedTemplate(search) {
    return new URLSearchParams(search).get(LINK_PARAMETER);
}

/**
 * A query string without the `template` parameter (the other parameters stay).
 *
 * @param {string} search A query string, with or without its leading `?`.
 * @returns {string} The query string without its leading `?`; empty when nothing else is left.
 */
export function withoutLinkedTemplate(search) {
    const parameters = new URLSearchParams(search);

    parameters.delete(LINK_PARAMETER);

    return parameters.toString();
}
