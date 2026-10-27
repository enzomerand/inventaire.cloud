/**
 * Helpers shared by the pages that drive forms and links from the markup: the validation errors of the API, bringing the
 * first of them into view, the URL templates and the wait a refusal for going too fast asks for.
 */
import { t } from './i18n.js';

/** Past this wait (two minutes), the time to wait is told in minutes rather than seconds. */
const LONG_WAIT_SECONDS = 120;

/** What shows a refusal in a form: the control that was refused, and the messages that say why (Bootstrap's and Tabler's classes). */
const REFUSAL = '.is-invalid, .invalid-feedback, .text-danger, .alert-danger';

/** The controls a person can put the cursor in to fix a value. */
const CONTROL = 'input:not([type="hidden"]), select, textarea';

/**
 * Keeps the first message of each field of a 422 answer: forms show one message under each input.
 *
 * @param {Record<string, string[]>} errors The `errors` of an `HttpError` (field name to messages).
 * @returns {Record<string, string>} Field name to its first message.
 */
export function firstMessages(errors) {
    return Object.fromEntries(
        Object.entries(errors)
            .filter(([, messages]) => Array.isArray(messages) && messages.length > 0)
            .map(([name, messages]) => [name, messages[0]]),
    );
}

/**
 * Whether nothing between an element and the root of a dialog hides it, the way the pages hide things: `x-show` sets a style,
 * `:hidden` (or the markup) the `hidden` attribute. What a stylesheet hides is not looked at, so a dialog that is not open
 * has nothing to find only because the pages never ask while it is closed.
 *
 * @param {Element} element
 * @param {Element} root The element the search started from, which is the last one looked at.
 * @returns {boolean}
 */
function isShown(element, root) {
    for (let node = /** @type {HTMLElement | null} */ (element); node !== null; node = node.parentElement) {
        if (node.hidden || node.style.display === 'none') {
            return false;
        }

        if (node === root) {
            break;
        }
    }

    return true;
}

/**
 * The control to put the cursor in for a refusal: the refused control itself, or the first control that shares the message's
 * block (a message is written under the control it is about). The message that opens a form (`.alert-danger`) is about no
 * control.
 *
 * @param {HTMLElement} refusal
 * @param {Element} root
 * @returns {HTMLElement | null}
 */
function controlOf(refusal, root) {
    if (refusal.matches(CONTROL)) {
        return /** @type {HTMLInputElement} */ (refusal).disabled ? null : refusal;
    }

    if (refusal.classList.contains('alert-danger')) {
        return null;
    }

    const controls = /** @type {HTMLElement[]} */ ([...(refusal.parentElement?.querySelectorAll(CONTROL) ?? [])]);

    return (
        controls.find((control) => !(/** @type {HTMLInputElement} */ (control).disabled) && isShown(control, root)) ??
        null
    );
}

/**
 * Brings the first refusal a dialog shows into view and puts the cursor on its control, once a 422 has been rendered. The
 * first invalid control of a long form can sit above or below what the scrolling part of the dialog shows, and then "Save"
 * looks as if it did nothing. The first refusal is the first one in reading order that is shown: a control marked
 * `.is-invalid`, or a message that holds some text (`.invalid-feedback`, `.text-danger`, `.alert-danger`).
 *
 * The cursor goes to the control the refusal is about when there is one; a message that opens the form (an alert) is only
 * scrolled to, so that the cursor is not taken to something that cannot be typed in. Call it once the refusals are displayed
 * ({@link revealFirstRefusalOnceShown}).
 *
 * @param {Element | null | undefined} root The dialog (or any element that holds the form).
 * @returns {HTMLElement | null} The refusal that was brought into view, null when there is none.
 */
export function revealFirstRefusal(root) {
    if (!root) {
        return null;
    }

    const refusals = /** @type {HTMLElement[]} */ ([...root.querySelectorAll(REFUSAL)]);
    const refusal = refusals.find(
        (element) =>
            (element.classList.contains('is-invalid') || (element.textContent ?? '').trim() !== '') &&
            isShown(element, root),
    );

    if (refusal === undefined) {
        return null;
    }

    refusal.scrollIntoView?.({ block: 'center' });
    // The refusal was scrolled to already: the cursor must not scroll the dialog somewhere else.
    controlOf(refusal, root)?.focus({ preventScroll: true });

    return refusal;
}

/**
 * {@link revealFirstRefusal} once Alpine has displayed the refusals. `x-show` displays an element on the next animation frame
 * (on a timer when the page is hidden), after `$nextTick` has run: a refusal that is only a message would still be hidden,
 * and skipped. Call it from `$nextTick`, so that it comes after the frame Alpine asked for.
 *
 * @param {Element | null | undefined} root The dialog (or any element that holds the form).
 * @returns {void}
 */
export function revealFirstRefusalOnceShown(root) {
    const later =
        document.visibilityState === 'visible'
            ? requestAnimationFrame
            : (/** @type {() => void} */ run) => setTimeout(run);

    later(() => revealFirstRefusal(root));
}

/**
 * Fills a URL template whose placeholder `__id__` stands for an identifier (the routes of the pages are written that way
 * in the markup, because the CSP build of Alpine does not allow Blade to inject values into expressions).
 *
 * @param {string} template For example `/app/w/__id__`.
 * @param {string} id The identifier to put in.
 * @returns {string}
 */
export function fillUrl(template, id) {
    return template.replace('__id__', encodeURIComponent(id));
}

/**
 * Tells how long to wait after a `429`, in minutes when it is long.
 *
 * @param {number | null} seconds The server's Retry-After, if it gave one.
 * @returns {string} A message of the interface; the general one when the wait is not known.
 */
export function throttledMessage(seconds) {
    if (!seconds) {
        return t('error.too_many_requests');
    }

    return seconds > LONG_WAIT_SECONDS
        ? t('login.error.throttled_minutes', { minutes: Math.ceil(seconds / 60) })
        : t('login.error.throttled', { seconds });
}
