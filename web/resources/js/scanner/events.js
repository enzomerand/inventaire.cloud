/**
 * The events the scanner panel (`../components/barcode-scanner.js`) and the page that uses it say to each other.
 *
 * The panel is a component of its own (a scope of its own, so that its refs never collide with the page's), set inside a
 * dialog of the page: the page asks it to start and to stop through the window, and it answers through a bubbling event
 * that the page listens to on its root. A `slot` names the panel the message is about, because a page holds more than one
 * (the one of the item dialog, the one of the search).
 */

/** The page asks a panel to start looking: `detail` is `{ slot, format }`. */
const SCAN_START = 'barcode-scan-start';

/** The page asks the panels to stop and close: `detail` is `{ slot }`, no slot meaning every panel. */
const SCAN_STOP = 'barcode-scan-stop';

/** A panel read a code: `detail` is `{ slot, value }`. Bubbles up from the panel. */
export const SCAN_DONE = 'barcode-scanned';

/** The person gave up scanning: `detail` is `{ slot }`. Bubbles up from the panel. */
export const SCAN_CANCELLED = 'barcode-scan-cancelled';

/**
 * Asks the panel of a slot to open and look for a code.
 *
 * @param {string} slot Which panel.
 * @param {string | null} format The `format` option of the barcode field being filled in, null for a search.
 * @returns {void}
 */
export function requestScan(slot, format) {
    window.dispatchEvent(new CustomEvent(SCAN_START, { detail: { slot, format } }));
}

/**
 * Asks the panel of a slot to stop the camera and close.
 *
 * @param {string} slot Which panel.
 * @returns {void}
 */
export function stopScan(slot) {
    window.dispatchEvent(new CustomEvent(SCAN_STOP, { detail: { slot } }));
}
