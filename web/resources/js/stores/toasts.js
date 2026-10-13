/**
 * Alpine store holding the toast notifications currently on screen.
 *
 * The store only manages state; `components/toasts.blade.php` renders it. Keeping
 * them apart lets any script raise a toast (through `lib/notify.js`) while the
 * markup stays in Blade, where translations and accessibility attributes live.
 */

/** Default time a toast stays visible, long enough to read a sentence. */
export const DEFAULT_TIMEOUT_MS = 5000;

/** Toasts kept on screen at once; older ones are dropped to avoid a wall of messages. */
export const MAX_VISIBLE = 5;

/** Types that interrupt screen readers (`role="alert"`); the others are polite. */
const ASSERTIVE_TYPES = ['warning', 'danger'];

const KNOWN_TYPES = ['success', 'info', 'warning', 'danger'];

/**
 * @typedef {import('../lib/notify.js').ToastType} ToastType
 * @typedef {{ id: number, type: ToastType, message: string }} Toast
 */

/**
 * Creates a fresh toast store. A factory (rather than a module singleton) so
 * each test, and each Alpine instance, gets isolated state.
 *
 * Methods rely on `this` so that, once Alpine wraps the object in a reactive
 * proxy, changes made through the proxy trigger re-renders.
 *
 * @returns {{
 *   items: Toast[],
 *   nextId: number,
 *   readonly polite: Toast[],
 *   readonly assertive: Toast[],
 *   push: (type: ToastType, message: string, options?: { timeout?: number | null }) => number,
 *   dismiss: (id: number) => void,
 * }}
 */
export function createToastsStore() {
    /** Pending auto-dismiss timers by toast id; outside the store so they are never proxied. */
    const timers = new Map();

    return {
        items: [],
        nextId: 1,

        /** Toasts announced politely (success, info), in display order. */
        get polite() {
            return this.items.filter((toast) => !ASSERTIVE_TYPES.includes(toast.type));
        },

        /** Toasts announced assertively (warning, danger), in display order. */
        get assertive() {
            return this.items.filter((toast) => ASSERTIVE_TYPES.includes(toast.type));
        },

        /**
         * Shows a toast and schedules its dismissal.
         *
         * @param {ToastType} type Unknown values fall back to "info".
         * @param {string} message Already translated text.
         * @param {{ timeout?: number | null }} [options] Delay in ms before auto-dismiss; 0 or null keeps it until closed.
         * @returns {number} Id to pass to `dismiss`.
         */
        push(type, message, { timeout = DEFAULT_TIMEOUT_MS } = {}) {
            const id = this.nextId++;

            this.items.push({ id, type: KNOWN_TYPES.includes(type) ? type : 'info', message });

            while (this.items.length > MAX_VISIBLE) {
                this.dismiss(this.items[0].id);
            }

            if (timeout) {
                timers.set(
                    id,
                    setTimeout(() => this.dismiss(id), timeout),
                );
            }

            return id;
        },

        /**
         * Removes a toast and cancels its pending timer. Unknown ids are ignored
         * so a late timer or a double click is harmless.
         *
         * @param {number} id
         * @returns {void}
         */
        dismiss(id) {
            clearTimeout(timers.get(id));
            timers.delete(id);

            this.items = this.items.filter((toast) => toast.id !== id);
        },
    };
}
