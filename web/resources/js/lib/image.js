/**
 * Decoding a picture the person took or picked, and drawing it smaller: what the barcode reader (`../scanner/photo.js`) and the
 * photo entry (`../photo-entry/photos.js`) both need before a photo of 12 megapixels is handed on.
 */

/**
 * Loads a picture in an `<img>`, which is what applies the orientation the camera recorded (EXIF): the width and the height of the
 * element, and what it draws, are those of the picture as a person sees it.
 *
 * @param {string} url
 * @returns {Promise<HTMLImageElement>} Rejects when the browser cannot decode the file as an image.
 */
export function loadImage(url) {
    return new Promise((resolve, reject) => {
        const image = new Image();

        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('The file is not an image the browser can decode.'));
        image.src = url;
    });
}

/**
 * Draws a picture on a canvas, shrunk so that its longest side is at most `maxSide` pixels (never enlarged): same proportions, a
 * white background under what is transparent (a transparent pixel would otherwise be read as black), and no side below 1 pixel.
 *
 * @param {HTMLImageElement} image A picture that has loaded.
 * @param {number} maxSide The longest side, in pixels, the canvas may have.
 * @returns {HTMLCanvasElement | null} Null when the browser gives no 2D context to draw with.
 */
export function drawScaled(image, maxSide) {
    const longest = Math.max(image.naturalWidth, image.naturalHeight);
    const scale = longest > maxSide ? maxSide / longest : 1;
    const canvas = document.createElement('canvas');

    canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
    canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));

    const context = canvas.getContext('2d');

    if (context === null) {
        return null;
    }

    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);

    return canvas;
}
