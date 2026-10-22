/**
 * Scanning barcodes on the page of an inventory, at the level of the component: the scan button of a barcode field in the
 * dialog of an item, the scan button of the search box, and the offer to add an item with a code no item has. The panels
 * that read the code (the camera, the reader) are `barcode-scanner.test.js`; here they are the events they send.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { isScannable, scanFormatOf } from '../../../resources/js/fields/index.js';
import { http } from '../../../resources/js/lib/http.js';
import { hideModal, showModal } from '../../../resources/js/lib/modal.js';
import { FIELDS, INVENTORY, field, inventoryAnswer, item, mountComponent, page } from '../support/inventory.js';

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

const CODE = field('code', 'barcode', { format: 'upca' }, { name: 'Code', position: 1 });
const BOOK = field('isbn', 'barcode', { format: 'isbn10' }, { name: 'ISBN', position: 2 });
const FREE = field('tag', 'barcode', {}, { name: 'Tag', position: 3 });
const WITH_CODES = [FIELDS[0], CODE, BOOK];

/** Answers the two reads of the page, whatever the criteria. */
function answer(items, { fields = WITH_CODES, itemsCount = items.length } = {}) {
    http.get.mockImplementation(async (url) => (url === INVENTORY ? inventoryAnswer(fields, itemsCount) : page(items)));
}

/** Lets the pending promises run. */
const settle = () => vi.advanceTimersByTimeAsync(0);

/** What a scanner panel says when it read a code. */
const scanned = (slot, value) => ({ detail: { slot, value } });

