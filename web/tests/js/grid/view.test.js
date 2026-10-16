import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { defaultView, isNarrow, parseView, rememberView, viewKey } from '../../../resources/js/grid/view.js';
import { useMemoryStorage, useRefusingStorage } from '../support/storage.js';

const INVENTORY = '/api/v1/inventories/tools';

/** Gives the page a screen of a width, the way `matchMedia` answers for it. */
function screenOf(width) {
    window.matchMedia = vi.fn((query) => ({
        matches: width <= Number(/max-width:\s*([\d.]+)px/.exec(query)?.[1] ?? 0),
        media: query,
    }));
}

describe('the view of the items', () => {
    beforeEach(() => {
        useMemoryStorage();
    });

    afterEach(() => {
        delete window.matchMedia;
    });

    it('reads a view from text, and only the two it knows', () => {
        expect(parseView('table')).toBe('table');
        expect(parseView('gallery')).toBe('gallery');
        expect(parseView('grid')).toBeNull();
        expect(parseView(null)).toBeNull();
        expect(parseView(undefined)).toBeNull();
    });

    describe('by the width of the screen', () => {
        it('is the table on a screen as wide as a tablet, and the gallery on anything narrower', () => {
            screenOf(576);
            expect(isNarrow()).toBe(false);
            expect(defaultView(INVENTORY)).toBe('table');

            screenOf(575);
            expect(isNarrow()).toBe(true);
            expect(defaultView(INVENTORY)).toBe('gallery');

            screenOf(375);
            expect(defaultView(INVENTORY)).toBe('gallery');
        });

        it('reads the width of the window when the browser has no matchMedia', () => {
            delete window.matchMedia;
            vi.spyOn(window, 'innerWidth', 'get').mockReturnValue(400);

            expect(isNarrow()).toBe(true);
        });
    });

    describe('by what the person chose', () => {
        it('is the choice made last for the inventory, whatever the width of the screen', () => {
            screenOf(375);
            rememberView(INVENTORY, 'table');
            expect(defaultView(INVENTORY)).toBe('table');

            screenOf(1280);
            rememberView(INVENTORY, 'gallery');
            expect(defaultView(INVENTORY)).toBe('gallery');
        });

        it('is kept for each inventory on its own', () => {
            screenOf(1280);
            rememberView(INVENTORY, 'gallery');

            expect(defaultView('/api/v1/inventories/books')).toBe('table');
            expect(viewKey(INVENTORY)).not.toBe(viewKey('/api/v1/inventories/books'));
        });

        it('is ignored when what was kept is not a view', () => {
            screenOf(1280);
            window.localStorage.setItem(viewKey(INVENTORY), 'carousel');

            expect(defaultView(INVENTORY)).toBe('table');
        });

        it('falls back to the width of the screen, and breaks nothing, when the browser refuses the storage', () => {
            useRefusingStorage();
            screenOf(375);

            expect(() => rememberView(INVENTORY, 'table')).not.toThrow();
            expect(defaultView(INVENTORY)).toBe('gallery');
        });
    });
});
