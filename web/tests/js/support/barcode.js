/**
 * Draws EAN/UPC barcodes as pixels, for the tests that run the real reader (`tests/js/scanner/decoder.real.test.js`).
 *
 * An EAN-13 symbol is 95 modules: a start guard, six digits (their pattern follows the first digit), a middle guard, six digits
 * and an end guard. An EAN-8 symbol is 67 modules: the guards and two groups of four digits. A UPC-A symbol is drawn exactly as the
 * EAN-13 of the same 12 digits with a 0 in front (the first digit of an EAN-13 is not drawn, only implied by the patterns).
 */

/** The patterns of the digits 0-9 in the set L (left half, odd parity), as modules (1 is a bar). */
const L_CODES = [
    '0001101',
    '0011001',
    '0010011',
    '0111101',
    '0100011',
    '0110001',
    '0101111',
    '0111011',
    '0110111',
    '0001011',
];

/** The set G (left half, even parity) is the set R read from the other end. */
const R_CODES = L_CODES.map((code) => [...code].map((module) => (module === '1' ? '0' : '1')).join(''));
const G_CODES = R_CODES.map((code) => [...code].reverse().join(''));

/** Which set (L or G) each of the six left digits uses, by the first digit of an EAN-13. */
const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

const START_GUARD = '101';
const MIDDLE_GUARD = '01010';
const END_GUARD = '101';

/**
 * The modules of an EAN-13 symbol.
 *
 * @param {string} digits 13 digits.
 * @returns {string} A string of 95 `0` and `1`.
 */
export function ean13Modules(digits) {
    if (!/^\d{13}$/.test(digits)) {
        throw new Error('An EAN-13 is 13 digits.');
    }

    const parity = PARITY[Number(digits[0])];
    const left = [...digits.slice(1, 7)].map(
        (digit, index) => (parity[index] === 'L' ? L_CODES : G_CODES)[Number(digit)],
    );
    const right = [...digits.slice(7)].map((digit) => R_CODES[Number(digit)]);

    return START_GUARD + left.join('') + MIDDLE_GUARD + right.join('') + END_GUARD;
}

/**
 * The modules of an EAN-8 symbol.
 *
 * @param {string} digits 8 digits.
 * @returns {string} A string of 67 `0` and `1`.
 */
export function ean8Modules(digits) {
    if (!/^\d{8}$/.test(digits)) {
        throw new Error('An EAN-8 is 8 digits.');
    }

    const left = [...digits.slice(0, 4)].map((digit) => L_CODES[Number(digit)]);
    const right = [...digits.slice(4)].map((digit) => R_CODES[Number(digit)]);

    return START_GUARD + left.join('') + MIDDLE_GUARD + right.join('') + END_GUARD;
}

/**
 * The pixels of a symbol, black bars on white, with a quiet zone on each side.
 *
 * @param {string} modules A string of `0` and `1`.
 * @param {{ scale?: number, quiet?: number, height?: number }} [options] Pixels per module (3), modules of quiet zone on each side
 *   (10) and height in pixels (90).
 * @returns {{ data: Uint8ClampedArray, width: number, height: number }} RGBA pixels, ready to be an `ImageData`.
 */
export function barcodePixels(modules, { scale = 3, quiet = 10, height = 90 } = {}) {
    const width = (modules.length + 2 * quiet) * scale;
    const data = new Uint8ClampedArray(width * height * 4).fill(255);

    for (let index = 0; index < modules.length; index++) {
        if (modules[index] !== '1') {
            continue;
        }

        for (let row = 0; row < height; row++) {
            for (let column = 0; column < scale; column++) {
                const offset = (row * width + (quiet + index) * scale + column) * 4;

                data[offset] = 0;
                data[offset + 1] = 0;
                data[offset + 2] = 0;
            }
        }
    }

    return { data, width, height };
}
