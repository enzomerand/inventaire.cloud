/**
 * Alpine store holding the workspaces of the signed-in user.
 *
 * Two things on the page show them: the switcher of the header (on every page) and the workspaces page. They read
 * the same list, so a workspace created, renamed or deleted on the page appears in the header without a reload.
 * The server stays the source of truth: the header seeds the store with the list it rendered, and the code that
 * changes something asks the API for the list again rather than guessing what the change did (deleting the current
 * workspace, for one, makes the server pick another).
 */

/**
 * @typedef {'owner' | 'editor' | 'viewer'} WorkspaceRole
 * @typedef {{ id: string, name: string, role: WorkspaceRole, current: boolean }} WorkspaceItem
 */

/**
 * Turns a workspace as the API describes it (`WorkspaceResource`) into the shape the interface uses.
 *
 * @param {{ id: string, name: string, role: WorkspaceRole, is_current: boolean }} workspace
 * @returns {WorkspaceItem}
 */
export function fromApi(workspace) {
    return { id: workspace.id, name: workspace.name, role: workspace.role, current: workspace.is_current === true };
}

/**
 * Creates a fresh workspaces store. A factory (rather than a module singleton) so each test, and each Alpine
 * instance, gets isolated state.
 *
 * @returns {{
 *   items: WorkspaceItem[],
 *   readonly current: WorkspaceItem | null,
 *   replace: (items: WorkspaceItem[]) => void,
 * }}
 */
export function createWorkspacesStore() {
    return {
        items: [],

        /** The workspace the person is working in, or null while the list is empty. */
        get current() {
            return this.items.find((item) => item.current) ?? null;
        },

        /**
         * Replaces the whole list.
         *
         * @param {WorkspaceItem[]} items
         * @returns {void}
         */
        replace(items) {
            this.items = items;
        },
    };
}
