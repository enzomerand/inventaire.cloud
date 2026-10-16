/**
 * The gallery of cards and the choice between it and the table, at the level of the component: which view is picked and
 * kept, and what a card holds.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { http } from '../../../resources/js/lib/http.js';
import { showModal } from '../../../resources/js/lib/modal.js';
import { viewKey } from '../../../resources/js/grid/view.js';
import { FIELDS, INVENTORY, field, inventoryAnswer, item, mountComponent, page } from '../support/inventory.js';
import { useRefusingStorage } from '../support/storage.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

/** Gives the page a screen of a width, the way `matchMedia` answers for it. */
function screenOf(width) {
    window.matchMedia = vi.fn((query) => ({
        matches: width <= Number(/max-width:\s*([\d.]+)px/.exec(query)?.[1] ?? 0),
        media: query,
    }));
}

describe('the gallery of the items of an inventory', () => {
    let component;

    /**
     * Opens the page with the given items. `prepare` sets the address or the storage up once the component exists (mounting
     * it starts both from a clean state) and before it reads anything.
     */
    async function open(items, { fields = FIELDS, totals = {}, prepare = () => {}, ...options } = {}) {
        component = mountComponent(inventoryItems, options);
        prepare();
        http.get.mockImplementation(async (url) =>
            url === INVENTORY ? inventoryAnswer(fields, items.length) : page(items, { totals }),
        );
        await component.init();
    }

    const HAMMER = item('i1', {
        name: 'Hammer',
        qty: '1.50',
        price: '19.90',
        state: 'aaaaaaaa',
        fragile: false,
        bought: '2026-10-15',
        labels: ['Tool'],
        grade: 4,
    });

    beforeEach(() => {
        screenOf(1280);
    });

    afterEach(() => {
        delete window.matchMedia;
        window.history.replaceState(null, '', '/');
    });

    describe('the view that is shown', () => {
        it('is the table on a wide screen, when nothing says otherwise', async () => {
            await open([HAMMER]);

            expect(component.view).toBe('table');
            expect(component.showTable).toBe(true);
            expect(component.showGallery).toBe(false);
            expect(component.urlView).toBeNull();
            expect(window.location.search).toBe('');
        });

        it('is the gallery on a phone', async () => {
            screenOf(375);

            await open([HAMMER]);

            expect(component.view).toBe('gallery');
            expect(component.showGallery).toBe(true);
            expect(component.showTable).toBe(false);
            // Not the person's choice: the address does not carry it.
            expect(window.location.search).toBe('');
        });

        it('is the one the address asks for, over the screen and over what was chosen before', async () => {
            screenOf(375);

            await open([HAMMER], {
                prepare: () => {
                    window.history.replaceState(null, '', '/?view=table');
                    window.localStorage.setItem(viewKey(INVENTORY), 'gallery');
                },
            });

            expect(component.view).toBe('table');
            expect(window.location.search).toBe('?view=table');
        });

        it('is the one chosen last for the inventory, over the screen', async () => {
            await open([HAMMER], { prepare: () => window.localStorage.setItem(viewKey(INVENTORY), 'gallery') });
            expect(component.view).toBe('gallery');

            screenOf(375);
            await open([HAMMER], { prepare: () => window.localStorage.setItem(viewKey(INVENTORY), 'table') });
            expect(component.view).toBe('table');
        });

        it('does not take the view of the address for a choice to keep', async () => {
            await open([HAMMER], { prepare: () => window.history.replaceState(null, '', '/?view=gallery') });

            expect(component.view).toBe('gallery');
            expect(window.localStorage.getItem(viewKey(INVENTORY))).toBeNull();
        });

        it('is picked by the screen when the browser refuses the storage', async () => {
            screenOf(375);

            await open([HAMMER], { prepare: useRefusingStorage });

            expect(component.view).toBe('gallery');
        });

        it('shows neither while there is nothing to show', async () => {
            await open([]);

            expect(component.showTable).toBe(false);
            expect(component.showGallery).toBe(false);
        });
    });

    describe('choosing a view', () => {
        beforeEach(async () => {
            await open([HAMMER]);
        });

        it('switches to the gallery, keeps the choice for the inventory and writes it in the address', () => {
            component.chooseView('gallery');

            expect(component.view).toBe('gallery');
            expect(component.isGallery).toBe(true);
            expect(window.localStorage.getItem(viewKey(INVENTORY))).toBe('gallery');
            expect(window.location.search).toBe('?view=gallery');
        });

        it('switches back to the table', () => {
            component.chooseView('gallery');
            component.chooseView('table');

            expect(component.view).toBe('table');
            expect(window.localStorage.getItem(viewKey(INVENTORY))).toBe('table');
            expect(window.location.search).toBe('?view=table');
        });

        it('keeps the view in the address along with the rest of the criteria and the page', async () => {
            component.chooseView('gallery');

            component.query = { ...component.query, q: 'ham' };
            await component.loadItems(2);

            expect(new URLSearchParams(window.location.search).get('view')).toBe('gallery');
            expect(new URLSearchParams(window.location.search).get('q')).toBe('ham');
            expect(new URLSearchParams(window.location.search).get('page')).toBe('2');
        });

        it('keeps working when the browser refuses to keep the choice', () => {
            useRefusingStorage();

            expect(() => component.chooseView('gallery')).not.toThrow();
            expect(component.view).toBe('gallery');
        });

        it('says which button is pressed', () => {
            expect([component.tablePressed, component.galleryPressed]).toEqual(['true', 'false']);
            expect([component.tableButtonClass, component.galleryButtonClass]).toEqual(['active', '']);

            component.chooseView('gallery');

            expect([component.tablePressed, component.galleryPressed]).toEqual(['false', 'true']);
            expect([component.tableButtonClass, component.galleryButtonClass]).toEqual(['', 'active']);
        });

        it('shows the sort select and its direction in the gallery, and on a small screen only in the table', () => {
            expect(component.sortBarClass).toBe('d-sm-none');

            component.chooseView('gallery');

            expect(component.sortBarClass).toBe('');
        });

        it('ends the editing of a cell, which the gallery has none of', async () => {
            await component.startInline(component.rows[0], component.rows[0].cells[0]);
            expect(component.inlineRow).toBe('i1');

            component.chooseView('gallery');

            expect(component.inlineRow).toBeNull();
        });
    });

    describe('a card', () => {
        it('is called by the first text the item holds, and shows its other values with their labels', async () => {
            await open([HAMMER]);

            const [card] = component.cards;

            expect(card.id).toBe('i1');
            expect(card.title).toBe('Hammer');
            expect(card.untitled).toBe(false);
            expect(card.cells.map((cell) => cell.name)).toEqual(['Weight', 'Price', 'State', 'Fragile', 'Bought']);
            expect(card.cells.map((cell) => cell.text || cell.badges.map((badge) => badge.text).join())).toEqual([
                '1.50 kg',
                '€19.90',
                'New',
                'No',
                'Oct 15, 2026',
            ]);
        });

        it('shows at most five other fields, the first that hold something, and leaves the empty ones out', async () => {
            const sparse = item('i2', {
                name: 'Saw',
                price: '10.00',
                labels: ['Tool'],
                grade: 3,
                tint: '#aabbcc',
                bought: '2026-10-15',
                fragile: true,
            });

            await open([sparse]);

            expect(component.cards[0].cells.map((cell) => cell.name)).toEqual([
                'Price',
                'Fragile',
                'Bought',
                'Labels',
                'Grade',
            ]);
        });

        it('shows every value as its type shows it: badges, stars, a swatch', async () => {
            await open([item('i2', { name: 'Saw', state: 'aaaaaaaa', grade: 4 })]);

            const [state, grade] = component.cards[0].cells;

            expect(state.kind).toBe('badges');
            expect(state.badges[0].text).toBe('New');
            expect(grade.kind).toBe('stars');
            expect(grade.title).toBe('4 out of 5');
        });

        it('is called by the next text field when the first holds nothing for this item', async () => {
            const fields = [
                field('code', 'text', {}, { name: 'Code' }),
                field('label', 'text', {}, { name: 'Label' }),
                field('note', 'textarea', {}, { name: 'Note' }),
            ];

            await open([item('i1', { label: 'Big saw', note: 'Sharp' })], { fields });

            expect(component.cards[0].title).toBe('Big saw');
            expect(component.cards[0].cells.map((cell) => cell.name)).toEqual(['Note']);
        });

        it('is "Untitled item" when no text field holds anything, and is marked so', async () => {
            await open([item('i2', { price: '10.00' })]);

            expect(component.cards[0].title).toBe('Untitled item');
            expect(component.cards[0].untitled).toBe(true);
            expect(component.cards[0].cells.map((cell) => cell.name)).toEqual(['Price']);
        });

        it('is only called by a field of the text type, not by an address or a code', async () => {
            const fields = [field('site', 'url', {}, { name: 'Site' }), field('code', 'barcode', {}, { name: 'Code' })];

            await open([item('i2', { site: 'https://example.com', code: '1234' })], { fields });

            expect(component.cards[0].untitled).toBe(true);
            expect(component.cards[0].cells.map((cell) => cell.name)).toEqual(['Site', 'Code']);
        });

        it('keeps the links of its values as links, which are not the card', async () => {
            await open([item('i2', { name: 'Saw', site: 'https://example.com' })], {
                fields: [...FIELDS, field('site', 'url', {}, { name: 'Site' })],
            });

            const site = component.cards[0].cells.find((cell) => cell.key === 'site');

            expect(site.kind).toBe('link');
            expect(site.plain).toBe(false);
        });

        it('has one card for each item of the page, in the order of the list, and follows the list', async () => {
            await open([HAMMER, item('i2', { name: 'Saw' })]);
            expect(component.cards.map((card) => card.id)).toEqual(['i1', 'i2']);

            http.get.mockResolvedValue(page([item('i3', { name: 'Plane' })]));
            await component.loadItems(1);

            expect(component.cards.map((card) => card.title)).toEqual(['Plane']);
        });

        it('opens the item like a row does: the form for who may edit, the details for the others', async () => {
            await open([HAMMER]);
            component.open(component.cards[0]);

            expect(component.mode).toBe('edit');
            expect(component.itemId).toBe('i1');
            expect(showModal).toHaveBeenCalledWith(component.$refs.modal);

            await open([HAMMER], { canEdit: false });
            component.open(component.cards[0]);

            expect(component.mode).toBe('view');
        });

        it('ignores a click on one of its links or buttons, but not on the rest of the card', async () => {
            await open([HAMMER]);
            const card = document.createElement('div');
            const link = document.createElement('a');
            link.href = '/somewhere';
            card.append(link);

            component.openFromRow(component.cards[0], { target: link, composedPath: () => [link, card] });
            expect(showModal).not.toHaveBeenCalled();

            component.openFromRow(component.cards[0], { target: card, composedPath: () => [card] });
            expect(showModal).toHaveBeenCalledTimes(1);
        });
    });

    describe('the totals above the cards', () => {
        it('lists the columns that add up, formatted like the column', async () => {
            await open([HAMMER], { totals: { qty: '12.5', price: '1234.5' } });

            expect(component.galleryTotals).toEqual([
                { key: 'qty', name: 'Weight', text: '12.50 kg' },
                { key: 'price', name: 'Price', text: '€1,234.50' },
            ]);
            expect(component.totalsLabel).toBe('Total');
        });

        it('is shown in the gallery only, and only when there is a total', async () => {
            await open([HAMMER], { totals: { qty: '12.5' } });
            expect(component.showGalleryTotals).toBe(false);

            component.chooseView('gallery');
            expect(component.showGalleryTotals).toBe(true);

            await open([HAMMER]);
            component.chooseView('gallery');
            expect(component.showGalleryTotals).toBe(false);
        });

        it('is those of the selection when the list is narrowed down', async () => {
            await open([HAMMER], {
                totals: { qty: '1.50' },
                prepare: () => window.history.replaceState(null, '', '/?q=ham'),
            });

            expect(component.totalsLabel).toBe('Selection total');
        });
    });
});
