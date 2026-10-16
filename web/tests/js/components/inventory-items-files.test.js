/**
 * The files of an item, at the level of the component: the photo and file fields of the dialog, the attachments tab, the
 * uploads (one request per file, after the item exists when it is being added), the removal of a file, and what the table and
 * the gallery show of them.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { HttpError, UPLOAD_TIMEOUT_MS, http } from '../../../resources/js/lib/http.js';
import { hideModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
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
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const PHOTOS = field('photos', 'photo', { max_files: 3 }, { name: 'Photos' });
const DOCS = field('docs', 'file', { max_files: 2 }, { name: 'Documents' });
const NAME = FIELDS[0];

/** The fields of the inventory: a name, a photo field that takes three photos and a file field that takes two files. */
const FILE_FIELDS = [NAME, PHOTOS, DOCS];

const FRONT = attachment('a1', 'photos', { name: 'front.jpg' });
const BACK = attachment('a2', 'photos', { name: 'back.jpg' });
const INVOICE = attachment('d1', 'docs', {
    name: 'invoice.pdf',
    mime: 'application/pdf',
    has_thumbnail: false,
    thumbnail_url: null,
    size: 1048576,
    uploaded_by: { id: 'u1', name: 'Ada' },
});
const NOTES = attachment('n1', null, {
    name: 'notes.txt',
    mime: 'text/plain',
    has_thumbnail: false,
    thumbnail_url: null,
});

/** An item with files, as `ItemResource` describes it. */
function withFiles(id, values, attachments) {
    return { ...item(id, values), attachments };
}

const HAMMER = withFiles('i1', { name: 'Hammer', photos: ['a1', 'a2'], docs: ['d1'] }, [FRONT, BACK, INVOICE, NOTES]);

/** A file the person picked, with the size and the type the test needs (the content does not matter). */
function picked(name, { type = 'image/jpeg', size = 100 } = {}) {
    const one = new File(['x'], name, { type });

    Object.defineProperty(one, 'size', { value: size });

    return one;
}

/** The refusal of the server to a validation, as the HTTP helper reports it. */
function refused(errors) {
    return new HttpError(422, 'The given data was invalid.', { errors });
}

/** The refusal of the server to go past a limit, as the HTTP helper reports it. */
function conflict(code, limit) {
    return new HttpError(409, 'Conflict', { code, body: { limit } });
}

