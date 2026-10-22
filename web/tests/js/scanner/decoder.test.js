/**
 * The loader of the barcode reader: nothing is fetched until a scan needs it, the WebAssembly file comes from this
 * instance, and the reader is prepared once.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

const reader = vi.hoisted(() => ({
    /** How many times the module of the reader was evaluated, which is when it is fetched. */
    evaluated: 0,
    prepare: vi.fn(),
    detectors: [],
}));

/** Stands for `barcode-detector/ponyfill`: evaluating it is what fetching the reader would do. */
function ponyfill() {
    reader.evaluated += 1;

    class BarcodeDetector {
        constructor(options) {
            this.options = options;
            reader.detectors.push(this);
        }
    }

    return { BarcodeDetector, prepareZXingModule: reader.prepare };
}

/** A fresh copy of the loader, as on a page that has not scanned yet. */
async function freshLoader() {
    vi.resetModules();
    // Registered again each time: a module that is mocked with a factory is evaluated once per registration.
    vi.doMock('barcode-detector/ponyfill', ponyfill);
    vi.doMock('zxing-wasm/reader/zxing_reader.wasm?url', () => ({
        default: '/build/assets/zxing_reader-3f2a9c.wasm',
    }));

    return import('../../../resources/js/scanner/decoder.js');
}

describe('the loader of the barcode reader', () => {
    beforeEach(() => {
        reader.evaluated = 0;
        reader.detectors.length = 0;
        reader.prepare.mockReset();
        reader.prepare.mockResolvedValue({});
    });

    it('fetches nothing until the first scan', async () => {
        const { loadDetector } = await freshLoader();

        expect(reader.evaluated).toBe(0);
        expect(reader.prepare).not.toHaveBeenCalled();

        await loadDetector(['ean_13']);

        expect(reader.evaluated).toBe(1);
        expect(reader.prepare).toHaveBeenCalledTimes(1);
    });

    it('points the reader at the WebAssembly file of this instance, never at a CDN', async () => {
        const { loadDetector } = await freshLoader();

        await loadDetector(['ean_13']);

        const [{ overrides, fireImmediately }] = reader.prepare.mock.calls[0];

        expect(overrides.locateFile('zxing_reader.wasm', 'https://cdn.example/zxing/')).toBe(
            '/build/assets/zxing_reader-3f2a9c.wasm',
        );
        // Whatever else the module asks for is looked for where the module says.
        expect(overrides.locateFile('other.bin', '/prefix/')).toBe('/prefix/other.bin');
        // The module is instantiated while the scanner still says it is loading, so a file that cannot be fetched fails there.
        expect(fireImmediately).toBe(true);
    });

    it('prepares the reader once and builds a detector for the symbologies of each call', async () => {
        const { loadDetector } = await freshLoader();

        const first = await loadDetector(['ean_13']);
        const second = await loadDetector(['qr_code', 'code_128']);

        expect(reader.prepare).toHaveBeenCalledTimes(1);
        expect(reader.evaluated).toBe(1);
        expect(first).not.toBe(second);
        expect(first.options).toEqual({ formats: ['ean_13'] });
        expect(second.options).toEqual({ formats: ['qr_code', 'code_128'] });
    });

    it('prepares the reader once for calls that overlap', async () => {
        const { loadDetector } = await freshLoader();
        let finish;

        reader.prepare.mockReturnValue(new Promise((resolve) => (finish = resolve)));

        const calls = Promise.all([loadDetector(['ean_13']), loadDetector(['ean_8'])]);

        await Promise.resolve();
        finish({});

        expect(await calls).toHaveLength(2);
        expect(reader.prepare).toHaveBeenCalledTimes(1);
    });

    it('fails when the reader cannot be started, and tries again at the next scan', async () => {
        const { loadDetector } = await freshLoader();

        reader.prepare.mockRejectedValueOnce(new Error('network down'));

        await expect(loadDetector(['ean_13'])).rejects.toThrow('network down');
        await expect(loadDetector(['ean_13'])).resolves.toBeDefined();

        expect(reader.prepare).toHaveBeenCalledTimes(2);
    });
});
