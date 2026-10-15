/**
 * Thin wrapper over Tabler's Bootstrap offcanvas (a panel that slides in from the edge of the screen).
 *
 * Like `modal.js`, it keeps the components free of the library and of its requirement for a real browser: their tests
 * replace this module. Bootstrap traps the focus in the panel, closes it on Escape and on a click outside, and locks the
 * scroll of the page; it gives the focus back only to a button wired with `data-bs-toggle`, so this module remembers where
 * the focus was and puts it back itself.
 */
import { Offcanvas } from '@tabler/core';

/**
 * Opens a panel. When it is closed, however it was, the focus goes back to the element that had it before, if that is
 * still on the page.
 *
 * Nothing is waited for when Bootstrap ignores the call (a panel that is in the middle of opening or closing): its
 * `show.bs.offcanvas` event is not fired then, and the listener that would be left behind is not added.
 *
 * @param {HTMLElement} element The `.offcanvas` element.
 * @returns {void}
 */
export function showOffcanvas(element) {
    const opener = document.activeElement;
    let started = false;
    const markStarted = () => {
        started = true;
    };

    element.addEventListener('show.bs.offcanvas', markStarted, { once: true });
    Offcanvas.getOrCreateInstance(element).show();
    element.removeEventListener('show.bs.offcanvas', markStarted);

    if (!started) {
        return;
    }

    element.addEventListener(
        'hidden.bs.offcanvas',
        () => {
            if (opener instanceof HTMLElement && opener !== document.body && opener.isConnected) {
                opener.focus();
            }
        },
        { once: true },
    );
}

/**
 * Closes a panel.
 *
 * @param {HTMLElement} element The `.offcanvas` element.
 * @returns {void}
 */
export function hideOffcanvas(element) {
    Offcanvas.getInstance(element)?.hide();
}