describe('the files of an item', () => {
    let component;

    /** Opens the page with the given items; the dialog is opened by the test. */
    async function open(items = [HAMMER], { fields = FILE_FIELDS, ...options } = {}) {
        component = mountComponent(inventoryItems, options);
        http.get.mockImplementation(async (url) =>
            url === INVENTORY ? inventoryAnswer(fields, items.length) : page(items),
        );
        await component.init();
    }

    /** Opens the dialog on the item of the page. */
    async function openItem(id = 'i1') {
        component.open({ id });
    }

    beforeEach(() => {
        http.post.mockReset();
        http.delete.mockReset();
    });

    describe('in the dialog of an item that exists', () => {
        it('shows the files of each field in the order of its value', async () => {
            await open();
            await openItem();

            expect(component.dialogItem.id).toBe('i1');
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['front.jpg', 'back.jpg']);
            expect(component.filesOf(DOCS).map((one) => one.name)).toEqual(['invoice.pdf']);
            expect(component.filesOf(NAME)).toEqual([]);
        });

        it('links the files: displayed in a new tab when the browser can, downloaded when it cannot', async () => {
            await open();
            await openItem();

            expect(component.filesOf(PHOTOS)[0]).toMatchObject({
                href: '/api/v1/attachments/a1?inline=1',
                target: '_blank',
            });
            expect(component.filesOf(DOCS)[0]).toMatchObject({
                href: '/api/v1/attachments/d1?inline=1',
                target: '_blank',
            });
        });

        it('lists every attachment of the item in the attachments tab, with what is known of it', async () => {
            await open();
            await openItem();

            const rows = component.attachmentRows;

            expect(rows.map((row) => row.name)).toEqual(['front.jpg', 'back.jpg', 'invoice.pdf', 'notes.txt']);
            expect(rows[2]).toMatchObject({ fieldName: 'Documents', addedBy: 'Added by Ada', icon: 'pdf' });
            expect(rows[3]).toMatchObject({ fieldName: '', addedBy: '', icon: 'file', href: '/api/v1/attachments/n1' });
            expect(rows[0].added).not.toBe('');
            expect(component.filesTabLabel).toBe('Attachments (4)');
        });

        it('says when the item holds no attachment', async () => {
            await open([withFiles('i2', { name: 'Saw' }, [])]);
            await openItem('i2');

            expect(component.noAttachments).toBe(true);
            expect(component.filesTabLabel).toBe('Attachments (0)');
        });

        it('says how many files a field has room for, counting the ones on their way up', async () => {
            await open();
            await openItem();

            expect(component.roomIn(PHOTOS)).toBe(1);
            expect(component.roomIn(DOCS)).toBe(1);
            expect(component.countText(PHOTOS)).toBe('2 of 3');
            expect(component.canAddTo(PHOTOS)).toBe(true);

            component.uploads.push({ key: 'k', name: 'x.jpg', field: 'photos', state: 'sending', message: '' });

            expect(component.roomIn(PHOTOS)).toBe(0);
            expect(component.canAddTo(PHOTOS)).toBe(false);
        });

        it('offers no addition to a field that is full, nor while a change is on its way', async () => {
            await open([withFiles('i2', { photos: ['a1', 'a2', 'a3'] }, [FRONT, BACK, attachment('a3', 'photos')])]);
            await openItem('i2');

            expect(component.canAddTo(PHOTOS)).toBe(false);
            expect(component.canAddTo(DOCS)).toBe(true);

            component.busy = true;

            expect(component.canAddTo(DOCS)).toBe(false);
        });

        it('offers the attachments tab to add files while the item can be changed', async () => {
            await open();
            await openItem();

            expect(component.canAttach).toBe(true);

            component.busy = true;

            expect(component.canAttach).toBe(false);
        });

        it('tells when the files of a field are saved: at once for an item that exists', async () => {
            await open();
            await openItem();

            expect(component.fileHint).toBe('Files are saved as soon as they are added or removed.');

            component.startCreate();

            expect(component.fileHint).toBe('Files are sent when the item is saved.');
        });

        it('names the button that adds a file by what the field takes', async () => {
            await open();

            expect(component.addLabel(PHOTOS)).toBe('Add a photo');
            expect(component.addLabel(DOCS)).toBe('Add a file');
            expect(component.removeFileLabel({ name: 'front.jpg' })).toBe('Remove front.jpg');
            expect(component.downloadLabel({ name: 'front.jpg' })).toBe('Download front.jpg');
        });

        it('never sends the files with the values', async () => {
            await open();
            await openItem();
            http.patch.mockResolvedValue({ data: HAMMER });
            component.values.name = 'Claw hammer';
            component.values.photos = ['a1'];

            await component.save();

            expect(http.patch).toHaveBeenCalledWith(`${ITEMS}/i1`, { values: { name: 'Claw hammer' } });
        });

        it('closes without a request when only the files were looked at', async () => {
            await open();
            await openItem();

            await component.save();

            expect(http.patch).not.toHaveBeenCalled();
            expect(hideModal).toHaveBeenCalled();
        });
    });

    describe('choosing files', () => {
        it('opens the file chooser for a field, with the types it takes and room for several when it has some', async () => {
            await open([withFiles('i2', { name: 'Saw' }, [])]);
            await openItem('i2');
            const picker = component.$refs.picker;
            picker.click = vi.fn();

            component.pickFiles('photos');

            expect(picker.accept).toBe('image/jpeg,image/png,image/webp,image/gif');
            expect(picker.multiple).toBe(true);
            expect(picker.click).toHaveBeenCalledTimes(1);
            expect(component.pickedField).toBe('photos');
        });

        it('takes a single file when the field has room for one more', async () => {
            await open();
            await openItem();
            component.$refs.picker.click = vi.fn();

            component.pickFiles('docs');

            expect(component.$refs.picker.accept).toBe('');
            expect(component.$refs.picker.multiple).toBe(false);
        });

        it('opens the file chooser for the item itself, with any type and several files', async () => {
            await open();
            await openItem();
            component.$refs.picker.click = vi.fn();

            component.pickAttachments();

            expect(component.pickedField).toBeNull();
            expect(component.$refs.picker.accept).toBe('');
            expect(component.$refs.picker.multiple).toBe(true);
        });

        it('sends the files that were chosen, for the field the chooser was opened for', async () => {
            await open([withFiles('i2', { name: 'Saw' }, [])]);
            await openItem('i2');
            http.post.mockResolvedValue({ data: withFiles('i2', { name: 'Saw', photos: ['a1'] }, [FRONT]) });
            const picker = component.$refs.picker;
            picker.click = vi.fn();
            component.pickFiles('photos');
            Object.defineProperty(picker, 'files', { value: [picked('front.jpg')], configurable: true });

            await component.onPicked({ target: picker });

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.pickedField).toBeNull();
        });

        it('ignores a chooser that was closed without a file', async () => {
            await open();
            await openItem();
            const picker = component.$refs.picker;
            Object.defineProperty(picker, 'files', { value: [], configurable: true });

            await component.onPicked({ target: picker });

            expect(http.post).not.toHaveBeenCalled();
        });
    });

    describe('sending a file to an item that exists', () => {
        const SAW = withFiles('i2', { name: 'Saw' }, []);
        const SAW_WITH_PHOTO = withFiles('i2', { name: 'Saw', photos: ['a1'] }, [FRONT]);

        it('posts it with its field, with the time an upload needs, and shows the item as the answer describes it', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockResolvedValue({ data: SAW_WITH_PHOTO });
            const front = picked('front.jpg');

            await component.receive([front], 'photos');

            const [url, body, options] = http.post.mock.calls[0];

            expect(url).toBe(`${ITEMS}/i2/attachments`);
            expect(body).toBeInstanceOf(FormData);
            expect(body.get('file')).toBe(front);
            expect(body.get('field')).toBe('photos');
            expect(options).toEqual({ timeout: UPLOAD_TIMEOUT_MS });
            expect(UPLOAD_TIMEOUT_MS).toBe(120_000);
            expect(component.uploads).toEqual([]);
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['front.jpg']);
            expect(component.items[0]).toBe(SAW_WITH_PHOTO);
        });

        it('sends no field for a file attached to the item itself', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockResolvedValue({ data: withFiles('i2', { name: 'Saw' }, [NOTES]) });

            await component.receive([picked('notes.txt', { type: 'text/plain' })], null);

            expect(http.post.mock.calls[0][1].has('field')).toBe(false);
            expect(component.attachmentRows.map((row) => row.name)).toEqual(['notes.txt']);
        });

        it('shows the thumbnail in the table and the picture on the card at once', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockResolvedValue({ data: SAW_WITH_PHOTO });

            await component.receive([picked('front.jpg')], 'photos');

            const photoCell = component.rows[0].cells.find((cell) => cell.key === 'photos');

            expect(photoCell).toMatchObject({ kind: 'photos', more: 0 });
            expect(photoCell.files[0]).toMatchObject({ name: 'front.jpg', thumb: '/api/v1/attachments/a1/thumbnail' });
            expect(component.cards[0].cover).toMatchObject({
                src: '/api/v1/attachments/a1/thumbnail',
                alt: 'front.jpg',
            });
        });

        it('shows the file on its way up with a spinner until the server answers', async () => {
            await open([SAW]);
            await openItem('i2');
            const sending = deferred();
            http.post.mockReturnValue(sending.promise);

            const receiving = component.receive([picked('front.jpg')], 'photos');

            expect(component.uploading).toBe(true);
            expect(component.uploadsOf(PHOTOS)).toMatchObject([{ name: 'front.jpg', state: 'sending', message: '' }]);
            expect(component.canAddTo(PHOTOS)).toBe(false);
            expect(component.canAttach).toBe(false);

            sending.resolve({ data: SAW_WITH_PHOTO });
            await receiving;

            expect(component.uploading).toBe(false);
        });

        it('sends the files one after the other', async () => {
            await open([SAW]);
            await openItem('i2');
            const first = deferred();
            http.post.mockReturnValueOnce(first.promise).mockResolvedValueOnce({ data: SAW_WITH_PHOTO });

            const receiving = component.receive([picked('one.jpg'), picked('two.jpg')], 'photos');
            await Promise.resolve();

            expect(http.post).toHaveBeenCalledTimes(1);

            first.resolve({ data: SAW_WITH_PHOTO });
            await receiving;

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(http.post.mock.calls.map((call) => call[1].get('file').name)).toEqual(['one.jpg', 'two.jpg']);
        });

        it('refuses a file that is too large without sending it, and says how large a file may be', async () => {
            await open([SAW], { maxAttachmentSize: 5 * 1024 * 1024 });
            await openItem('i2');

            await component.receive([picked('huge.jpg', { size: 6 * 1024 * 1024 })], 'photos');

            expect(http.post).not.toHaveBeenCalled();
            expect(component.uploadsOf(PHOTOS)).toMatchObject([
                { name: 'huge.jpg', state: 'error', message: 'This file is larger than 5 MB.' },
            ]);
        });

        it('takes the limit of the server as 20 MB when the page does not say', async () => {
            await open([SAW]);
            await openItem('i2');

            expect(component.maxFileSize).toBe(20 * 1024 * 1024);

            await component.receive([picked('huge.jpg', { size: 21 * 1024 * 1024 })], 'photos');

            expect(component.uploads[0].message).toBe('This file is larger than 20 MB.');
        });

        it('refuses a file that is no image for a photo field, and takes it for a file field', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockResolvedValue({ data: SAW });

            await component.receive([picked('plan.pdf', { type: 'application/pdf' })], 'photos');

            expect(http.post).not.toHaveBeenCalled();
            expect(component.uploads[0].message).toBe('Only JPEG, PNG, WebP and GIF images are accepted.');

            await component.receive([picked('plan.pdf', { type: 'application/pdf' })], 'docs');

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('leaves the judgment to the server when the browser does not know the type of a photo', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockResolvedValue({ data: SAW });

            await component.receive([picked('front', { type: '' })], 'photos');

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('sends as many files as the field has room for and refuses the others', async () => {
            await open();
            await openItem();
            http.post.mockResolvedValue({ data: HAMMER });

            await component.receive([picked('one.jpg'), picked('two.jpg')], 'photos');

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.uploads).toMatchObject([
                { name: 'two.jpg', state: 'error', message: 'This field cannot hold more files.' },
            ]);
        });

        it('keeps the row of a file the server refused, with the reason it gave', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockRejectedValue(refused({ file: ['The file must be an image.'] }));

            await component.receive([picked('front.jpg')], 'photos');

            expect(component.uploadsOf(PHOTOS)).toMatchObject([
                { name: 'front.jpg', state: 'error', message: 'The file must be an image.' },
            ]);
            expect(component.uploading).toBe(false);
            expect(component.canAddTo(PHOTOS)).toBe(true);
        });

        it('says which limit it ran into when the server answers a conflict', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post
                .mockRejectedValueOnce(conflict('attachment_limit_reached', 20))
                .mockRejectedValueOnce(conflict('field_file_limit_reached', 3))
                .mockRejectedValueOnce(conflict('storage_limit_reached', undefined));

            await component.receive([picked('a.jpg')], 'photos');
            await component.receive([picked('b.jpg')], 'photos');
            await component.receive([picked('c.jpg')], 'photos');

            expect(component.uploads.map((upload) => upload.message)).toEqual([
                'An item cannot hold more than 20 files.',
                'This field cannot hold more than 3 files.',
                'The storage space of this workspace is full.',
            ]);
        });

        it('says the general message of a failure that has no word of its own', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockRejectedValueOnce(new HttpError(0, 'Network error.', { kind: 'network' }));
            http.post.mockRejectedValueOnce(new Error('boom'));

            await component.receive([picked('a.jpg')], 'photos');
            await component.receive([picked('b.jpg')], 'photos');

            // The mocked messages know no "error.generic": the key stands in for the sentence.
            expect(component.uploads.map((upload) => upload.message)).toEqual(['Network error.', 'error.generic']);
        });

        it('dismisses the row of a refused file', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockRejectedValue(refused({ file: ['No.'] }));
            await component.receive([picked('front.jpg')], 'photos');

            component.dropUpload(component.uploads[0]);

            expect(component.uploads).toEqual([]);
            expect(component.dismissLabel({ name: 'front.jpg' })).toBe('Dismiss the error for front.jpg');
        });

        it('reads the list again when the item is gone', async () => {
            await open([SAW]);
            await openItem('i2');
            http.post.mockRejectedValue(new HttpError(404, 'This item no longer exists.'));
            http.get.mockClear();

            await component.receive([picked('front.jpg')], 'photos');

            expect(component.uploads[0].message).toBe('This item no longer exists.');
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
        });

        it('shows the item in the table but leaves the dialog alone when it moved on to another item', async () => {
            await open([SAW, HAMMER]);
            await openItem('i2');
            const sending = deferred();
            http.post.mockReturnValue(sending.promise);
            const receiving = component.receive([picked('front.jpg')], 'photos');

            component.begin('edit', component.items[1]);
            sending.resolve({ data: SAW_WITH_PHOTO });
            await receiving;

            expect(component.itemId).toBe('i1');
            expect(component.dialogItem.id).toBe('i1');
            expect(component.items[0]).toBe(SAW_WITH_PHOTO);
            expect(component.uploads).toEqual([]);
        });

        it('tells the failure by a toast when the dialog it would have been shown in is gone', async () => {
            await open([SAW]);
            await openItem('i2');
            const sending = deferred();
            http.post.mockReturnValue(sending.promise);
            const receiving = component.receive([picked('front.jpg')], 'photos');

            component.resetDialog();
            sending.reject(refused({ file: ['Too heavy.'] }));
            await receiving;

            expect(notify).toHaveBeenCalledWith('warning', 'front.jpg could not be sent: Too heavy.');
            expect(component.uploads).toEqual([]);
        });
    });

    describe('choosing files for an item that is being added', () => {
        const CREATED = withFiles('new', { name: 'Saw' }, []);

        /** Opens the dialog that adds an item, with a name typed in. */
        async function startAdding() {
            await open([]);
            component.startCreate();
            component.set(NAME, 'Saw');
        }

        it('keeps them until the item exists, without sending anything', async () => {
            await startAdding();

            await component.receive([picked('front.jpg'), picked('back.jpg')], 'photos');

            expect(http.post).not.toHaveBeenCalled();
            expect(component.stagedOf(PHOTOS).map((one) => one.name)).toEqual(['front.jpg', 'back.jpg']);
            expect(component.stagedOf(PHOTOS)[0]).toMatchObject({ icon: 'image', thumb: null, href: null });
            expect(component.stagedOf(DOCS)).toEqual([]);
            expect(component.roomIn(PHOTOS)).toBe(1);
            expect(component.countText(PHOTOS)).toBe('2 of 3');
        });

        it('shows no tabs, as the item does not exist', async () => {
            await startAdding();

            expect(component.showTabs).toBe(false);
            expect(component.dialogItem).toBeNull();
        });

        it('checks them as it does for an item that exists', async () => {
            await startAdding();

            await component.receive([picked('plan.pdf', { type: 'application/pdf' })], 'photos');
            await component.receive([picked('a.pdf'), picked('b.pdf'), picked('c.pdf')], 'docs');

            expect(component.stagedOf(PHOTOS)).toEqual([]);
            expect(component.stagedOf(DOCS)).toHaveLength(2);
            expect(component.uploads.map((upload) => upload.message)).toEqual([
                'Only JPEG, PNG, WebP and GIF images are accepted.',
                'This field cannot hold more files.',
            ]);
        });

        it('takes one back', async () => {
            await startAdding();
            await component.receive([picked('front.jpg'), picked('back.jpg')], 'photos');

            component.unstage(component.stagedOf(PHOTOS)[0]);

            expect(component.stagedOf(PHOTOS).map((one) => one.name)).toEqual(['back.jpg']);
        });

        it('sends them right after the item was added, with the id of the new item', async () => {
            await startAdding();
            await component.receive([picked('front.jpg')], 'photos');
            await component.receive([picked('invoice.pdf', { type: 'application/pdf' })], 'docs');
            http.post.mockImplementation(async (url) =>
                url === ITEMS ? { data: CREATED } : { data: withFiles('new', { name: 'Saw' }, [FRONT]) },
            );

            await component.save();

            expect(http.post.mock.calls.map((call) => call[0])).toEqual([
                ITEMS,
                `${ITEMS}/new/attachments`,
                `${ITEMS}/new/attachments`,
            ]);
            expect(http.post.mock.calls[0][1]).toEqual({ values: { name: 'Saw' } });
            expect(http.post.mock.calls[1][1].get('field')).toBe('photos');
            expect(http.post.mock.calls[2][1].get('field')).toBe('docs');
            expect(component.staged).toEqual([]);
            expect(hideModal).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('success', 'Item added.');
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
        });

        it('keeps the dialog on the new item while the files go up', async () => {
            await startAdding();
            await component.receive([picked('front.jpg')], 'photos');
            const sending = deferred();
            http.post.mockResolvedValueOnce({ data: CREATED }).mockReturnValueOnce(sending.promise);

            const saving = component.save();
            await vi.waitFor(() => expect(http.post).toHaveBeenCalledTimes(2));

            expect(component.mode).toBe('edit');
            expect(component.itemId).toBe('new');
            expect(component.dialogItem.id).toBe('new');
            expect(component.showTabs).toBe(true);
            expect(component.busy).toBe(true);
            expect(component.uploadsOf(PHOTOS)).toMatchObject([{ name: 'front.jpg', state: 'sending' }]);
            expect(hideModal).not.toHaveBeenCalled();

            sending.resolve({ data: withFiles('new', { name: 'Saw' }, [FRONT]) });
            await saving;

            expect(hideModal).toHaveBeenCalledTimes(1);
            expect(component.busy).toBe(false);
        });

        it('stays open on the new item when a file could not be sent, with the reason beside the file', async () => {
            await startAdding();
            await component.receive([picked('front.jpg'), picked('back.jpg')], 'photos');
            http.post
                .mockResolvedValueOnce({ data: CREATED })
                .mockResolvedValueOnce({ data: withFiles('new', { name: 'Saw', photos: ['a1'] }, [FRONT]) })
                .mockRejectedValueOnce(refused({ file: ['The file must be an image.'] }));

            await component.save();

            expect(hideModal).not.toHaveBeenCalled();
            expect(notify).toHaveBeenCalledWith('warning', 'Item added, but 1 file could not be sent.');
            expect(notify).not.toHaveBeenCalledWith('success', 'Item added.');
            expect(component.mode).toBe('edit');
            expect(component.uploads).toMatchObject([
                { name: 'back.jpg', state: 'error', message: 'The file must be an image.' },
            ]);
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['front.jpg']);
            // The item is in the list meanwhile.
            expect(http.get).toHaveBeenCalledWith(ITEMS, { params: { page: 1 } });
        });

        it('counts the files that failed, in the plural', async () => {
            await startAdding();
            await component.receive([picked('front.jpg'), picked('back.jpg')], 'photos');
            http.post.mockResolvedValueOnce({ data: CREATED }).mockRejectedValue(refused({ file: ['No.'] }));

            await component.save();

            expect(notify).toHaveBeenCalledWith('warning', 'Item added, but 2 files could not be sent.');
        });

        it('keeps the files chosen when the item is refused', async () => {
            await startAdding();
            await component.receive([picked('front.jpg')], 'photos');
            http.post.mockRejectedValue(refused({ 'values.name': ['The name is required.'] }));

            await component.save();

            expect(component.mode).toBe('create');
            expect(component.stagedOf(PHOTOS)).toHaveLength(1);
            expect(http.post).toHaveBeenCalledTimes(1);
            expect(hideModal).not.toHaveBeenCalled();
        });

        it('adds an item without files as it always did', async () => {
            await startAdding();
            http.post.mockResolvedValue({ data: CREATED });

            await component.save();

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(hideModal).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('success', 'Item added.');
        });

        it('forgets the files when the dialog is closed', async () => {
            await startAdding();
            await component.receive([picked('front.jpg')], 'photos');

            component.resetDialog();

            expect(component.staged).toEqual([]);
            expect(component.uploads).toEqual([]);
        });
    });

    describe('removing a file', () => {
        const WITHOUT_FRONT = withFiles('i1', { name: 'Hammer', photos: ['a2'], docs: ['d1'] }, [BACK, INVOICE, NOTES]);

        it('asks first, naming the file, and only then erases it', async () => {
            await open();
            await openItem();

            component.askRemove(component.filesOf(PHOTOS)[0]);

            expect(component.removing).toEqual({ id: 'a1', name: 'front.jpg' });
            expect(component.asking).toBe(true);
            expect(component.isRemoving).toBe(true);
            expect(component.removeText).toBe('Delete the file front.jpg? This cannot be undone.');
            expect(component.showTabs).toBe(false);
            expect(component.canDeleteItem).toBe(false);
            expect(http.delete).not.toHaveBeenCalled();
        });

        it('puts the cursor on the button that confirms', async () => {
            await open();
            await openItem();
            const confirm = document.createElement('button');
            confirm.focus = vi.fn();
            component.$refs.confirmRemove = confirm;

            component.askRemove({ id: 'a1', name: 'front.jpg' });

            expect(confirm.focus).toHaveBeenCalled();
        });

        it('gives up when asked to', async () => {
            await open();
            await openItem();
            component.askRemove({ id: 'a1', name: 'front.jpg' });

            component.cancelRemove();

            expect(component.removing).toBeNull();
            expect(component.asking).toBe(false);
            expect(http.delete).not.toHaveBeenCalled();
        });

        it('deletes the attachment and shows the item as the answer describes it', async () => {
            await open();
            await openItem();
            http.delete.mockResolvedValue({ data: WITHOUT_FRONT });
            component.askRemove({ id: 'a1', name: 'front.jpg' });

            await component.confirmRemove();

            expect(http.delete).toHaveBeenCalledWith(`${ITEMS}/i1/attachments/a1`);
            expect(component.removing).toBeNull();
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['back.jpg']);
            expect(component.items[0]).toBe(WITHOUT_FRONT);
            expect(component.filesTabLabel).toBe('Attachments (3)');
            expect(notify).toHaveBeenCalledWith('success', 'File deleted.');
            expect(component.busy).toBe(false);
            // The dialog stays open: the item may be changed some more.
            expect(hideModal).not.toHaveBeenCalled();
        });

        it('does nothing without a question to answer', async () => {
            await open();
            await openItem();

            await component.confirmRemove();

            expect(http.delete).not.toHaveBeenCalled();
        });

        it('reads the list again when the file is already gone', async () => {
            await open();
            await openItem();
            http.delete.mockRejectedValue(new HttpError(404, 'This item no longer exists.'));
            http.get.mockImplementation(async (url) =>
                url === INVENTORY ? inventoryAnswer(FILE_FIELDS, 1) : page([WITHOUT_FRONT]),
            );
            component.askRemove({ id: 'a1', name: 'front.jpg' });

            await component.confirmRemove();

            expect(component.removing).toBeNull();
            expect(notify).toHaveBeenCalledWith('warning', 'This item no longer exists.');
            expect(component.dialogItem).toBe(component.items[0]);
            expect(component.filesOf(PHOTOS).map((one) => one.name)).toEqual(['back.jpg']);
        });

        it('keeps the file and tells why when the server refuses', async () => {
            await open();
            await openItem();
            http.delete.mockRejectedValue(new HttpError(403, 'You are not allowed to do this.'));
            component.askRemove({ id: 'a1', name: 'front.jpg' });

            await component.confirmRemove();

            expect(notify).toHaveBeenCalledWith('danger', 'You are not allowed to do this.');
            expect(component.removing).toBeNull();
            expect(component.filesOf(PHOTOS)).toHaveLength(2);
            expect(component.busy).toBe(false);
        });

        it('is not offered while a file is being sent', async () => {
            await open();
            await openItem();

            component.uploads.push({ key: 'k', name: 'x.jpg', field: null, state: 'sending', message: '' });

            expect(component.uploading).toBe(true);
            expect(component.canAttach).toBe(false);
        });
    });

    describe('the cursor', () => {
        afterEach(() => {
            document.body.innerHTML = '';
        });

        it('goes back to the button that adds a file when what had it is gone from the dialog', async () => {
            await open();
            await openItem();
            document.body.innerHTML = '<button id="item-field-photos"></button><button id="item-tab-details"></button>';

            component.restoreFocus('photos');

            expect(document.activeElement?.id).toBe('item-field-photos');
        });

        it('goes to the tab that is shown when that button is gone too', async () => {
            await open();
            await openItem();
            document.body.innerHTML =
                '<button id="item-field-photos" style="display: none"></button><button id="item-tab-details"></button>';

            component.restoreFocus('photos');

            expect(document.activeElement?.id).toBe('item-tab-details');
        });

        it('stays where it is while it is still in the dialog', async () => {
            await open();
            await openItem();
            const inside = document.createElement('button');
            component.$refs.modal.append(inside);
            document.body.append(component.$refs.modal);
            inside.focus();
            document.body.insertAdjacentHTML('beforeend', '<button id="item-field-photos"></button>');

            component.restoreFocus('photos');

            expect(document.activeElement).toBe(inside);
        });

        it('is put back after a file chosen for an item that is being added was taken back', async () => {
            await open([]);
            component.startCreate();
            await component.receive([picked('front.jpg')], 'photos');
            document.body.innerHTML = '<button id="item-field-photos"></button>';

            component.unstage(component.stagedOf(PHOTOS)[0]);

            expect(document.activeElement?.id).toBe('item-field-photos');
        });
    });

    describe('what a person who may not edit sees', () => {
        it('is the details of the item and its attachments, with nothing to add', async () => {
            await open([HAMMER], { canEdit: false });
            await openItem();

            expect(component.mode).toBe('view');
            expect(component.showTabs).toBe(true);
            expect(component.canAttach).toBe(false);
            expect(component.attachmentRows).toHaveLength(4);
            expect(component.details.find((cell) => cell.key === 'photos')).toMatchObject({ kind: 'photos', more: 0 });
            expect(component.details.find((cell) => cell.key === 'photos').files.map((one) => one.name)).toEqual([
                'front.jpg',
                'back.jpg',
            ]);
        });
    });

    describe('in the table and the gallery', () => {
        it('shows the first photo of the cell with the number of the others, and the first file name', async () => {
            await open();

            const [row] = component.rows;
            const photos = row.cells.find((cell) => cell.key === 'photos');
            const docs = row.cells.find((cell) => cell.key === 'docs');

            expect(photos).toMatchObject({ kind: 'photos', more: 1, plain: true });
            expect(photos.files.map((one) => one.name)).toEqual(['front.jpg']);
            expect(docs).toMatchObject({ kind: 'files', more: 0 });
            expect(docs.files.map((one) => one.name)).toEqual(['invoice.pdf']);
        });

        it('opens the dialog, on the field, when a cell of files is pressed: files are not edited in place', async () => {
            await open();
            const [row] = component.rows;
            const cell = row.cells.find((one) => one.key === 'photos');

            await component.startInline(row, cell);

            expect(component.inlineRow).toBeNull();
            expect(component.mode).toBe('edit');
            expect(component.itemId).toBe('i1');
        });

        it('describes the cell of an item without files as nothing', async () => {
            await open([item('i3', { name: 'Saw' })]);

            expect(component.rows[0].cells.find((cell) => cell.key === 'photos').kind).toBe('empty');
        });

        it('puts the first photo of the first photo field on the card, which then leaves that field out', async () => {
            await open();

            const [card] = component.cards;

            expect(card.cover).toEqual({ src: '/api/v1/attachments/a1/thumbnail', alt: 'front.jpg', more: '+1' });
            expect(card.cells.map((cell) => cell.key)).toEqual(['docs']);
        });

        it('has no picture for an item without a photo, and keeps its file field on the card', async () => {
            await open([withFiles('i4', { name: 'Saw', docs: ['d1'] }, [INVOICE])]);

            const [card] = component.cards;

            expect(card.cover).toBeNull();
            expect(card.cells.map((cell) => cell.key)).toEqual(['docs']);
        });

        it('does not sort by a field of files', async () => {
            await open();

            expect(component.isSortable(PHOTOS)).toBe(false);
        });
    });
});
