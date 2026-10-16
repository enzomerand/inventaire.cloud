import { describe, expect, it, vi } from 'vitest';

import { compose } from '../../../resources/js/lib/compose.js';
import itemTabs, { TABS, tabForKey } from '../../../resources/js/item/tabs.js';

describe('the tab a key moves to', () => {
    it('is the next one for the right arrow, and the first after the last', () => {
        expect(tabForKey('details', 'ArrowRight')).toBe('files');
        expect(tabForKey('files', 'ArrowRight')).toBe('history');
        expect(tabForKey('history', 'ArrowRight')).toBe('details');
    });

    it('is the previous one for the left arrow, and the last before the first', () => {
        expect(tabForKey('history', 'ArrowLeft')).toBe('files');
        expect(tabForKey('details', 'ArrowLeft')).toBe('history');
    });

    it('is the first for Home and the last for End', () => {
        expect(tabForKey('files', 'Home')).toBe('details');
        expect(tabForKey('files', 'End')).toBe('history');
    });

    it('is none for any other key', () => {
        expect(tabForKey('files', 'Enter')).toBeNull();
        expect(tabForKey('files', 'a')).toBeNull();
        expect(tabForKey('files', 'ArrowDown')).toBeNull();
    });
});

describe('the tabs of the dialog of an item', () => {
    /** The part with what the dialog gives it: a mode, whether it asks, and the history it opens. */
    function tabs(state = {}) {
        return compose(itemTabs(), {
            mode: 'edit',
            asking: false,
            showHistory: vi.fn(),
            $nextTick: (run) => run(),
            ...state,
        });
    }

    it('open on the details', () => {
        expect(tabs().tab).toBe('details');
        expect(TABS).toEqual(['details', 'files', 'history']);
    });

    it('exist once the item does, and not while the dialog asks for a confirmation', () => {
        expect(tabs({ mode: 'create' }).showTabs).toBe(false);
        expect(tabs({ mode: 'edit' }).showTabs).toBe(true);
        expect(tabs({ mode: 'view' }).showTabs).toBe(true);
        expect(tabs({ asking: true }).showTabs).toBe(false);
    });

    it('are panels of tabs only where the dialog shows its tabs, labelled by their tab and reachable by keyboard', () => {
        const editing = tabs({ mode: 'edit' });

        expect(editing.panelRole).toBe('tabpanel');
        expect(editing.panelTabIndex).toBe('0');
        expect(TABS.map((name) => editing.panelLabel(name))).toEqual([
            'item-tab-details',
            'item-tab-files',
            'item-tab-history',
        ]);
    });

    it('are no panels of tabs while an item is added: the form is alone, there is no tab to belong to', () => {
        const adding = tabs({ mode: 'create' });

        expect(adding.panelRole).toBeNull();
        expect(adding.panelTabIndex).toBeNull();
        expect(adding.panelLabel('details')).toBeNull();
    });

    it('show one panel at a time', () => {
        const component = tabs();

        component.selectTab('files');

        expect([component.onDetails, component.onFiles, component.onHistory]).toEqual([false, true, false]);
    });

    it('say which tab is selected, and keep only that one in the tab order', () => {
        const component = tabs();

        component.selectTab('history');

        expect(component.tabSelected('history')).toBe('true');
        expect(component.tabSelected('files')).toBe('false');
        expect(component.tabIndex('history')).toBe('0');
        expect(component.tabIndex('details')).toBe('-1');
        expect(component.tabClass('history')).toBe('nav-link active');
        expect(component.tabClass('details')).toBe('nav-link');
    });

    it('read the history when it is shown', () => {
        const component = tabs();

        component.selectTab('files');
        expect(component.showHistory).not.toHaveBeenCalled();

        component.selectTab('history');
        expect(component.showHistory).toHaveBeenCalledTimes(1);
    });

    it('ignore a tab that does not exist', () => {
        const component = tabs();

        component.selectTab('settings');

        expect(component.tab).toBe('details');
    });

    it('move with the arrow keys, and put the cursor on the tab they reach', () => {
        document.body.innerHTML = '<button id="item-tab-files"></button>';
        const component = tabs();
        const event = { key: 'ArrowRight', preventDefault: vi.fn() };

        component.moveTab(event);

        expect(component.tab).toBe('files');
        expect(event.preventDefault).toHaveBeenCalled();
        expect(document.activeElement?.id).toBe('item-tab-files');
    });

    it('leave the other keys alone', () => {
        const component = tabs();
        const event = { key: 'Tab', preventDefault: vi.fn() };

        component.moveTab(event);

        expect(component.tab).toBe('details');
        expect(event.preventDefault).not.toHaveBeenCalled();
    });

    it('go back to the details', () => {
        const component = tabs();

        component.selectTab('files');
        component.resetTabs();

        expect(component.tab).toBe('details');
    });
});
