/**
 * Thin wrapper over Tabler's Bootstrap modal.
 *
 * Opening and closing a dialog goes through here so that the components stay free of the library (and of its
 * requirement for a real browser): their tests replace this module. Bootstrap does the work that makes a dialog
 * accessible: it traps the focus, closes on Escape and on a click on the backdrop, locks the scroll of the page and
 * gives the focus back to the button that opened it.
 */
import { Modal } from '@tabler/core';

/**
 * Opens a dialog, and puts the cursor in its first input (or in the one marked `data-autofocus`) once it is shown.
 *
 * @param {HTMLElement} element The `.modal` element.
 * @returns {void}
 */
export function showModal(element) {
    element.addEventListener(
        'shown.bs.modal',
        () => {
            const target =
                element.querySelector('[data-autofocus]') ??
                element.querySelector('.modal-body :is(input:not([type="hidden"]), select, textarea)');

            target?.focus();
        },
        { once: true },
    );
    Modal.getOrCreateInstance(element).show();
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
