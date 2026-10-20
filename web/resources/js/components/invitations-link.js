/**
 * Alpine component of the link to the page of invitations in the header of the app: it shows how many invitations wait for the
 * signed-in person, and goes away when none is left.
 *
 * The server renders the link only when some wait, with the count in the markup (`data-count` on the root, because the CSP build of
 * Alpine does not allow Blade to inject values into expressions). The page of invitations sends the `invitations-changed` event to the
 * window whenever the person answers one (or the list was read), with the new count in `detail.count`; the link follows.
 */
import { tn } from '../lib/i18n.js';

/**
 * @returns {Record<string, any>}
 */
export default function invitationsLink() {
    return {
        /** How many invitations wait for an answer. */
        count: 0,

        /** Whether the link is shown: while some invitation waits. */
        get visible() {
            return this.count > 0;
        },

        /** What the link says to assistive technology: how many invitations wait, in words. */
        get label() {
            return tn('invitations.waiting', this.count);
        },

        /**
         * Takes the count the server rendered.
         *
         * @returns {void}
         */
        init() {
            this.count = Number(this.$root.dataset.count) || 0;
        },

        /**
         * Follows the page of invitations when the number of invitations that wait changes.
         *
         * @param {CustomEvent<{ count?: number }>} event The `invitations-changed` event of the window.
         * @returns {void}
         */
        update(event) {
            this.count = Math.max(0, Number(event.detail?.count) || 0);
        },
    };
}
