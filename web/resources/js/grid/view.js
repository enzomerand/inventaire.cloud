/**
 * How the items of an inventory are laid out: as a table, or as a gallery of cards.
 *
 * Which one is shown comes, in this order, from the address of the page (`view`, so that a link keeps it), from the choice
 * the person made last for this inventory (kept in the browser), and else from the width of the screen: cards on a phone,
 * where a table of many columns is hard to read, the table on anything wider.
 */
import { readPreference, writePreference } from '../lib/storage.js';

/** The ways to lay the items out. */
export const VIEWS = ['table', 'gallery'];

/** The screen width below which the gallery is the default view, in pixels: Bootstrap's `sm` breakpoint. */
export const NARROW_BELOW = 576;

/**
 * @typedef {'table' | 'gallery'} View
 */

/**
 * Reads a view from text.
 *
 * @param {unknown} value
 * @returns {View | null} Null for anything that is not a view.
 */
export function parseView(value) {
    return value === 'table' || value === 'gallery' ? value : null;
}

/**
 * Whether the screen is narrower than a tablet.
 *
 * @returns {boolean}
 */
export function isNarrow() {
    return window.matchMedia
        ? window.matchMedia(`(max-width: ${NARROW_BELOW - 0.02}px)`).matches
        : window.innerWidth < NARROW_BELOW;
}

/**
 * What identifies an inventory's choice in the storage of the browser.
 *
 * @param {string} inventoryUrl The address of the inventory in the API.
 * @returns {string}
 */
export function viewKey(inventoryUrl) {
    return `inventaire.items.view:${inventoryUrl}`;
}

/**
 * The view to show when the address does not say: the one chosen last for this inventory, else by the width of the screen.
 *
 * @param {string} inventoryUrl
 * @returns {View}
 */
export function defaultView(inventoryUrl) {
    return parseView(readPreference(viewKey(inventoryUrl))) ?? (isNarrow() ? 'gallery' : 'table');
}

/**
 * Remembers the view a person chose for an inventory.
 *
 * @param {string} inventoryUrl
 * @param {View} view
 * @returns {void}
 */
export function rememberView(inventoryUrl, view) {
    writePreference(viewKey(inventoryUrl), view);
}
