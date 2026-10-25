/**
 * Gets the photo of a barcode ready for the reader.
 *
 * A phone takes pictures of 12 megapixels and more. The reader has to turn such a picture into pixels first, which is slow, and
 * which some browsers refuse (iOS Safari limits the size of a canvas, and the memory of a small Android phone is not made for a
 * 50 megapixel photo). A barcode is read as well from a picture of 2000 pixels on its longest side, so a bigger one is shrunk first.
 */
import { drawScaled, loadImage } from '../lib/image.js';

/** The longest side, in pixels, of the picture the reader gets. */
export const MAX_PHOTO_SIDE = 2000;

/**
 * The photo as the reader should get it: the file itself when its longest side is within {@link MAX_PHOTO_SIDE}, and a canvas with
 * the photo shrunk to that side (same proportions, a white background under what is transparent) when it is longer.
 *
 * This never fails: a file the browser cannot decode into an `<img>` (or a canvas that cannot be made) is handed over as it is, for
 * the reader to say what it makes of it. The address made for the file is revoked once the picture has been drawn.
 *
 * @param {File} file The photo the person took or picked.
 * @returns {Promise<File | HTMLCanvasElement>}
 */
export async function prepareImage(file) {
    let url = null;

    try {
        url = URL.createObjectURL(file);

        const image = await loadImage(url);
        const longest = Math.max(image.naturalWidth, image.naturalHeight);

        if (!(longest > MAX_PHOTO_SIDE)) {
            return file;
        }

        return drawScaled(image, MAX_PHOTO_SIDE) ?? file;
    } catch {
        return file;
    } finally {
        if (url !== null) {
            URL.revokeObjectURL(url);
        }
    }
}
