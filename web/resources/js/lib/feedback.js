/**
 * What the components that copy a value or speak to a live region have in common.
 *
 * The functions work on an Alpine component (the object `this` stands for in its methods): they write the properties the
 * markup reads (`announcement` for the live region, `copied` for the button that copies) and use its `$nextTick`.
 */

/** How long a button says it copied, in milliseconds. */
const COPIED_MS = 2000;

/**
 * Says something in a live region. The region is emptied first, so that the same sentence said twice in a row is announced
 * twice.
 *
 * @param {Record<string, any>} component The Alpine component that owns the region.
 * @param {string} message
 * @param {string} [property] The property of the component the region shows.
 * @returns {void}
 */
export function announce(component, message, property = 'announcement') {
    component[property] = '';
    component.$nextTick(() => {
        component[property] = message;
    });
}

/**
 * Copies a value, and tells it on the button (`copied`, for a moment) and in the live region. Where the browser does not
 * allow it (a page that is not served over https, a refused permission), the value is selected instead and the selection is
 * copied when the browser still lets a page do it; when nothing works, the person is told to copy it with the keyboard.
 *
 * The component keeps the timer that takes "copied" off the button in `copiedTimer`, so that it can stop it when it goes away.
 *
 * @param {Record<string, any>} component The Alpine component with the button (`copied`, `copiedTimer`, `announcement`).
 * @param {string} value What to copy.
 * @param {{ select: () => void, copied: string, hint: string }} options `select` selects the value in its box; `copied` and
 *   `hint` are the sentences announced when the value was copied and when it was only selected.
 * @returns {Promise<void>}
 */
export async function copyValue(component, value, options) {
    let done;

    try {
        await navigator.clipboard.writeText(value);
        done = true;
    } catch {
        options.select();

        try {
            done = document.execCommand('copy') === true;
        } catch {
            done = false;
        }
    }

    if (!done) {
        announce(component, options.hint);

        return;
    }

    component.copied = true;
    announce(component, options.copied);
    clearTimeout(component.copiedTimer);
    component.copiedTimer = setTimeout(() => {
        component.copied = false;
    }, COPIED_MS);
}
