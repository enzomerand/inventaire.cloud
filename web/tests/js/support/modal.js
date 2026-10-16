/**
 * A stand-in for `lib/modal.js` that behaves the way Bootstrap's modal does where it matters to a component: closing a
 * dialog fires a cancellable `hide.bs.modal` first, and the `hidden.bs.modal` that follows comes later, once the fade is
 * over (a timer, not at once). A test that mocks the module with plain `vi.fn()`s hides the bugs that live in that
 * ordering: a dialog "closed" by `hideModal` is still open for a while, a person can close it by hand while a request is
 * on its way, and the reset of the dialog runs on `hidden.bs.modal`, not when the code asks.
 *
 * Use it with `vi.mock('.../lib/modal.js', async () => import('../support/modal.js'))`.
 */
import { vi } from 'vitest';

/** The dialogs that are shown. */
const shown = new Set();

/** Opens a dialog (Bootstrap fires `show.bs.modal` as it starts to open it). */
export const showModal = vi.fn((element) => {
    shown.add(element);
    element.dispatchEvent(new Event('show.bs.modal'));
});

/**
 * Closes a dialog, as Bootstrap's `hide()` does for whoever calls it: the code, Escape, a click outside, a button that
 * dismisses. A listener of `hide.bs.modal` can cancel the closing; otherwise `hidden.bs.modal` follows after the fade.
 *
 * @param {HTMLElement} element
 * @returns {boolean} Whether the dialog is closing.
 */
export const hideModal = vi.fn((element) => {
    if (!shown.has(element)) {
        return false;
    }

    const hide = new Event('hide.bs.modal', { cancelable: true });

    element.dispatchEvent(hide);

    if (hide.defaultPrevented) {
        return false;
    }

    shown.delete(element);
    setTimeout(() => element.dispatchEvent(new Event('hidden.bs.modal')), 0);

    return true;
});

/** Calls a function whenever the dialog has been closed. */
export const onModalHidden = vi.fn((element, callback) => {
    element.addEventListener('hidden.bs.modal', callback);
});

/**
 * The person closes the dialog (Escape, the cross, Cancel, a click outside): the same as the code closing it.
 *
 * @param {HTMLElement} element
 * @returns {boolean} Whether the dialog is closing.
 */
export function dismiss(element) {
    return hideModal(element);
}

/** Waits for the timers of the fade to be over, so that `hidden.bs.modal` has been fired. */
export function fadeOut() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** Forgets which dialogs are shown (between tests). */
export function forgetDialogs() {
    shown.clear();
}
