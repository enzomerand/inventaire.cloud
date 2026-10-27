/**
 * Alpine component behind the workspace switcher in the header.
 *
 * It seeds the shared `workspaces` store (see stores/workspaces.js) with the list the server rendered, shows it (a
 * workspace of somebody else with the name of its owner under its own), and changes the current workspace through the
 * API. The person is taken to the page of that workspace afterwards, since what a page shows depends on the workspace.
 * The endpoints and the list come from the markup (`data-url`, `data-workspace-url`, `data-workspaces` on the root),
 * because the CSP build of Alpine does not allow Blade to inject values into expressions.
 */
import { fillUrl } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { notify } from '../lib/notify.js';
import { redirectTo } from '../lib/page.js';

/**
 * @returns {Record<string, any>}
 */
export default function workspaceSwitcher() {
    return {
        /** True while a change is in flight; blocks double clicks. */
        busy: false,

        /**
         * The name of the owner of each workspace that belongs to somebody else, by id of workspace. Kept here rather than in
         * the shared store: the workspaces page fills the store again from the API, which does not carry them.
         *
         * @type {Record<string, string>}
         */
        owners: {},

        /** Endpoint that chooses the current workspace (PUT), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /** Page of a workspace, with `__id__` where its ULID goes, from `data-workspace-url` on the component root. */
        get workspaceUrl() {
            return this.$root.dataset.workspaceUrl;
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
            const items = JSON.parse(this.$root.dataset.workspaces);

            this.owners = Object.fromEntries(items.filter((item) => item.owner).map((item) => [item.id, item.owner]));
            this.$store.workspaces.replace(items);
        },

        /**
         * The name of the owner of a workspace that is somebody else's, to tell it from another of the same name (every
         * account starts with a "Personal space"); empty for the workspaces of the person.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        ownerOf(item) {
            return this.owners[item.id] ?? '';
        },

        /**
         * CSS classes of the line that names the owner under the name of a workspace: muted, except in the highlighted
         * entry, where the muted colour would not read on its background.
         *
         * @param {import('../stores/workspaces.js').WorkspaceItem} item
         * @returns {string}
         */
        ownerClass(item) {
            return item.current ? 'd-block small' : 'd-block small text-secondary';
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
         * Makes a workspace the current one, then takes the person to its page.
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

            redirectTo(fillUrl(this.workspaceUrl, item.id));
        },
    };
}
