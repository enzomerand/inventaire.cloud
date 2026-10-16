/**
 * Thin wrapper over Tabler's Bootstrap modal.
 *
 * Opening and closing a dialog goes through here so that the components stay free of the library (and of its
 * requirement for a real browser): their tests replace this module. Bootstrap does most of the work that makes a dialog
 * accessible: it traps the focus, closes on Escape and on a click on the backdrop, and locks the scroll of the page.
 * It gives the focus back to the button that opened the dialog only for a button wired with `data-bs-toggle`; the
 * dialogs here are opened by calling `show()`, so this module remembers where the focus was and puts it back itself.
 */
import { Modal } from '@tabler/core';

/**
 * Opens a dialog, and puts the cursor in its first input (or in the one marked `data-autofocus`) once it is shown. When
 * the dialog is closed, however it was, the focus goes back to the element that had it before, if that is still on the page.
 *
 * A caller that opens the dialog for one control (the cell of a table that has no editor of its own) can name where the
 * cursor goes: the first of its selectors that finds something wins, and the usual place is the fallback.
 *
 * Nothing is waited for when Bootstrap ignores the call (a dialog that is in the middle of opening or closing): its
 * `show.bs.modal` event is not fired then, and the listeners that would be left behind are not added.
 *
 * @param {HTMLElement} element The `.modal` element.
 * @param {{ focus?: string[] }} [options] `focus`: CSS selectors of where the cursor goes, in order of preference.
 * @returns {void}
 */
export function showModal(element, { focus = [] } = {}) {
    const opener = document.activeElement;
    let started = false;
    const markStarted = () => {
        started = true;
    };

    element.addEventListener('show.bs.modal', markStarted, { once: true });
    Modal.getOrCreateInstance(element).show();
    element.removeEventListener('show.bs.modal', markStarted);

    if (!started) {
        return;
    }

    element.addEventListener(
        'shown.bs.modal',
        () => {
            const asked = focus.map((selector) => element.querySelector(selector)).find((found) => found !== null);
            const target =
                asked ??
                element.querySelector('[data-autofocus]') ??
                element.querySelector('.modal-body :is(input:not([type="hidden"]), select, textarea)');

            target?.focus();
        },
        { once: true },
    );
    element.addEventListener(
        'hidden.bs.modal',
        () => {
            if (opener instanceof HTMLElement && opener !== document.body && opener.isConnected) {
                opener.focus();
            }
        },
        { once: true },
    );
}

/**
 * Closes a dialog.
 *
 * @param {HTMLElement} element The `.modal` element.
 * @returns {void}
 */
export function hideModal(element) {
    Modal.getInstance(element)?.hide();
}

/**
 * Calls a function whenever the dialog has been closed, however it was (a button, Escape, a click outside).
 *
 * @param {HTMLElement} element The `.modal` element.
 * @param {() => void} callback
 * @returns {void}
 */
export function onModalHidden(element, callback) {
    element.addEventListener('hidden.bs.modal', callback);
}
