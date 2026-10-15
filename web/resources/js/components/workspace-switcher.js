/**
 * Alpine component behind the workspace switcher in the header.
 *
 * It seeds the shared `workspaces` store (see stores/workspaces.js) with the list the server rendered, shows it,
 * and changes the current workspace through the API. The page is reloaded afterwards, since what it shows depends on
 * the workspace. The endpoint and the list come from the markup (`data-url`, `data-workspaces` on the root),
 * because the CSP build of Alpine does not allow Blade to inject values into expressions.
 */
import { HttpError, http } from '../lib/http.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';

/**
 * @returns {Record<string, any>}
 */
export default function workspaceSwitcher() {
    return {
        /** True while a change is in flight; blocks double clicks. */
        busy: false,

        /** Endpoint that chooses the current workspace (PUT), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** The workspaces of the person, from the shared store. */
        get workspaces() {
            return this.$store.workspaces.items;
        },

        /** Name of the current workspace, for the button of the dropdown. */
        get currentName() {
            return this.$store.workspaces.current?.name ?? '';
        },

        /**
         * Fills the shared store with the list the server rendered in the markup.
         *
         * @returns {void}
         */
        init() {
            this.$store.workspaces.replace(JSON.parse(this.$root.dataset.workspaces ?? '[]'));
        },

        /**
         * CSS classes of one entry of the dropdown: the current workspace is highlighted.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        itemClass(item) {
            return item.current ? 'dropdown-item active' : 'dropdown-item';
        },

        /**
         * Makes a workspace the current one, then reloads the page so that it shows that workspace.
         *
         * Failures the HTTP helper already reported (network, 5xx, expired session...) are not repeated. A 422 means
         * the workspace is no longer one the person belongs to: the server's translated message is shown.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item The entry that was chosen.
         * @returns {Promise<void>}
         */
        async select(item) {
            if (this.busy || item.current) {
                return;
            }

            this.busy = true;

            try {
                await http.put(this.url, { workspace: item.id });
            } catch (error) {
                this.busy = false;

                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.errors.workspace?.[0] ?? error.message);
                }

                return;
            }

            reloadPage();
        },
    };
}
