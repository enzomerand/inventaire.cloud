import { afterEach, describe, expect, it } from 'vitest';

import {
    CELL_FILES,
    DEFAULT_MAX_FILE_SIZE,
    PHOTO_TYPES,
    acceptOf,
    attachmentsOfField,
    coverOf,
    fileEntry,
    formatSize,
    isDisplayable,
    isFileField,
    maxFilesOf,
} from '../../../resources/js/fields/files.js';
import { controlOf, describeValue, isFilterable, isSortable, isSummable } from '../../../resources/js/fields/index.js';
import { attachment, field } from '../support/inventory.js';

/** The spaces the formatters use (no-break, narrow no-break) written as plain ones, to compare text. */
const plain = (text) => text.replace(/[\u00a0\u202f]/g, ' ');

afterEach(() => {
    document.documentElement.lang = 'en';
});

describe('the size of a file', () => {
    it('is written with the unit that fits it, in the language of the page', () => {
        expect(plain(formatSize(0))).toBe('0 byte');
        expect(plain(formatSize(512))).toBe('512 byte');
        expect(plain(formatSize(2048))).toBe('2 kB');
        expect(plain(formatSize(1.5 * 1024 * 1024))).toBe('1.5 MB');
        expect(plain(formatSize(20 * 1024 * 1024))).toBe('20 MB');
        expect(plain(formatSize(3 * 1024 ** 3))).toBe('3 GB');

        document.documentElement.lang = 'fr';

        expect(plain(formatSize(1.5 * 1024 * 1024))).toBe('1,5 Mo');
        expect(plain(formatSize(512))).toBe('512 o');
    });

    it('is zero for what is not a size', () => {
        expect(plain(formatSize(Number.NaN))).toBe('0 byte');
        expect(plain(formatSize(-5))).toBe('0 byte');
    });

    it('says the limit of the server, 20 MiB, when the page does not', () => {
        expect(DEFAULT_MAX_FILE_SIZE).toBe(20 * 1024 * 1024);
    });
});

describe('the fields that hold files', () => {
    it('are the photo and the file fields', () => {
        expect(isFileField(field('f', 'photo'))).toBe(true);
        expect(isFileField(field('f', 'file'))).toBe(true);
        expect(isFileField(field('f', 'text'))).toBe(false);
    });

    it('hold the number of files their option says, from 1 to 10', () => {
        expect(maxFilesOf(field('f', 'photo', { max_files: 4 }))).toBe(4);
        expect(maxFilesOf(field('f', 'photo', { max_files: '7' }))).toBe(7);
        expect(maxFilesOf(field('f', 'photo'))).toBe(1);
        expect(maxFilesOf(field('f', 'photo', { max_files: 99 }))).toBe(10);
        expect(maxFilesOf(field('f', 'photo', { max_files: 0 }))).toBe(1);
        expect(maxFilesOf(field('f', 'photo', { max_files: 'many' }))).toBe(1);
    });

    it('offer images only to a photo field, and any file to a file field', () => {
        expect(acceptOf(field('f', 'photo'))).toBe(PHOTO_TYPES.join(','));
        expect(acceptOf(field('f', 'file'))).toBe('');
    });

    it('are filled by uploading, never typed in', () => {
        expect(controlOf(field('f', 'photo'))).toBe('files');
        expect(controlOf(field('f', 'file'))).toBe('files');
    });

    it('are filtered by whether they hold files, but neither sorted nor added up', () => {
        for (const type of ['photo', 'file']) {
            expect(isFilterable(field('f', type))).toBe(true);
            expect(isSortable(field('f', type))).toBe(false);
            expect(isSummable(field('f', type))).toBe(false);
        }
    });
});

describe('a file as the templates show it', () => {
    it('has a name, a size, a thumbnail and an icon', () => {
        const entry = fileEntry(attachment('a1', 'f', { name: 'front.jpg', size: 2048 }));

        expect(entry).toMatchObject({
            id: 'a1',
            name: 'front.jpg',
            mime: 'image/jpeg',
            size: 2048,
            icon: 'image',
            thumb: '/api/v1/attachments/a1/thumbnail',
            download: '/api/v1/attachments/a1',
        });
        expect(plain(entry.sizeText)).toBe('2 kB');
    });

    it('is no link unless it is asked to be one', () => {
        expect(fileEntry(attachment('a1')).href).toBeNull();
        expect(fileEntry(attachment('a1')).target).toBeNull();
    });

    it('opens in a new tab when the browser can display it: an image, a PDF', () => {
        const image = fileEntry(attachment('a1'), true);
        const pdf = fileEntry(attachment('a2', null, { mime: 'application/pdf', has_thumbnail: false }), true);

        expect(image).toMatchObject({ href: '/api/v1/attachments/a1?inline=1', target: '_blank' });
        expect(pdf).toMatchObject({
            href: '/api/v1/attachments/a2?inline=1',
            target: '_blank',
            icon: 'pdf',
            thumb: null,
        });
    });

    it('is downloaded when the browser cannot display it', () => {
        const sheet = fileEntry(
            attachment('a3', null, { mime: 'application/vnd.ms-excel', name: 'stock.xls', has_thumbnail: false }),
            true,
        );

        expect(sheet).toMatchObject({ href: '/api/v1/attachments/a3', target: null, icon: 'file', thumb: null });
    });

    it('has no thumbnail unless the server made one', () => {
        expect(fileEntry(attachment('a1', null, { has_thumbnail: false })).thumb).toBeNull();
        expect(fileEntry(attachment('a1', null, { thumbnail_url: null })).thumb).toBeNull();
    });

    it('knows which types the server lets the browser display', () => {
        expect(isDisplayable('image/png')).toBe(true);
        expect(isDisplayable('application/pdf')).toBe(true);
        expect(isDisplayable('image/svg+xml')).toBe(false);
        expect(isDisplayable('text/html')).toBe(false);
    });

    it('adds the parameter to an address that has a query already', () => {
        const entry = fileEntry(attachment('a1', null, { url: '/download?id=a1' }), true);

        expect(entry.href).toBe('/download?id=a1&inline=1');
    });
});

