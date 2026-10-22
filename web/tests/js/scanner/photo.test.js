/**
 * The photo handed to the reader: a picture within 2000 pixels goes as it is, a bigger one is shrunk on a canvas first. jsdom loads no
 * image and draws nothing: the `<img>`, the object URLs and the canvas are replaced.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { MAX_PHOTO_SIDE, prepareImage } from '../../../resources/js/scanner/photo.js';

/** What the last fake `<img>` was asked to load. */
let images;

/** The 2D context the canvases give. */
let context;

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

const photo = new File(['jpeg'], 'photo.jpg', { type: 'image/jpeg' });

describe('the photo handed to the reader', () => {
    beforeEach(() => {
        images = [];
        context = { fillRect: vi.fn(), drawImage: vi.fn(), fillStyle: '' };
        URL.createObjectURL = vi.fn(() => 'blob:photo-1');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => context);
    });

    afterEach(() => {
        delete URL.createObjectURL;
        delete URL.revokeObjectURL;
    });

    it('is the file itself when its longest side is within 2000 pixels', async () => {
        stubImage({ width: 2000, height: 1500 });

        expect(await prepareImage(photo)).toBe(photo);
        expect(HTMLCanvasElement.prototype.getContext).not.toHaveBeenCalled();
    });

    it('is the file itself for a small picture, whichever way it is turned', async () => {
        stubImage({ width: 800, height: 1200 });

        expect(await prepareImage(photo)).toBe(photo);
    });

    it('is a canvas shrunk to 2000 pixels on its longest side when the photo is wider, with the proportions kept', async () => {
        stubImage({ width: 4000, height: 3000 });

        const prepared = await prepareImage(photo);

        expect(prepared).toBeInstanceOf(HTMLCanvasElement);
        expect(prepared.width).toBe(2000);
        expect(prepared.height).toBe(1500);
        expect(context.drawImage).toHaveBeenCalledWith(images[0], 0, 0, 2000, 1500);
    });

    it('is shrunk by its height when the photo is taller (a phone held upright)', async () => {
        stubImage({ width: 3024, height: 4032 });

        const prepared = await prepareImage(photo);

        expect(prepared.height).toBe(MAX_PHOTO_SIDE);
        expect(prepared.width).toBe(1500);
    });

    it('is drawn on a white background, so that a transparent picture is not read as black', async () => {
        stubImage({ width: 5000, height: 2000 });

        await prepareImage(photo);

        expect(context.fillStyle).toBe('#fff');
        expect(context.fillRect).toHaveBeenCalledWith(0, 0, 2000, 800);
        expect(context.fillRect.mock.invocationCallOrder[0]).toBeLessThan(
            context.drawImage.mock.invocationCallOrder[0],
        );
    });

    it('never makes a canvas with a side of 0 pixels', async () => {
        stubImage({ width: 100000, height: 10 });

        const prepared = await prepareImage(photo);

        expect(prepared.width).toBe(2000);
        expect(prepared.height).toBe(1);
    });

    it('loads the picture from an address made for the file, and revokes it once the picture is drawn', async () => {
        stubImage({ width: 4000, height: 3000 });

        await prepareImage(photo);

        expect(URL.createObjectURL).toHaveBeenCalledWith(photo);
        expect(images[0].address).toBe('blob:photo-1');
        expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
    });

    it('revokes the address when no shrinking is needed too', async () => {
        stubImage({ width: 640, height: 480 });

        await prepareImage(photo);

        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
    });

    it('is the file itself when the browser cannot decode it as an image, and still revokes the address', async () => {
        stubImage(new Error('broken'));

        expect(await prepareImage(photo)).toBe(photo);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
    });

    it('is the file itself when the browser cannot make an address for it', async () => {
        stubImage({ width: 4000, height: 3000 });
        URL.createObjectURL = vi.fn(() => {
            throw new TypeError('not supported');
        });

        expect(await prepareImage(photo)).toBe(photo);
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();
    });

    it('is the file itself when no canvas can be drawn on', async () => {
        stubImage({ width: 4000, height: 3000 });
        HTMLCanvasElement.prototype.getContext.mockImplementation(() => null);

        expect(await prepareImage(photo)).toBe(photo);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:photo-1');
    });
});