describe('scanning barcodes on the page of an inventory', () => {
    let component;
    let started;
    let stopped;
    let listeners;

    /** Opens the page of an inventory of 3 items. */
    async function open({ items = [item('i1', { name: 'Hammer' })], ...options } = {}, mounting = {}) {
        component = mountComponent(inventoryItems, mounting);
        component.$refs.scanModal = document.createElement('div');
        answer(items, options);
        await component.init();
        await settle();
        http.get.mockClear();
    }

    beforeEach(() => {
        vi.useFakeTimers();
        showModal.mockClear();
        hideModal.mockClear();
        document.body.innerHTML = '';
        started = [];
        stopped = [];
        listeners = new AbortController();
        window.addEventListener('barcode-scan-start', (event) => started.push(event.detail), {
            signal: listeners.signal,
        });
        window.addEventListener('barcode-scan-stop', (event) => stopped.push(event.detail), {
            signal: listeners.signal,
        });
    });

    afterEach(() => {
        listeners.abort();
        component.destroy();
        vi.useRealTimers();
        window.history.replaceState(null, '', '/');
    });

    describe('which fields are scanned', () => {
        it('offers the scan to the barcode fields, whatever their format, and to no other field', () => {
            component = mountComponent(inventoryItems);

            expect(FIELDS.some(isScannable)).toBe(false);
            expect([CODE, BOOK, FREE].every(isScannable)).toBe(true);
            expect(component.isScannable(CODE)).toBe(true);
            expect(component.isScannable(FIELDS[0])).toBe(false);
        });

        it('reads the format of the field, any code when it says nothing', () => {
            expect(scanFormatOf(CODE)).toBe('upca');
            expect(scanFormatOf(BOOK)).toBe('isbn10');
            expect(scanFormatOf(FREE)).toBe('any');
            expect(scanFormatOf({ ...FREE, options: undefined })).toBe('any');
        });
    });

    describe('in the dialog of an item', () => {
        beforeEach(async () => {
            await open();
            component.startCreate();
        });

        it('starts the panel of the dialog for the format of the field, and gives way to the camera', () => {
            expect(component.scanning).toBe(false);

            component.scanField(CODE);

            expect(component.scanning).toBe(true);
            expect(component.scanningField).toBe('code');
            expect(started).toEqual([{ slot: 'field', format: 'upca' }]);

            component.scanField(FREE);

            expect(component.scanningField).toBe('tag');
            expect(started.at(-1)).toEqual({ slot: 'field', format: 'any' });
        });

        it('puts the code in the field like a typed one, forgets its refusal, and puts the cursor in its box', () => {
            const box = document.body.appendChild(document.createElement('input'));

            box.id = 'item-field-code';
            component.errors = { code: ['The code must be a valid UPC-A code.'], name: ['Required.'] };
            component.scanField(CODE);
            component.onBarcodeScanned(scanned('field', '012345678905'));

            expect(component.values.code).toBe('012345678905');
            expect(component.errors).toEqual({ name: ['Required.'] });
            expect(component.scanning).toBe(false);
            expect(document.activeElement).toBe(box);
        });

        it('sends the code with the other values when the item is saved, for the server to judge', () => {
            component.values.name = 'Hammer';
            component.scanField(BOOK);
            component.onBarcodeScanned(scanned('field', '0306406152'));

            expect(component.valuesToSend()).toEqual({ name: 'Hammer', isbn: '0306406152' });
        });

        it('fills the field being scanned and no other', () => {
            component.values.isbn = '0131103628';
            component.scanField(CODE);
            component.onBarcodeScanned(scanned('field', '012345678905'));

            expect(component.values.isbn).toBe('0131103628');
        });

        it('leaves the field alone when the person gives up, and shows the form again', () => {
            const box = document.body.appendChild(document.createElement('input'));

            box.id = 'item-field-code';
            component.values.code = '012345678905';
            component.scanField(CODE);
            component.onBarcodeCancelled({ detail: { slot: 'field' } });

            expect(component.values.code).toBe('012345678905');
            expect(component.scanning).toBe(false);
            expect(document.activeElement).toBe(box);
        });

        it('does not take the answer of the panel of the search for its own', () => {
            component.scanField(CODE);
            component.onBarcodeScanned(scanned('search', '3017620422003'));
            component.onBarcodeCancelled({ detail: { slot: 'search' } });

            expect(component.scanning).toBe(true);
            expect(component.values.code).toBe('');
        });

        it('takes a code for a field that is gone (the fields changed meanwhile) for nothing', () => {
            component.scanField(CODE);
            component.fields = [FIELDS[0]];

            expect(() => component.onBarcodeScanned(scanned('field', '012345678905'))).not.toThrow();
            expect(component.scanning).toBe(false);
        });

        it('stops the panel and shows the form again when the dialog is closed during the scan', () => {
            component.scanField(CODE);
            stopped.length = 0;
            component.onDialogHidden();

            expect(component.scanning).toBe(false);
            expect(stopped).toEqual([{ slot: 'field' }]);
        });

        it('stops the panel when another dialog opens during the scan', () => {
            component.scanField(CODE);
            stopped.length = 0;
            component.startCreate();

            expect(component.scanning).toBe(false);
            expect(stopped).toEqual([{ slot: 'field' }]);
        });

        it('has no panel to stop when no scan is running', () => {
            component.onDialogHidden();
            component.startCreate();

            expect(stopped).toEqual([]);
        });
    });

    describe('in the search box', () => {
        beforeEach(async () => {
            await open({ itemsCount: 3 });
        });

        it('opens the dialog of the scan and starts its panel, looking for any code', () => {
            component.scanSearch();

            expect(showModal).toHaveBeenCalledWith(component.$refs.scanModal);
            expect(started).toEqual([{ slot: 'search', format: null }]);
        });

        it('closes that dialog, puts the code in the box and searches for it at once', async () => {
            component.onBarcodeScanned(scanned('search', '3017620422003'));

            expect(hideModal).toHaveBeenCalledWith(component.$refs.scanModal);
            expect(component.searchText).toBe('3017620422003');
            expect(component.query.q).toBe('3017620422003');
            expect(component.scannedCode).toBe('3017620422003');

            await settle();

            expect(http.get).toHaveBeenCalledTimes(1);
            expect(http.get.mock.lastCall[1].params).toMatchObject({ page: 1, q: '3017620422003' });
        });

        it('searches for the 12 digits of a UPC-A code, which the reader gives in 13: the item may hold either form', async () => {
            component.onBarcodeScanned(scanned('search', '0012345678905'));
            await settle();

            expect(component.searchText).toBe('012345678905');
            expect(component.query.q).toBe('012345678905');
            expect(http.get.mock.lastCall[1].params).toMatchObject({ q: '012345678905' });
            // What was scanned is remembered as the reader gave it, for the item that may be added with it.
            expect(component.scannedCode).toBe('0012345678905');
        });

        it('searches for no more than the box takes', () => {
            component.onBarcodeScanned(scanned('search', 'A'.repeat(300)));

            expect(component.searchText).toBe('A'.repeat(200));
            expect(component.scannedCode).toBe('A'.repeat(200));
        });

        it('closes the dialog and searches for nothing when the person gives up', () => {
            component.onBarcodeCancelled({ detail: { slot: 'search' } });

            expect(hideModal).toHaveBeenCalledWith(component.$refs.scanModal);
            expect(http.get).not.toHaveBeenCalled();
            expect(component.query.q).toBe('');
        });

        it('does not read the list again for a code that is already searched, and remembers it was scanned', async () => {
            component.onBarcodeScanned(scanned('search', '3017620422003'));
            await settle();
            http.get.mockClear();
            component.scannedCode = '';
            component.onBarcodeScanned(scanned('search', '3017620422003'));
            await settle();

            expect(http.get).not.toHaveBeenCalled();
            expect(component.scannedCode).toBe('3017620422003');
        });

        it('ignores what the panel of the item dialog says', () => {
            component.onBarcodeScanned(scanned('field', '3017620422003'));

            expect(component.query.q).toBe('');
            expect(hideModal).not.toHaveBeenCalled();
        });

        it('forgets the scan when the text is changed by hand, emptied, or the criteria cleared', () => {
            component.onBarcodeScanned(scanned('search', '3017620422003'));
            component.onSearchInput({ target: { value: '30176204220' } });

            expect(component.scannedCode).toBe('');

            component.scannedCode = '3017620422003';
            component.clearSearch();

            expect(component.scannedCode).toBe('');

            component.scannedCode = '3017620422003';
            component.clearCriteria();

            expect(component.scannedCode).toBe('');
        });
    });

    describe('the offer to add an item with a code no item has', () => {
        /** Scans a code that matches nothing in an inventory of 3 items. */
        async function scanUnknown(code = '0012345678905') {
            answer([], { itemsCount: 3 });
            component.onBarcodeScanned(scanned('search', code));
            await settle();
        }

        it('is made when that very search matches no item of an inventory that holds some', async () => {
            await open({ itemsCount: 3 });

            expect(component.canAddScanned).toBe(false);

            await scanUnknown();

            expect(component.noMatch).toBe(true);
            expect(component.canAddScanned).toBe(true);
        });

        it('is not made when the code matches an item', async () => {
            await open({ itemsCount: 3 });
            answer([item('i2', { name: 'Saw', code: '012345678905' })], { itemsCount: 3 });
            component.onBarcodeScanned(scanned('search', '012345678905'));
            await settle();

            expect(component.canAddScanned).toBe(false);
        });

        it('is not made for a search that was typed, not scanned', async () => {
            await open({ itemsCount: 3 });
            answer([], { itemsCount: 3 });
            component.onSearchInput({ target: { value: '0012345678905' } });
            component.submitSearch();
            await settle();

            expect(component.noMatch).toBe(true);
            expect(component.canAddScanned).toBe(false);
        });

        it('is not made once the words are changed, even to look for the same code again by hand', async () => {
            await open({ itemsCount: 3 });
            await scanUnknown();
            component.onSearchInput({ target: { value: '0012345678906' } });
            component.submitSearch();
            await settle();

            expect(component.canAddScanned).toBe(false);

            component.onSearchInput({ target: { value: '0012345678905' } });
            component.submitSearch();
            await settle();

            expect(component.canAddScanned).toBe(false);
        });

        it('is not made while filters narrow the list as well: the code may not be what matches nothing', async () => {
            await open({ itemsCount: 3 });
            await scanUnknown();
            component.query = { ...component.query, filters: { qty: { min: '5' } } };

            expect(component.canAddScanned).toBe(false);
        });

        it('is not made while the list is being read', async () => {
            await open({ itemsCount: 3 });
            await scanUnknown();
            component.loadingItems = true;

            expect(component.canAddScanned).toBe(false);
        });

        it('is not made to the people who may not add items', async () => {
            await open({ itemsCount: 3 }, { canEdit: false });
            await scanUnknown();

            expect(component.noMatch).toBe(true);
            expect(component.canAddScanned).toBe(false);
        });

        it('is not made in an inventory with no barcode field', async () => {
            await open({ itemsCount: 3, fields: [FIELDS[0], FIELDS[1]] });
            await scanUnknown();

            expect(component.noMatch).toBe(true);
            expect(component.firstBarcodeField).toBeNull();
            expect(component.canAddScanned).toBe(false);
        });

        it('is not made in an inventory with no item (it has an empty state of its own)', async () => {
            await open({ items: [], itemsCount: 0 });
            component.scannedCode = '0012345678905';
            component.query = { ...component.query, q: '0012345678905' };

            expect(component.canAddScanned).toBe(false);
        });

        it('opens the dialog that adds an item, with the code in the first barcode field and in no other', async () => {
            await open({ itemsCount: 3 });
            await scanUnknown('012345678905');
            component.addScanned();

            expect(showModal).toHaveBeenCalledWith(component.$refs.modal);
            expect(component.mode).toBe('create');
            expect(component.values.code).toBe('012345678905');
            expect(component.values.isbn).toBe('');
            expect(component.valuesToSend()).toEqual({ code: '012345678905' });
        });

        it('puts the code in the form of the field, as a scan for that field would: a UPC-A read as an EAN-13', async () => {
            await open({ itemsCount: 3 });
            await scanUnknown('0012345678905');
            component.addScanned();

            expect(component.values.code).toBe('012345678905');
        });

        it('puts a book in an ISBN-10 field as its ISBN-10', async () => {
            await open({ itemsCount: 3, fields: [FIELDS[0], BOOK, CODE] });
            await scanUnknown('9780306406157');
            component.addScanned();

            expect(component.values.isbn).toBe('0306406152');
            expect(component.values.code).toBe('');
        });

        it('keeps the text of a code that is not an EAN-13 as it is', async () => {
            await open({ itemsCount: 3, fields: [FIELDS[0], FREE] });
            await scanUnknown('AB-12345');
            component.addScanned();

            expect(component.values.tag).toBe('AB-12345');
        });

        it('opens nothing when there is no code or no barcode field', async () => {
            await open({ itemsCount: 3, fields: [FIELDS[0]] });
            component.scannedCode = '3017620422003';
            component.addScanned();

            expect(showModal).not.toHaveBeenCalled();

            await open({ itemsCount: 3 });
            component.addScanned();

            expect(showModal).not.toHaveBeenCalled();
        });
    });
});