describe('the files of a field', () => {
    const photos = field('photos', 'photo', { max_files: 3 });
    const files = [attachment('a1', 'photos'), attachment('a2', 'photos'), attachment('a3', 'docs')];

    it('come in the order of the value of the field', () => {
        expect(attachmentsOfField(photos, ['a2', 'a1'], files).map((one) => one.id)).toEqual(['a2', 'a1']);
    });

    it('leave out an id nobody knows, and a file of another field', () => {
        expect(attachmentsOfField(photos, ['a1', 'gone', 'a3'], files).map((one) => one.id)).toEqual(['a1']);
    });

    it('are none when the field holds nothing', () => {
        expect(attachmentsOfField(photos, undefined, files)).toEqual([]);
        expect(attachmentsOfField(photos, null, files)).toEqual([]);
        expect(attachmentsOfField(photos, 'a1', files)).toEqual([]);
    });
});

describe('showing the files of a field', () => {
    const photos = field('photos', 'photo', { max_files: 3 });
    const docs = field('docs', 'file', { max_files: 3 });
    const item = {
        values: { photos: ['a1', 'a2', 'a3'], docs: ['d1', 'd2'] },
        attachments: [
            attachment('a1', 'photos', { name: 'front.jpg' }),
            attachment('a2', 'photos', { name: 'back.jpg' }),
            attachment('a3', 'photos', { name: 'side.jpg' }),
            attachment('d1', 'docs', {
                name: 'invoice.pdf',
                mime: 'application/pdf',
                has_thumbnail: false,
                size: 1024,
            }),
            attachment('d2', 'docs', { name: 'manual.pdf', mime: 'application/pdf', has_thumbnail: false }),
        ],
    };

    it('shows the first photo of a table cell and counts the others', () => {
        const cell = describeValue(photos, item.values.photos, { attachments: item.attachments });

        expect(CELL_FILES).toBe(1);
        expect(cell).toMatchObject({ kind: 'photos', more: 2, text: 'front.jpg, back.jpg, side.jpg' });
        expect(cell.files.map((one) => one.name)).toEqual(['front.jpg']);
        expect(cell.files[0].href).toBeNull();
    });

    it('shows the first name of a table cell and counts the others', () => {
        const cell = describeValue(docs, item.values.docs, { attachments: item.attachments });

        expect(cell).toMatchObject({ kind: 'files', more: 1, text: 'invoice.pdf, manual.pdf' });
        expect(cell.files.map((one) => one.name)).toEqual(['invoice.pdf']);
    });

    it('shows every file, as a link, in the details of an item', () => {
        const cell = describeValue(photos, item.values.photos, { attachments: item.attachments, full: true });

        expect(cell.more).toBe(0);
        expect(cell.files.map((one) => one.name)).toEqual(['front.jpg', 'back.jpg', 'side.jpg']);
        expect(cell.files.every((one) => one.href !== null)).toBe(true);
    });

    it('shows nothing when the field holds nothing, or when the attachments are not known', () => {
        expect(describeValue(photos, undefined, { attachments: item.attachments }).kind).toBe('empty');
        expect(describeValue(photos, [], { attachments: item.attachments }).kind).toBe('empty');
        expect(describeValue(photos, ['a1']).kind).toBe('empty');
        expect(describeValue(photos, ['a1'], { attachments: [] }).kind).toBe('empty');
    });
});

describe('the picture of an item', () => {
    const fields = [field('name', 'text'), field('docs', 'file'), field('photos', 'photo'), field('more', 'photo')];

    it('is the first photo of its first photo field', () => {
        const cover = coverOf(fields, {
            values: { photos: ['a1', 'a2'], more: ['b1'] },
            attachments: [
                attachment('a1', 'photos', { name: 'front.jpg' }),
                attachment('a2', 'photos'),
                attachment('b1', 'more'),
            ],
        });

        expect(cover).toEqual({
            fieldId: 'photos',
            src: '/api/v1/attachments/a1/thumbnail',
            alt: 'front.jpg',
            more: 1,
        });
    });

    it('is taken from the next photo field when the first holds none', () => {
        const cover = coverOf(fields, { values: { more: ['b1'] }, attachments: [attachment('b1', 'more')] });

        expect(cover?.fieldId).toBe('more');
        expect(cover?.more).toBe(0);
    });

    it('is not a file field: only photos make a picture', () => {
        const cover = coverOf(fields, {
            values: { docs: ['d1'] },
            attachments: [attachment('d1', 'docs')],
        });

        expect(cover).toBeNull();
    });

    it('is the first photo that has a thumbnail', () => {
        const cover = coverOf(fields, {
            values: { photos: ['a1', 'a2'] },
            attachments: [attachment('a1', 'photos', { has_thumbnail: false }), attachment('a2', 'photos')],
        });

        expect(cover?.src).toBe('/api/v1/attachments/a2/thumbnail');
    });

    it('is none for an item without photos, or whose photos have no thumbnail', () => {
        expect(coverOf(fields, { values: {} })).toBeNull();
        expect(
            coverOf(fields, {
                values: { photos: ['a1'] },
                attachments: [attachment('a1', 'photos', { has_thumbnail: false })],
            }),
        ).toBeNull();
    });
});
