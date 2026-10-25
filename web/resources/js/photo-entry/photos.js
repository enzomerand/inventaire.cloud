/**
 * The photos of the photo entry: which files are taken, and how each one is got ready to be sent for the analysis.
 *
 * A phone takes pictures of 12 megapixels and more, which the analysis does not need (it reads at most 1568 pixels on the longest
 * side) and which would only make the upload slow. So every photo is redrawn on a canvas and sent as a JPEG of at most
 * {@link ANALYSIS_SIDE} pixels. That also turns what the browser can decode but the server cannot (a HEIC photo on Safari) into a
 * JPEG, and applies the orientation the camera recorded. The originals stay in the browser: they are the ones that end up in the
 * photo field of the item.
 */
import { drawScaled, loadImage } from '../lib/image.js';

/** The longest side, in pixels, of a photo sent for the analysis. */
export const ANALYSIS_SIDE = 1568;

/** The quality of the JPEG sent for the analysis (0 to 1). */
export const ANALYSIS_QUALITY = 0.85;

/** How many photos an analysis takes when the page does not say (`data-photo-entry-max`). */
export const DEFAULT_MAX_PHOTOS = 5;

/** Thrown for a photo the browser cannot decode or redraw. */
export class UnreadablePhoto extends Error {
    /**
     * @param {string} name The name of the file.
     */
    constructor(name) {
        super(`The photo ${name} could not be read.`);
        this.name = 'UnreadablePhoto';
        this.fileName = name;
    }
}

/**
 * Whether a file the person chose may be a photo: its type says it is an image, or says nothing (some browsers give no type to a
 * file that comes from a camera roll, and the redrawing decides).
 *
 * @param {File} file
 * @returns {boolean}
 */
export function looksLikeImage(file) {
    return file.type === '' || file.type.startsWith('image/');
}

/**
 * The name a photo gets once it is a JPEG: its own, with the extension of the new format.
 *
 * @param {string} name
 * @returns {string}
 */
export function jpegName(name) {
    const base = name.replace(/\.[^./\\]*$/, '');

    return (base === '' ? 'photo' : base) + '.jpg';
}

/**
 * Waits for a canvas to be turned into an image file.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {string} type
 * @param {number} quality
 * @returns {Promise<Blob | null>} Null when the browser cannot encode the canvas.
 */
function encode(canvas, type, quality) {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * Gets a photo ready for the analysis: a JPEG of at most {@link ANALYSIS_SIDE} pixels on its longest side, same proportions, on a
 * white background where the photo is transparent. The address made for the file is revoked, and the canvas let go of (a phone
 * keeps the memory of a canvas until it is told it is not needed), once the photo has been drawn.
 *
 * @param {File} file The photo the person took or picked.
 * @returns {Promise<File>} A new file; the one given is left as it is.
 * @throws {UnreadablePhoto} When the browser cannot decode the file as an image, or cannot redraw it.
 */
export async function prepareAnalysisPhoto(file) {
    let url = null;
    let canvas = null;

    try {
        url = URL.createObjectURL(file);

        const image = await loadImage(url);

        canvas = drawScaled(image, ANALYSIS_SIDE);

        const blob = canvas === null ? null : await encode(canvas, 'image/jpeg', ANALYSIS_QUALITY);

        if (blob === null) {
            throw new Error('The photo could not be encoded.');
        }

        return new File([blob], jpegName(file.name), { type: 'image/jpeg' });
    } catch {
        throw new UnreadablePhoto(file.name);
    } finally {
        if (url !== null) {
            URL.revokeObjectURL(url);
        }

        if (canvas !== null) {
            canvas.width = 0;
            canvas.height = 0;
        }
    }
}
