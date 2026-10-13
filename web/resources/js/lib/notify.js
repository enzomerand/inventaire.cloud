/**
 * Bridge between plain modules and the Alpine `toasts` store.
 *
 * Alpine wraps stores in reactive proxies, so a toast only renders when it is
 * pushed through `Alpine.store('toasts')`. Importing the store object directly
 * would bypass that reactivity, hence this indirection. It also lets modules
 * such as the HTTP helper stay usable (and testable) without Alpine running.
 */

/**
 * @typedef {'success' | 'info' | 'warning' | 'danger'} ToastType
 */

/**
 * Shows a toast if the Alpine store is available; otherwise does nothing.
 *
 * @param {ToastType} type Visual and accessibility level of the message.
 * @param {string} message Already translated text to display.
 * @param {{ timeout?: number | null }} [options] Auto-dismiss delay in ms; 0 or null keeps the toast until closed.
 * @returns {number | null} Toast id, or null when no store is registered.
 */
export function notify(type, message, options = {}) {
    const store = window.Alpine?.store('toasts');

    return store ? store.push(type, message, options) : null;
}
