/**
 * The dialog of an item while requests are on its way, with a dialog that closes the way Bootstrap's does: `hide.bs.modal`
 * first (which can be cancelled), `hidden.bs.modal` once the fade is over (see `support/modal.js`), and a `$nextTick` that
 * runs after the code that asked. The components are tested elsewhere against a dialog that closes at once and a
 * `$nextTick` that runs at once, which cannot show what happens when the person closes the dialog (or opens another) while a
 * request is in flight: that is what is checked here, for the files of an item.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { hideModal, showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { dismiss, fadeOut, forgetDialogs } from '../support/modal.js';
import {
    FIELDS,
    INVENTORY,
    ITEMS,
    attachment,
    deferred,
    field,
    inventoryAnswer,
    item,
    mountComponent,
    page,
} from '../support/inventory.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', async () => import('../support/modal.js'));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const PHOTOS = field('photos', 'photo', { max_files: 3 }, { name: 'Photos' });
const DOCS = field('docs', 'file', { max_files: 2 }, { name: 'Documents' });
const FILE_FIELDS = [FIELDS[0], PHOTOS, DOCS];

/** An item with files, as `ItemResource` describes it. */
function withFiles(id, values, attachments) {
    return { ...item(id, values), attachments };
}

const SAW = withFiles('i2', { name: 'Saw' }, []);
const PLANE = withFiles('i3', { name: 'Plane' }, []);
const FRONT = attachment('a1', 'photos', { name: 'front.jpg', size: 100 });
const SIDE = attachment('a2', 'photos', { name: 'side.jpg', size: 100 });
const SAW_WITH_FRONT = withFiles('i2', { name: 'Saw', photos: ['a1'] }, [FRONT]);
const CREATED = withFiles('new', { name: 'Chisel' }, []);
const CREATED_WITH_FRONT = withFiles('new', { name: 'Chisel', photos: ['a1'] }, [FRONT]);

/** A file the person picked, with the size the test needs (the content does not matter). */
function picked(name, { type = 'image/jpeg', size = 100 } = {}) {
    const one = new File(['x'], name, { type });

    Object.defineProperty(one, 'size', { value: size });

    return one;
}

