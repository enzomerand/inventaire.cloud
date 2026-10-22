// @vitest-environment node
/**
 * The real barcode reader: `barcode-detector` and the WebAssembly file of `zxing-wasm`, both from node_modules, run on symbols
 * drawn here (`../support/barcode.js`). The other tests of the scanner replace the reader, so they cannot tell whether what they
 * assume about it is true; this one says what the reader really gives for the codes the application takes, and that `normalizeScan`
 * turns it into the code of the field.
 *
 * The glue of the reader only knows how to fetch its WebAssembly file in a browser: in Node the bytes are handed over (the address
 * that the loader of the application, `resources/js/scanner/decoder.js`, gives for the file is tested in `decoder.test.js`).
 */
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { beforeEach, describe, expect, it, vi } from 'vitest';

import { loadDetector } from '../../../resources/js/scanner/decoder.js';
import { fitsFormat, formatsFor, normalizeScan } from '../../../resources/js/scanner/formats.js';
import { barcodePixels, ean13Modules, ean8Modules } from '../support/barcode.js';

const WASM_FILE = resolve(import.meta.dirname, '../../../node_modules/zxing-wasm/dist/reader/zxing_reader.wasm');

vi.mock('barcode-detector/ponyfill', async (importOriginal) => {
    const real = await importOriginal();
    const { readFileSync: read } = await import('node:fs');
    const { resolve: join } = await import('node:path');
    const wasmBinary = read(
        join(import.meta.dirname, '../../../node_modules/zxing-wasm/dist/reader/zxing_reader.wasm'),
    );

    return {
        ...real,
        // Only the bytes come from the disk.
        prepareZXingModule: (options) =>
            real.prepareZXingModule({ ...options, overrides: { ...options.overrides, wasmBinary } }),
    };
});

/** What the reader finds in the pixels of a symbol, for the symbologies of a field. */
async function read(modules, fieldFormat = null, formats = formatsFor(fieldFormat)) {
    const { data, width, height } = barcodePixels(modules);
    const detector = await loadDetector(formats);
    const found = await detector.detect(new ImageData(data, width, height));

    return found.map(({ rawValue, format }) => ({ rawValue, format }));
}

describe('the real barcode reader', () => {
    beforeEach(() => {
        // Node has no ImageData, no DOMRectReadOnly and, for this test, no network: the reader may use none of the last.
        vi.stubGlobal(
            'ImageData',
            class {
                constructor(data, width, height) {
                    this.data = data;
                    this.width = width;
                    this.height = height;
                    this.colorSpace = 'srgb';
                }
            },
        );
        vi.stubGlobal(
            'DOMRectReadOnly',
            class {
                constructor(x, y, width, height) {
                    Object.assign(this, { x, y, width, height });
                }
            },
        );
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new Error('The reader must not fetch anything in this test.');
            }),
        );
    });

    describe('where it comes from', () => {
        it('has a WebAssembly file that is the one its JavaScript was built with (a glue and a file that differ do not start)', async () => {
            const { ZXING_WASM_SHA256, ZXING_WASM_VERSION } = await import('barcode-detector/ponyfill');
            const sha256 = createHash('sha256').update(readFileSync(WASM_FILE)).digest('hex');
            const installed = JSON.parse(readFileSync(resolve(WASM_FILE, '../../../package.json'), 'utf8')).version;

            expect(sha256).toBe(ZXING_WASM_SHA256);
            expect(installed).toBe(ZXING_WASM_VERSION);
        });
    });

    describe('what it gives for the codes of the fields', () => {
        it('gives the 13 digits and the symbology of an EAN-13', async () => {
            expect(await read(ean13Modules('3017620422003'), 'ean13')).toEqual([
                { rawValue: '3017620422003', format: 'ean_13' },
            ]);
        });

        it('gives the 13 digits of the EAN-13 of a book, and its ISBN-10 is rebuilt from them', async () => {
            const [found] = await read(ean13Modules('9780306406157'), 'isbn10');

            expect(found).toEqual({ rawValue: '9780306406157', format: 'ean_13' });
            expect(normalizeScan(found.rawValue, found.format, 'isbn10')).toBe('0306406152');
            expect(normalizeScan(found.rawValue, found.format, 'isbn13')).toBe('9780306406157');
        });

        // A UPC-A symbol is drawn as the EAN-13 of its digits behind a 0: the reader cannot tell them apart, and gives 13 digits
        // whichever of the two symbologies it was asked for. `normalizeScan` drops the 0 for a UPC-A field.
        it('gives a UPC-A symbol as 13 digits, a 0 and its 12 digits, named ean_13 when it looks for both symbologies', async () => {
            const found = await read(ean13Modules('0012345678905'), 'upca');

            expect(found).toEqual([{ rawValue: '0012345678905', format: 'ean_13' }]);
            expect(normalizeScan(found[0].rawValue, found[0].format, 'upca')).toBe('012345678905');
        });

        it('gives the same 13 digits, named upc_a, when it looks for UPC-A only', async () => {
            const found = await read(ean13Modules('0012345678905'), null, ['upc_a']);

            expect(found).toEqual([{ rawValue: '0012345678905', format: 'upc_a' }]);
            expect(normalizeScan(found[0].rawValue, found[0].format, 'upca')).toBe('012345678905');
        });

        it('gives the 8 digits of an EAN-8', async () => {
            expect(await read(ean8Modules('96385074'), 'ean8')).toEqual([{ rawValue: '96385074', format: 'ean_8' }]);
        });

        it('reads every one of them when it looks for every symbology, as a search does', async () => {
            expect(await read(ean13Modules('3017620422003'))).toEqual([
                { rawValue: '3017620422003', format: 'ean_13' },
            ]);
            expect(await read(ean8Modules('96385074'))).toEqual([{ rawValue: '96385074', format: 'ean_8' }]);
        });

        it('finds nothing of another symbology than the ones asked for', async () => {
            expect(await read(ean13Modules('3017620422003'), 'ean8')).toEqual([]);
            expect(await read(ean8Modules('96385074'), 'ean13')).toEqual([]);
        });

        it('reads a symbol drawn with other proportions: thinner bars, a tall picture, a narrow quiet zone', async () => {
            const modules = ean13Modules('3017620422003');
            const { data, width, height } = barcodePixels(modules, { scale: 2, quiet: 9, height: 120 });
            const detector = await loadDetector(['ean_13']);
            const found = await detector.detect(new ImageData(data, width, height));

            expect(found.map(({ rawValue }) => rawValue)).toEqual(['3017620422003']);
        });
    });

    describe('what the shape of a read says about the field it was read for', () => {
        it('lets the code of the field through and sets aside the barcode of a product that is not a book, a UPC-A or an EAN-8', async () => {
            const [bottle] = await read(ean13Modules('3017620422003'), 'ean13');
            const [book] = await read(ean13Modules('9780306406157'), 'isbn13');
            const [american] = await read(ean13Modules('0012345678905'), 'upca');

            expect(fitsFormat(bottle.rawValue, 'ean13')).toBe(true);
            expect(fitsFormat(bottle.rawValue, 'isbn13')).toBe(false);
            expect(fitsFormat(bottle.rawValue, 'isbn10')).toBe(false);
            expect(fitsFormat(bottle.rawValue, 'upca')).toBe(false);
            expect(fitsFormat(book.rawValue, 'isbn13')).toBe(true);
            expect(fitsFormat(book.rawValue, 'isbn10')).toBe(true);
            expect(fitsFormat(american.rawValue, 'upca')).toBe(true);
        });
    });
});
