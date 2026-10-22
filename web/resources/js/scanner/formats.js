/**
 * What a scan is asked to look for, and what a code read from a barcode is turned into before it reaches a field.
 *
 * The server stays the only judge of a code (check digit, length, characters: see `BarcodeType`): nothing here mirrors
 * those rules. This only picks the symbologies the reader looks for (fewer symbologies means fewer false reads and a
 * faster pass over each frame) and undoes the ways the same product shows up under another code than the field's.
 */

/** The symbologies a search, and a field that takes any code, look for. */
const ANY_FORMATS = [
    'ean_13',
    'ean_8',
    'upc_a',
    'upc_e',
    'code_128',
    'code_39',
    'code_93',
    'codabar',
    'itf',
    'qr_code',
    'data_matrix',
];

/** The symbologies each format of a barcode field looks for (`BarcodeType::FORMATS`, except `any`). */
const FIELD_FORMATS = {
    ean13: ['ean_13'],
    isbn13: ['ean_13'],
    ean8: ['ean_8'],
    // A UPC-A symbol is read as an EAN-13 with a leading 0 (see `normalizeScan`), unless only UPC-A is asked for.
    upca: ['upc_a', 'ean_13'],
    // The barcode of a book is an EAN-13 that starts with 978 (see `normalizeScan`).
    isbn10: ['ean_13'],
};

/**
 * The symbologies to look for.
 *
 * @param {string | null} [fieldFormat] The `format` option of the barcode field being filled in, or null for a search
 *   (which looks for every symbology a field can hold).
 * @returns {string[]} Names of the Barcode Detection API (`ean_13`, `upc_a`...), a new list each time.
 */
export function formatsFor(fieldFormat = null) {
    return [...(FIELD_FORMATS[fieldFormat ?? ''] ?? ANY_FORMATS)];
}

/**
 * The check digit of an ISBN-10: the first nine digits weigh 10 down to 2, and the check digit completes their sum to a
 * multiple of 11 (`X` stands for 10).
 *
 * @param {string} digits The nine first digits.
 * @returns {string} `0` to `9`, or `X`.
 */
function isbn10CheckDigit(digits) {
    let sum = 0;

    for (let index = 0; index < 9; index++) {
        sum += Number(digits[index]) * (10 - index);
    }

    const check = (11 - (sum % 11)) % 11;

    return check === 10 ? 'X' : String(check);
}

/**
 * Turns what the reader returned into the code the field takes.
 *
 * - A UPC-A field takes the 12 digits of the code, but ZXing gives a UPC-A symbol (and a UPC-E one, expanded) as its
 *   EAN-13 form, a 0 and the 12 digits, whether it names the symbology `ean_13` or `upc_a`: the 0 is dropped.
 * - An ISBN-10 field takes the ten characters printed on the book, but its barcode holds the EAN-13 form (978 and the
 *   first nine digits of the ISBN-10, then a new check digit): the ISBN-10 is rebuilt, with its own check digit. The
 *   979 prefix has no ISBN-10: the EAN-13 is handed over as it is, for the server to refuse.
 * - Anything else is the text of the code, trimmed.
 *
 * @param {string} rawValue What the reader decoded.
 * @param {string} detectedFormat The symbology it found (`ean_13`...).
 * @param {string | null} [fieldFormat] The `format` option of the field, null for a search.
 * @returns {string}
 */
export function normalizeScan(rawValue, detectedFormat, fieldFormat = null) {
    const value = String(rawValue ?? '').trim();

    if (fieldFormat === 'upca' && ['ean_13', 'upc_a', 'upc_e'].includes(detectedFormat) && /^0\d{12}$/.test(value)) {
        return value.slice(1);
    }

    if (fieldFormat === 'isbn10' && detectedFormat === 'ean_13' && /^978\d{10}$/.test(value)) {
        const digits = value.slice(3, 12);

        return digits + isbn10CheckDigit(digits);
    }

    return value;
}

/**
 * The words to search for a code that was scanned. The search finds a word anywhere in the text of an item, so a UPC-A
 * code (which the reader gives with a leading 0, as an EAN-13) is searched as its 12 digits: they are found whether the
 * item holds the UPC-A or the EAN-13 form of the code.
 *
 * @param {string} code The code as the reader gave it.
 * @returns {string}
 */
export function searchTermFor(code) {
    return /^0\d{12}$/.test(code) ? code.slice(1) : code;
}