describe('the dialog of an item while its requests are on their way', () => {
    let component;

    /** Opens the page with the given items. */
    async function open(items = [SAW, PLANE], options = {}) {
        component = mountComponent(inventoryItems, { laterTick: true, ...options });
        http.get.mockImplementation(async (url) =>
            url === INVENTORY ? inventoryAnswer(FILE_FIELDS, items.length) : page(items),
        );
        await component.init();
        await Promise.resolve();
    }

    /** How many times the list of items was read. */
    const itemReads = () => http.get.mock.calls.filter(([url]) => url === ITEMS).length;

    /** The dialog is reset whatever the person did (what Bootstrap's `hidden.bs.modal` makes it do, which cancelling `hide` does not stop). */
    function resetFromOutside() {
        component.$refs.modal.dispatchEvent(new Event('hidden.bs.modal'));
    }

    beforeEach(() => {
        for (const mock of [http.get, http.post, http.patch, http.delete, notify]) {
            mock.mockReset();
        }

        showModal.mockClear();
        hideModal.mockClear();

        forgetDialogs();
    });

    describe('closing it by hand while the item is being saved', () => {
        it('keeps the dialog and the files while the request is in flight, then sends the files and closes the dialog itself', async () => {
            await open([]);
            const creating = deferred();

            component.startCreate();
            component.values.name = 'Chisel';
            await component.receive([picked('front.jpg')], 'photos');
            http.post.mockImplementation(async (url) =>
                url === ITEMS ? creating.promise : { data: CREATED_WITH_FRONT },
            );
            const saving = component.save();

            // Escape, a click outside, the cross, Cancel: Bootstrap asks `hide()`, and the guard cancels it.
            const closing = dismiss(component.$refs.modal);

            await fadeOut();

            expect(closing).toBe(false);
            expect(component.staged.map((one) => one.file.name)).toEqual(['front.jpg']);

            creating.resolve({ data: CREATED });
            await saving;

            expect(http.post.mock.calls.map(([url]) => url)).toEqual([ITEMS, `${ITEMS}/new/attachments`]);
            expect(notify).toHaveBeenCalledWith('success', 'Item added.');

            // The code closes the dialog itself when it is done with it, busy or not.
            expect(hideModal.mock.results.at(-1).value).toBe(true);

            await fadeOut();

            expect(component.dialogItem).toBeNull();
        });

        it('is allowed once nothing is on its way', async () => {
            await open();
            component.open({ id: 'i2' });

            expect(dismiss(component.$refs.modal)).toBe(true);

            await fadeOut();

            expect(component.dialogItem).toBeNull();
        });

        it('does not stop the code from closing the dialog when it deletes the item', async () => {
            await open();
            component.open({ id: 'i2' });
            http.delete.mockResolvedValue({});

            await component.confirmDelete();
            await fadeOut();

            expect(hideModal).toHaveBeenCalledTimes(1);
            expect(hideModal.mock.results[0].value).toBe(true);
            expect(component.dialogItem).toBeNull();
        });
    });

    describe('a dialog that was reset anyway while the item is being saved', () => {
        it('does not send the files chosen in the next dialog to the item that was just added', async () => {
            await open([]);
            const creating = deferred();

            component.startCreate();
            component.values.name = 'Chisel';
            await component.receive([picked('front.jpg')], 'photos');
            http.post.mockImplementation(async (url) =>
                url === ITEMS ? creating.promise : { data: CREATED_WITH_FRONT },
            );
            const saving = component.save();

            resetFromOutside();
            component.startCreate();
            await component.receive([picked('side.jpg')], 'photos');
            creating.resolve({ data: CREATED });
            await saving;

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.staged.map((one) => one.file.name)).toEqual(['side.jpg']);
            expect(component.mode).toBe('create');
            // It is the new dialog that is open: nothing closed it, and the person is told the files of the first one were lost.
            expect(hideModal).not.toHaveBeenCalled();
            expect(notify).toHaveBeenCalledWith('warning', 'Item added, but 1 file could not be sent.');
        });

        it('neither closes nor congratulates a dialog that is not the one that was saved, and gives up the files that had not gone', async () => {
            await open();
            const creating = deferred();
            const first = deferred();

            component.startCreate();
            component.values.name = 'Chisel';
            await component.receive([picked('front.jpg'), picked('side.jpg')], 'photos');
            http.post.mockImplementation(async (url) => (url === ITEMS ? creating.promise : first.promise));
            const saving = component.save();

            creating.resolve({ data: CREATED });
            await vi.waitFor(() => expect(http.post).toHaveBeenCalledTimes(2));

            resetFromOutside();
            component.open({ id: 'i2' });
            first.resolve({ data: CREATED_WITH_FRONT });
            await saving;

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(hideModal).not.toHaveBeenCalled();
            expect(component.uploads).toEqual([]);
            expect(component.itemId).toBe('i2');
            expect(notify).toHaveBeenCalledWith('warning', 'Item added, but 1 file could not be sent.');
            expect(notify).not.toHaveBeenCalledWith('success', 'Item added.');
        });
    });

    describe('a dialog that moved on while the last file of the item being saved was going up', () => {
        it('is neither closed nor told anything: the item was added, and the dialog that is open is another one', async () => {
            await open();
            const first = deferred();

            component.startCreate();
            component.values.name = 'Chisel';
            await component.receive([picked('front.jpg')], 'photos');
            http.post.mockImplementation(async (url) => (url === ITEMS ? { data: CREATED } : first.promise));
            const saving = component.save();

            await vi.waitFor(() => expect(http.post).toHaveBeenCalledTimes(2));
            resetFromOutside();
            component.open({ id: 'i2' });
            first.resolve({ data: CREATED_WITH_FRONT });
            await saving;

            expect(hideModal).not.toHaveBeenCalled();
            expect(notify).not.toHaveBeenCalled();
            expect(component.itemId).toBe('i2');
            // The list was read again all the same: the item is in it.
            expect(itemReads()).toBe(2);
        });
    });

    describe('closing the dialog while files go up to an item that exists', () => {
        it('gives up the files of the batch that were not sent, shows nothing in the next dialog, and says so', async () => {
            await open();
            component.open({ id: 'i2' });
            const first = deferred();

            http.post.mockReturnValueOnce(first.promise).mockResolvedValue({ data: SAW_WITH_FRONT });
            const receiving = component.receive(
                [picked('front.jpg'), picked('side.jpg'), picked('back.jpg')],
                'photos',
            );

            expect(component.uploads.map((upload) => upload.state)).toEqual(['sending', 'queued', 'queued']);

            resetFromOutside();
            component.open({ id: 'i3' });
            first.resolve({ data: SAW_WITH_FRONT });
            await receiving;

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.uploads).toEqual([]);
            expect(component.dialogItem.id).toBe('i3');
            expect(notify).toHaveBeenCalledWith('warning', '2 files were not sent, as the dialog was closed.');
            // The table has the item as the server holds it, whichever dialog is open.
            expect(component.items.find((one) => one.id === 'i2')).toBe(SAW_WITH_FRONT);
        });

        it('says it with the singular when one file was given up', async () => {
            await open();
            component.open({ id: 'i2' });
            const first = deferred();

            http.post.mockReturnValueOnce(first.promise);
            const receiving = component.receive([picked('front.jpg'), picked('side.jpg')], 'photos');

            resetFromOutside();
            first.resolve({ data: SAW_WITH_FRONT });
            await receiving;

            expect(notify).toHaveBeenCalledWith('warning', '1 file was not sent, as the dialog was closed.');
        });

        it('still shows the answer in a dialog opened again on the same item', async () => {
            await open();
            component.open({ id: 'i2' });
            const sending = deferred();

            http.post.mockReturnValue(sending.promise);
            const receiving = component.receive([picked('front.jpg')], 'photos');

            resetFromOutside();
            component.open({ id: 'i2' });
            sending.resolve({ data: SAW_WITH_FRONT });
            await receiving;

            expect(component.dialogItem).toBe(SAW_WITH_FRONT);
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['front.jpg']);
        });

        it('leaves a dialog that was closed for good alone when the answer comes', async () => {
            await open();
            component.open({ id: 'i2' });
            const sending = deferred();

            http.post.mockReturnValue(sending.promise);
            const receiving = component.receive([picked('front.jpg')], 'photos');

            resetFromOutside();
            sending.resolve({ data: SAW_WITH_FRONT });
            await receiving;

            expect(component.dialogItem).toBeNull();
            expect(component.items.find((one) => one.id === 'i2')).toBe(SAW_WITH_FRONT);
        });
    });

    describe('an item that was deleted elsewhere', () => {
        it('closes the dialog when a file is refused because the item is gone, and the list no longer has it', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(404, 'Not found'));
            http.get.mockImplementation(async (url) =>
                url === INVENTORY ? inventoryAnswer(FILE_FIELDS, 1) : page([PLANE]),
            );

            await component.receive([picked('front.jpg'), picked('side.jpg')], 'photos');

            expect(hideModal).toHaveBeenCalledWith(component.$refs.modal);
            expect(component.items.map((one) => one.id)).toEqual(['i3']);
            // The second file was not even tried: the item is gone.
            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.uploads.map((upload) => [upload.state, upload.retryable])).toEqual([
                ['error', false],
                ['error', false],
            ]);
        });

        it('keeps the dialog when the item is still in the list', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(404, 'Not found'));

            await component.receive([picked('front.jpg')], 'photos');

            expect(hideModal).not.toHaveBeenCalled();
        });

        it('closes the dialog when removing a file finds the item gone', async () => {
            await open([SAW_WITH_FRONT, PLANE]);
            component.open({ id: 'i2' });
            component.askRemove({ id: 'a1', name: 'front.jpg' });
            http.delete.mockRejectedValue(new HttpError(404, 'Not found'));
            http.get.mockImplementation(async (url) =>
                url === INVENTORY ? inventoryAnswer(FILE_FIELDS, 1) : page([PLANE]),
            );

            await component.confirmRemove();

            expect(hideModal).toHaveBeenCalledWith(component.$refs.modal);
            expect(component.removing).toBeNull();
        });

        it('shows the item as the list holds it when only the file is gone', async () => {
            await open([SAW_WITH_FRONT, PLANE]);
            component.open({ id: 'i2' });
            component.askRemove({ id: 'a1', name: 'front.jpg' });
            http.delete.mockRejectedValue(new HttpError(404, 'Not found'));
            http.get.mockImplementation(async (url) =>
                url === INVENTORY ? inventoryAnswer(FILE_FIELDS, 2) : page([SAW, PLANE]),
            );

            await component.confirmRemove();

            expect(hideModal).not.toHaveBeenCalled();
            expect(component.dialogItem).toBe(component.items[0]);
            expect(component.filesOf(PHOTOS)).toEqual([]);
        });
    });

    describe('the list of items while files are added', () => {
        it('is read again when a reading that began before the file was stored answers after it', async () => {
            await open();
            component.open({ id: 'i2' });
            const stale = deferred();
            const reads = [stale.promise, Promise.resolve(page([SAW_WITH_FRONT, PLANE]))];

            http.get.mockImplementation(async (url) =>
                url === INVENTORY ? inventoryAnswer(FILE_FIELDS, 2) : reads.shift(),
            );
            http.post.mockResolvedValue({ data: SAW_WITH_FRONT });
            // A reading of the list is on its way when the answer of the upload comes.
            const reading = component.loadItems(1);
            const readsBefore = itemReads();

            await component.receive([picked('front.jpg')], 'photos');
            stale.resolve(page([SAW, PLANE]));
            await reading;

            expect(itemReads()).toBe(readsBefore + 1);
            expect(component.items[0].attachments).toEqual([FRONT]);
            expect(component.rows[0].cells.find((cell) => cell.key === 'photos').kind).toBe('photos');
        });

        it('is read again after a file when a search or filters are in force, since the item can match them differently', async () => {
            await open();
            component.open({ id: 'i2' });
            component.query.q = 'saw';
            http.post.mockResolvedValue({ data: SAW_WITH_FRONT });
            const readsBefore = itemReads();

            await component.receive([picked('front.jpg')], 'photos');

            expect(itemReads()).toBe(readsBefore + 1);
        });

        it('is not read again for each file of a batch, but once when it is over', async () => {
            await open();
            component.open({ id: 'i2' });
            component.query.q = 'saw';
            http.post.mockResolvedValue({ data: SAW_WITH_FRONT });
            const readsBefore = itemReads();

            await component.receive([picked('front.jpg'), picked('side.jpg')], 'photos');

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(itemReads()).toBe(readsBefore + 1);
        });

        it('is read again when the dialog closes after files were added, if nothing read it since', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockResolvedValue({ data: SAW_WITH_FRONT });
            await component.receive([picked('front.jpg')], 'photos');
            const readsBefore = itemReads();

            expect(dismiss(component.$refs.modal)).toBe(true);
            await fadeOut();

            expect(itemReads()).toBe(readsBefore + 1);

            // Once: the list that was read is the one that has the files.
            component.open({ id: 'i3' });
            dismiss(component.$refs.modal);
            await fadeOut();

            expect(itemReads()).toBe(readsBefore + 1);
        });

        it('is not read an extra time when the dialog closes after a save that read it', async () => {
            await open([]);
            component.startCreate();
            component.values.name = 'Chisel';
            await component.receive([picked('front.jpg')], 'photos');
            http.post.mockImplementation(async (url) => ({ data: url === ITEMS ? CREATED : CREATED_WITH_FRONT }));

            await component.save();
            const readsBefore = itemReads();

            await fadeOut();

            expect(itemReads()).toBe(readsBefore);
            expect(component.dialogItem).toBeNull();
        });
    });

    describe('an upload that is cut short', () => {
        it('is given a time that grows with the size of the file, and a minimum', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockResolvedValue({ data: SAW_WITH_FRONT });

            await component.receive([picked('small.jpg', { size: 100 })], 'photos');
            await component.receive([picked('big.pdf', { type: 'application/pdf', size: 20 * 1024 * 1024 })], null);

            expect(http.post.mock.calls[0][2]).toEqual({ timeout: 120_000 });
            expect(http.post.mock.calls[1][2]).toEqual({ timeout: 60_000 + 410 * 1000 });
        });

        it('is looked for on the item when the time ran out, since the server may have stored the file', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(0, 'Too slow', { kind: 'timeout' }));
            http.get.mockImplementation(async (url) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FILE_FIELDS, 2);
                }

                return url === `${ITEMS}/i2` ? { data: SAW_WITH_FRONT } : page([SAW, PLANE]);
            });

            await component.receive([picked('front.jpg')], 'photos');

            expect(http.get).toHaveBeenCalledWith(`${ITEMS}/i2`, { silent: true });
            expect(component.uploads).toEqual([]);
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['front.jpg']);
            expect(component.items[0]).toBe(SAW_WITH_FRONT);
        });

        it('is looked for on the item when the connection broke, and is a failure when the item does not hold it', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(0, 'Offline', { kind: 'network' }));
            http.get.mockImplementation(async (url) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FILE_FIELDS, 2);
                }

                return url === `${ITEMS}/i2` ? { data: SAW } : page([SAW, PLANE]);
            });

            await component.receive([picked('front.jpg')], 'photos');

            expect(component.uploads).toMatchObject([{ state: 'error', message: 'Offline', retryable: true }]);
        });

        it('is a failure when the item cannot be read either', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(0, 'Too slow', { kind: 'timeout' }));
            http.get.mockImplementation(async (url) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FILE_FIELDS, 2);
                }

                if (url === `${ITEMS}/i2`) {
                    throw new HttpError(0, 'Offline', { kind: 'network' });
                }

                return page([SAW, PLANE]);
            });

            await component.receive([picked('front.jpg')], 'photos');

            expect(component.uploads).toMatchObject([{ state: 'error', message: 'Too slow' }]);
        });

        it('does not look for a file the server refused', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(500, 'Server down'));

            await component.receive([picked('front.jpg')], 'photos');

            expect(http.get).not.toHaveBeenCalledWith(`${ITEMS}/i2`, expect.anything());
        });
    });

    describe('a batch of files', () => {
        it('shows every file as waiting from the start, and counts them in the room of the field', async () => {
            await open();
            component.open({ id: 'i2' });
            const first = deferred();

            http.post.mockReturnValueOnce(first.promise).mockResolvedValue({ data: SAW_WITH_FRONT });
            const receiving = component.receive(
                [picked('front.jpg'), picked('side.jpg'), picked('back.jpg')],
                'photos',
            );

            expect(component.uploads.map((upload) => [upload.name, upload.state])).toEqual([
                ['front.jpg', 'sending'],
                ['side.jpg', 'queued'],
                ['back.jpg', 'queued'],
            ]);
            expect(component.uploading).toBe(true);
            expect(component.roomIn(PHOTOS)).toBe(0);
            expect(component.canAddTo(PHOTOS)).toBe(false);

            first.resolve({ data: SAW_WITH_FRONT });
            await receiving;

            expect(component.uploads).toEqual([]);
            expect(component.uploading).toBe(false);
        });

        it('ends when the space is full: the files that follow are not sent, and say why', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'storage_limit_reached' }));

            await component.receive([picked('front.jpg'), picked('side.jpg'), picked('back.jpg')], 'photos');

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.uploads.map((upload) => [upload.state, upload.message, upload.retryable])).toEqual([
                ['error', 'The storage space of this workspace is full.', true],
                ['error', 'The storage space of this workspace is full.', true],
                ['error', 'The storage space of this workspace is full.', true],
            ]);
        });

        it('ends when the item holds all the files it can: the files that follow are not sent', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'attachment_limit_reached', body: { limit: 20 } }),
            );

            await component.receive([picked('notes.txt', { type: 'text/plain' }), picked('more.txt')], null);

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.uploads.map((upload) => upload.message)).toEqual([
                'An item cannot hold more than 20 files.',
                'An item cannot hold more than 20 files.',
            ]);
        });

        it('goes on after a refusal that only concerns the file', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post
                .mockRejectedValueOnce(new HttpError(422, 'Invalid', { errors: { file: ['Too heavy.'] } }))
                .mockResolvedValue({ data: SAW_WITH_FRONT });

            await component.receive([picked('front.jpg'), picked('side.jpg')], 'photos');

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(component.uploads).toMatchObject([{ name: 'front.jpg', state: 'error', message: 'Too heavy.' }]);
        });

        it('keeps the file of a failed upload and sends it again on request', async () => {
            await open();
            component.open({ id: 'i2' });
            const front = picked('front.jpg');

            http.post.mockRejectedValueOnce(new HttpError(500, 'Server down'));
            await component.receive([front], 'photos');

            const [failed] = component.uploads;

            expect(failed).toMatchObject({ state: 'error', retryable: true });
            expect(failed.file).toBe(front);

            http.post.mockResolvedValue({ data: SAW_WITH_FRONT });
            await component.retryUpload(failed);

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(http.post.mock.calls[1][1].get('file')).toBe(front);
            expect(http.post.mock.calls[1][1].get('field')).toBe('photos');
            expect(component.uploads).toEqual([]);
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['front.jpg']);
        });

        it('keeps the row of a file that is sent again where it was, and shows it as sending', async () => {
            await open();
            component.open({ id: 'i2' });
            const again = deferred();

            http.post
                .mockRejectedValueOnce(new HttpError(500, 'Server down'))
                .mockRejectedValueOnce(new HttpError(500, 'Server down'))
                .mockReturnValueOnce(again.promise);
            await component.receive([picked('front.jpg'), picked('side.jpg')], 'photos');
            const retrying = component.retryUpload(component.uploads[0]);

            expect(component.uploads.map((upload) => [upload.name, upload.state])).toEqual([
                ['front.jpg', 'sending'],
                ['side.jpg', 'error'],
            ]);

            again.resolve({ data: SAW_WITH_FRONT });
            await retrying;

            expect(component.uploads.map((upload) => upload.name)).toEqual(['side.jpg']);
        });

        it('offers nothing to send again for a file the browser refused', async () => {
            await open();
            component.open({ id: 'i2' });

            await component.receive([picked('huge.jpg', { size: 21 * 1024 * 1024 })], 'photos');

            expect(component.uploads).toMatchObject([{ state: 'error', retryable: false }]);
            expect(component.uploads[0].file).toBeUndefined();
            expect(http.post).not.toHaveBeenCalled();
        });

        it('does not send again while something is being sent', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValueOnce(new HttpError(500, 'Server down'));
            await component.receive([picked('front.jpg')], 'photos');
            const [failed] = component.uploads;
            component.busy = true;

            await component.retryUpload(failed);

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('refuses to send again into a field that has filled up since', async () => {
            await open([withFiles('i2', { name: 'Saw', photos: ['a1', 'a2'] }, [FRONT, SIDE]), PLANE]);
            component.open({ id: 'i2' });
            http.post.mockRejectedValueOnce(new HttpError(500, 'Server down'));
            await component.receive([picked('back.jpg')], 'photos');
            const [failed] = component.uploads;
            const third = attachment('a3', 'photos', { name: 'other.jpg' });
            component.dialogItem = {
                ...component.dialogItem,
                values: { ...component.dialogItem.values, photos: ['a1', 'a2', 'a3'] },
                attachments: [FRONT, SIDE, third],
            };

            await component.retryUpload(failed);

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.uploads[0].message).toBe('This field cannot hold more files.');
        });
    });

    describe('the live region of the uploads', () => {
        it('says which file is being sent and which one arrived, and is empty again when the dialog is reset', async () => {
            await open();
            component.open({ id: 'i2' });
            const sending = deferred();

            http.post.mockReturnValue(sending.promise);
            const receiving = component.receive([picked('front.jpg')], 'photos');

            expect(component.uploadAnnouncement).toBe('Sending front.jpg…');

            sending.resolve({ data: SAW_WITH_FRONT });
            await receiving;

            expect(component.uploadAnnouncement).toBe('File sent: front.jpg');

            resetFromOutside();

            expect(component.uploadAnnouncement).toBe('');
        });

        it('says nothing about a file that failed: its row says why', async () => {
            await open();
            component.open({ id: 'i2' });
            http.post.mockRejectedValue(new HttpError(500, 'Server down'));

            await component.receive([picked('front.jpg')], 'photos');

            expect(component.uploadAnnouncement).toBe('');
        });
    });
});
