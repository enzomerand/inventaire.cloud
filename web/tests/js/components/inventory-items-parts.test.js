/**
 * The component of the items of an inventory is made of parts (`lib/compose.js`), and a name a later part defines again
 * silently replaces the earlier one. Nothing would say so: a getter of the files could take the place of one of the grid, and
 * the page would misbehave far from the cause. This test makes the collision loud.
 */
import { describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import gridCriteria from '../../../resources/js/grid/criteria.js';
import gridView from '../../../resources/js/grid/gallery.js';
import gridInline from '../../../resources/js/grid/inline.js';
import itemFiles from '../../../resources/js/item/files.js';
import itemHistory from '../../../resources/js/item/history.js';
import itemTabs from '../../../resources/js/item/tabs.js';

vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));

const PARTS = {
    'grid/criteria': gridCriteria,
    'grid/inline': gridInline,
    'grid/gallery': gridView,
    'item/tabs': itemTabs,
    'item/files': itemFiles,
    'item/history': itemHistory,
};

describe('the parts of the component of the items of an inventory', () => {
    it('define no name in common', () => {
        const owners = new Map();
        const shared = [];

        for (const [name, factory] of Object.entries(PARTS)) {
            for (const key of Object.keys(Object.getOwnPropertyDescriptors(factory()))) {
                if (owners.has(key)) {
                    shared.push(`${key} (${owners.get(key)} and ${name})`);
                }

                owners.set(key, name);
            }
        }

        expect(shared).toEqual([]);
    });

    it('are not replaced by the component itself, except for the pieces it is meant to wrap', () => {
        const component = Object.getOwnPropertyDescriptors(inventoryItems());
        const replaced = [];

        for (const [name, factory] of Object.entries(PARTS)) {
            for (const [key, descriptor] of Object.entries(Object.getOwnPropertyDescriptors(factory()))) {
                const kept = component[key];
                const same =
                    typeof descriptor.value === 'function' || descriptor.get
                        ? // A method or a getter is a new function on each call of the factory: compare what it does.
                          String(kept?.value ?? kept?.get) === String(descriptor.value ?? descriptor.get)
                        : true;

                if (!same) {
                    replaced.push(`${key} (${name})`);
                }
            }
        }

        expect(replaced).toEqual([]);
    });
});
