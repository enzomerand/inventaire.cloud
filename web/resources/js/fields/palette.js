/**
 * The fixed palette of the choices of a select, a multiple choice and, through their badges, the tags.
 *
 * The API only stores a name (`ChoiceType::COLORS`); this module maps it to a Tabler badge. Every class is written out
 * in full, so that the stylesheet build can see it.
 */

/** @type {Record<string, string>} Badge classes by color name. */
const BADGES = {
    gray: 'badge bg-secondary-lt',
    red: 'badge bg-red-lt',
    orange: 'badge bg-orange-lt',
    yellow: 'badge bg-yellow-lt',
    green: 'badge bg-green-lt',
    teal: 'badge bg-teal-lt',
    blue: 'badge bg-blue-lt',
    indigo: 'badge bg-indigo-lt',
    purple: 'badge bg-purple-lt',
    pink: 'badge bg-pink-lt',
};

/** The names of the colors, in the order they are offered. */
export const COLORS = Object.keys(BADGES);

/**
 * The classes of the badge of a color; a plain badge for no color or one that is not in the palette.
 *
 * @param {string | null | undefined} color A name of the palette.
 * @returns {string}
 */
export function badgeClass(color) {
    return (color && BADGES[color]) || 'badge bg-secondary-lt';
}

/**
 * The classes of the little round swatch that shows a color in a list of choices to pick from.
 *
 * @param {string | null | undefined} color A name of the palette.
 * @returns {string}
 */
export function swatchClass(color) {
    return badgeClass(color).replace('badge ', 'app-swatch ');
}
