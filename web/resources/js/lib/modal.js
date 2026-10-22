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
 * Whether an element is shown: nothing between it and the dialog hides it (the `hidden` attribute, `display: none` from a
 * style or a class, which is how `x-show` hides). A hidden element cannot take the focus.
 *
 * @param {Element} element
 * @param {Element} root The dialog: what is above it is not looked at.
 * @returns {boolean}
 */
function isShown(element, root) {
    for (let node = element; node !== null && node !== root; node = node.parentElement) {
        if ((node instanceof HTMLElement && node.hidden) || getComputedStyle(node).display === 'none') {
            return false;
        }
    }

    return true;
}

/**
 * The first element, in order of the selectors, that matches one and is shown.
 *
 * @param {HTMLElement} root The dialog.
 * @param {string[]} selectors
 * @returns {HTMLElement | null}
 */
function firstShown(root, selectors) {
    for (const selector of selectors) {
        const found = [...root.querySelectorAll(selector)].find((candidate) => isShown(candidate, root));

        if (found instanceof HTMLElement) {
            return found;
        }
    }

    return null;
}

/**
 * Opens a dialog, and puts the cursor in its first input (or in the one marked `data-autofocus`) once it is shown. When
 * the dialog is closed, however it was, the focus goes back to the element that had it before, if that is still on the page.
 *
 * A caller that opens the dialog for one control (the cell of a table that has no editor of its own) can name where the
 * cursor goes: the first of its selectors that finds a control that can be reached wins, and the usual place is the
 * fallback. A control that is hidden (the button that adds a file to a field that is full, or anything inside a tab that is
 * not shown) cannot take the cursor, so it is skipped, like the controls the usual place would pick.
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
            const target = firstShown(element, [
                ...focus,
                '[data-autofocus]',
                '.modal-body :is(input:not([type="hidden"]), select, textarea)',
            ]);

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
 * @returns {() => void} Stops listening. A component that lives shorter than its dialog (one that is taken apart while the dialog
 *   stays) calls it when it goes; the others, which live as long as the page, may ignore it.
 */
export function onModalHidden(element, callback) {
    element.addEventListener('hidden.bs.modal', callback);

    return () => element.removeEventListener('hidden.bs.modal', callback);
}
