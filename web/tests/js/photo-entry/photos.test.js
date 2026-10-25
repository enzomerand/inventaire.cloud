/**
 * The photos of the photo entry: which files are taken, and how a photo is got ready for the analysis (redrawn as a JPEG of at most
 * 1568 pixels). jsdom loads no image and draws nothing: the `<img>`, the object URLs and the canvas are replaced.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    ANALYSIS_QUALITY,
    ANALYSIS_SIDE,
    UnreadablePhoto,
    jpegName,
    looksLikeImage,
    prepareAnalysisPhoto,
} from '../../../resources/js/photo-entry/photos.js';

/** What the last fake `<img>` was asked to load. */
let images;

/** The 2D context the canvases give. */
let context;

/** What the canvases encode to: a blob, or null for a browser that cannot encode. */
let encoded;

/**
 * Stands for `Image`: setting `src` makes it load (or fail) on the next tick, with the size the test chose.
 *
 * @param {{ width: number, height: number } | Error} outcome
 */
function stubImage(outcome) {
    class FakeImage {
        constructor() {
            this.onload = null;
            this.onerror = null;
            images.push(this);
        }

        set src(value) {
            this.address = value;
            queueMicrotask(() => {
                if (outcome instanceof Error) {
                    this.onerror?.(new Event('error'));
                } else {
                    this.naturalWidth = outcome.width;
                    this.naturalHeight = outcome.height;
                    this.onload?.(new Event('load'));
                }
            });
        }
    }

    vi.stubGlobal('Image', FakeImage);
}

const photo = (name = 'IMG_0001.HEIC', type = 'image/heic') => new File(['heic'], name, { type });

describe('the photos of the photo entry', () => {
    beforeEach(() => {
        images = [];
        context = { fillRect: vi.fn(), drawImage: vi.fn(), fillStyle: '' };
        encoded = new Blob(['jpeg'], { type: 'image/jpeg' });
        URL.createObjectURL = vi.fn(() => 'blob:photo-1');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => context);
        vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(encoded));
    });

    afterEach(() => {
        delete URL.createObjectURL;
        delete URL.revokeObjectURL;
    });

    describe('which files are taken', () => {
        it.each([
            ['photo.jpg', 'image/jpeg', true],
            ['photo.png', 'image/png', true],
            ['IMG_0001.HEIC', 'image/heic', true],
            ['camera-roll', '', true],
            ['manual.pdf', 'application/pdf', false],
            ['notes.txt', 'text/plain', false],
        ])('%s (%s): %s', (name, type, expected) => {
            expect(looksLikeImage(photo(name, type))).toBe(expected);
        });
    });

    describe('the name of a photo once it is a JPEG', () => {
        it.each([
            ['IMG_0001.HEIC', 'IMG_0001.jpg'],
            ['front.jpeg', 'front.jpg'],
            ['front.jpg', 'front.jpg'],
            ['a.b.c.png', 'a.b.c.jpg'],
            ['no-extension', 'no-extension.jpg'],
            ['.hidden', 'photo.jpg'],
            ['', 'photo.jpg'],
        ])('%s becomes %s', (name, expected) => {
            expect(jpegName(name)).toBe(expected);
        });
    });

    describe('getting a photo ready for the analysis', () => {
        it('is a JPEG file of its own, named after the photo, and the photo is left as it is', async () => {
            stubImage({ width: 1000, height: 800 });

            const original = photo();
            const prepared = await prepareAnalysisPhoto(original);

            expect(prepared).toBeInstanceOf(File);
            expect(prepared).not.toBe(original);
            expect(prepared.name).toBe('IMG_0001.jpg');
            expect(prepared.type).toBe('image/jpeg');
            expect(original.type).toBe('image/heic');
            // A photo within the limit is redrawn at its own size, never enlarged.
            expect(context.drawImage).toHaveBeenCalledWith(images[0], 0, 0, 1000, 800);
        });

        it('is shrunk to 1568 pixels on its longest side, with the proportions kept', async () => {
            stubImage({ width: 4032, height: 3024 });

            await prepareAnalysisPhoto(photo());

            expect(ANALYSIS_SIDE).toBe(1568);
            expect(context.drawImage).toHaveBeenCalledWith(images[0], 0, 0, 1568, 1176);
        });

        it('is encoded as a JPEG of quality 0.85', async () => {
            stubImage({ width: 640, height: 480 });

            const canvases = [];

            HTMLCanvasElement.prototype.toBlob.mockImplementation(function (callback, type, quality) {
                canvases.push([type, quality]);
                callback(encoded);
            });
            await prepareAnalysisPhoto(photo());

            expect(ANALYSIS_QUALITY).toBe(0.85);
            expect(canvases).toEqual([['image/jpeg', 0.85]]);
        });

        it('revokes the address made for the file, and lets go of the canvas', async () => {
            stubImage({ width: 800, height: 600 });

            const canvases = [];

            HTMLCanvasElement.prototype.toBlob.mockImplementation(function (callback) {
                canvases.push(this);
                callback(encoded);
            });
            await prepareAnalysisPhoto(photo());

            expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
            expect(images[0].address).toBe('blob:photo-1');
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
            expect(canvases[0].width).toBe(0);
            expect(canvases[0].height).toBe(0);
        });

        it('fails when the browser cannot decode the photo, still revoking the address', async () => {
            stubImage(new Error('broken'));

            await expect(prepareAnalysisPhoto(photo('front.heic'))).rejects.toMatchObject({
                name: 'UnreadablePhoto',
                fileName: 'front.heic',
            });
            await expect(prepareAnalysisPhoto(photo())).rejects.toBeInstanceOf(UnreadablePhoto);
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
        });

        it('fails when the browser cannot make an address for the file', async () => {
            stubImage({ width: 800, height: 600 });
            URL.createObjectURL = vi.fn(() => {
                throw new TypeError('not supported');
            });

            await expect(prepareAnalysisPhoto(photo())).rejects.toBeInstanceOf(UnreadablePhoto);
            expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        });

        it('fails when no canvas can be drawn on', async () => {
            stubImage({ width: 800, height: 600 });
            HTMLCanvasElement.prototype.getContext.mockImplementation(() => null);

            await expect(prepareAnalysisPhoto(photo())).rejects.toBeInstanceOf(UnreadablePhoto);
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
        });

        it('fails when the browser cannot encode the canvas', async () => {
            stubImage({ width: 800, height: 600 });
            encoded = null;

            await expect(prepareAnalysisPhoto(photo())).rejects.toBeInstanceOf(UnreadablePhoto);
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
        });
    });
});
