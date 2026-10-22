/**
 * Loads the barcode reader the first time a scan needs it.
 *
 * The reader is `barcode-detector` used as a ponyfill (the same code path and the same symbologies on every browser: the
 * native `BarcodeDetector` is missing from Safari and Firefox) on top of ZXing compiled to WebAssembly. Both are imported
 * dynamically, so they are chunks of their own that a page without a scan never fetches, and the `.wasm` file (about 1 MB)
 * comes from this instance (see `wasm-url.js`), never from the CDN the library points to by default.
 */

/**
 * @typedef {object} DetectedBarcode
 * @property {string} rawValue The text of the code.
 * @property {string} format The symbology (`ean_13`, `qr_code`...).
 *
 * @typedef {object} Detector
 * @property {(image: ImageBitmapSource | Blob) => Promise<DetectedBarcode[]>} detect
 */

/**
 * The ponyfill once it is loaded and pointed at our file; null before the first scan, and again after a failure so that
 * the next scan tries again.
 *
 * @type {Promise<typeof import('barcode-detector/ponyfill')> | null}
 */
let ready = null;

/**
 * Fetches the reader and points it at the WebAssembly file of this instance. The module is instantiated right away, so a
 * file that cannot be fetched fails here (while the scanner still says it is loading) and not at the first frame.
 *
 * @returns {Promise<typeof import('barcode-detector/ponyfill')>}
 */
async function prepare() {
    const [ponyfill, wasm] = await Promise.all([import('barcode-detector/ponyfill'), import('./wasm-url.js')]);
    const wasmUrl = wasm.default;

    await ponyfill.prepareZXingModule({
        overrides: {
            locateFile: (/** @type {string} */ path, /** @type {string} */ prefix) =>
                path.endsWith('.wasm') ? wasmUrl : prefix + path,
        },
        fireImmediately: true,
    });

    return ponyfill;
}

/**
 * A detector that looks for some symbologies. The reader is loaded and prepared once: later calls only build a detector.
 *
 * @param {string[]} formats Names of the Barcode Detection API (`ean_13`...), see `formats.js`.
 * @returns {Promise<Detector>}
 * @throws When the reader cannot be fetched or started (offline, blocked file...); the next call tries again.
 */
export async function loadDetector(formats) {
    ready ??= prepare().catch((error) => {
        ready = null;

        throw error;
    });

    const { BarcodeDetector } = await ready;

    return new BarcodeDetector({ formats });
}
