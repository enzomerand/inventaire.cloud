/**
 * The tabs of the dialog of an item: its details (the form, or the read-only list), its attachments and its history.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`). The tabs exist once the item does: the dialog that
 * adds an item shows the form alone. They follow the pattern of WAI-ARIA tabs: one tab is in the tab order, the arrow keys,
 * Home and End move between them (and show the panel at once), and the panels are labelled by their tab. The dialog always
 * opens on the details.
 */

/** The tabs, in order: the details of the item, its attachments, its history. */
export const TABS = ['details', 'files', 'history'];

/** The id of the button of a tab, which its panel is labelled by. */
const TAB_ID_PREFIX = 'item-tab-';

/**
 * The tab a key moves to.
 *
 * @param {string} current The tab that is shown.
 * @param {string} key The `key` of a keyboard event.
 * @returns {string | null} Null for a key that moves nowhere.
 */
export function tabForKey(current, key) {
    const index = TABS.indexOf(current);

    switch (key) {
        case 'ArrowRight':
            return TABS[(index + 1) % TABS.length];
        case 'ArrowLeft':
            return TABS[(index + TABS.length - 1) % TABS.length];
        case 'Home':
            return TABS[0];
        case 'End':
            return TABS[TABS.length - 1];
        default:
            return null;
    }
}

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function itemTabs() {
    return {
        /** The tab that is shown: `details`, `files` or `history`. */
        tab: 'details',

        /** Whether the dialog shows its tabs: the item exists, and the dialog is not asking to confirm a deletion. */
        get showTabs() {
            return this.mode !== 'create' && !this.asking;
        },

        /** The role of the panels of the dialog: they are the panels of tabs only when the dialog shows its tabs (adding an item shows the form alone). */
        get panelRole() {
            return this.showTabs ? 'tabpanel' : null;
        },

        /** The `tabindex` of the panels: a panel of tabs is reached from its tab with the Tab key, so that a keyboard user can scroll it. */
        get panelTabIndex() {
            return this.showTabs ? '0' : null;
        },

        /**
         * The `aria-labelledby` of a panel: its tab, when the dialog shows its tabs.
         *
         * @param {string} name One of {@link TABS}.
         * @returns {string | null}
         */
        panelLabel(name) {
            return this.showTabs ? TAB_ID_PREFIX + name : null;
        },

        /** Whether the panel of the details is shown. */
        get onDetails() {
            return this.tab === 'details';
        },

        /** Whether the panel of the attachments is shown. */
        get onFiles() {
            return this.tab === 'files';
        },

        /** Whether the panel of the history is shown. */
        get onHistory() {
            return this.tab === 'history';
        },

        /**
         * The value of `aria-selected` for a tab.
         *
         * @param {string} name
         * @returns {string}
         */
        tabSelected(name) {
            return this.tab === name ? 'true' : 'false';
        },

        /**
         * The `tabindex` of a tab: only the one that is shown is in the tab order, the arrow keys reach the others.
         *
         * @param {string} name
         * @returns {string}
         */
        tabIndex(name) {
            return this.tab === name ? '0' : '-1';
        },

        /**
         * The classes of a tab.
         *
         * @param {string} name
         * @returns {string}
         */
        tabClass(name) {
            return this.tab === name ? 'nav-link active' : 'nav-link';
        },

        /**
         * Shows a tab. The history is read the first time it is shown, and again when the item changed since.
         *
         * @param {string} name One of {@link TABS}.
         * @returns {void}
         */
        selectTab(name) {
            if (!TABS.includes(name)) {
                return;
            }

            this.tab = name;

            if (name === 'history') {
                this.showHistory();
            }
        },

        /**
         * Shows the tab a key press on the tabs asks for (the arrow keys, Home and End), and puts the cursor on it.
         *
         * @param {KeyboardEvent} event
         * @returns {void}
         */
        moveTab(event) {
            const next = tabForKey(this.tab, event.key);

            if (next === null) {
                return;
            }

            event.preventDefault();
            this.selectTab(next);
            this.focusTab();
        },

        /**
         * Puts the cursor on the tab that is shown, once the page has caught up with it.
         *
         * @returns {void}
         */
        focusTab() {
            this.$nextTick(() => document.getElementById(TAB_ID_PREFIX + this.tab)?.focus());
        },

        /**
         * Goes back to the details, as the dialog opens.
         *
         * @returns {void}
         */
        resetTabs() {
            this.tab = 'details';
        },
    };
}
