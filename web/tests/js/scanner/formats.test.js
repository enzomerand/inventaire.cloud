/**
 * What a scan looks for, which reads can be the code of a field, and what a code that was read is turned into before it reaches a field.
 */
import { describe, expect, it } from 'vitest';

import { fitsFormat, formatsFor, normalizeScan, searchTermFor } from '../../../resources/js/scanner/formats.js';

/** The symbologies of a search and of a field that takes any code. */
const EVERYTHING = [
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

describe('the symbologies a scan looks for', () => {
    it('looks for every symbology in a search and in a field that takes any code', () => {
        expect(formatsFor(null)).toEqual(EVERYTHING);
        expect(formatsFor()).toEqual(EVERYTHING);
        expect(formatsFor('any')).toEqual(EVERYTHING);
    });

    it.each([
        ['ean13', ['ean_13']],
        ['isbn13', ['ean_13']],
        ['ean8', ['ean_8']],
        ['isbn10', ['ean_13']],
    ])('looks only for %s in a field limited to that format', (format, expected) => {
        expect(formatsFor(format)).toEqual(expected);
    });

    it('looks for UPC-A, and for the EAN-13 a reader may take it for, in a UPC-A field', () => {
        expect(formatsFor('upca')).toEqual(['upc_a', 'ean_13']);
    });

    it('falls back to every symbology for a format it does not know (a newer server)', () => {
        expect(formatsFor('gs1')).toEqual(EVERYTHING);
    });

    it('hands over a list of its own each time, which a caller may change', () => {
        formatsFor('ean13').push('qr_code');
        formatsFor(null).pop();

        expect(formatsFor('ean13')).toEqual(['ean_13']);
        expect(formatsFor(null)).toEqual(EVERYTHING);
    });
});

describe('the code a field takes', () => {
    it('is the text that was read, trimmed, when the field has nothing to undo', () => {
        expect(normalizeScan('  3017620422003 \n', 'ean_13', 'ean13')).toBe('3017620422003');
        expect(normalizeScan('96385074', 'ean_8', 'ean8')).toBe('96385074');
        expect(normalizeScan('ABC-123', 'code_128', 'any')).toBe('ABC-123');
        expect(normalizeScan('https://example.com/x', 'qr_code', null)).toBe('https://example.com/x');
        expect(normalizeScan('9780306406157', 'ean_13', 'isbn13')).toBe('9780306406157');
        expect(normalizeScan('0012345678905', 'ean_13', null)).toBe('0012345678905');
    });

    it('is an empty text when nothing was read', () => {
        expect(normalizeScan(undefined, 'ean_13', 'upca')).toBe('');
        expect(normalizeScan(null, 'ean_13', null)).toBe('');
    });

    describe('in a UPC-A field', () => {
        it('drops the leading 0 of an EAN-13, which is how a reader may report a UPC-A symbol', () => {
            expect(normalizeScan('0012345678905', 'ean_13', 'upca')).toBe('012345678905');
        });

        it('keeps an EAN-13 that does not start with 0 (the server refuses it)', () => {
            expect(normalizeScan('3017620422003', 'ean_13', 'upca')).toBe('3017620422003');
        });

        // What ZXing really does: a UPC-A symbol is given as 13 digits, whether it is named ean_13 or upc_a (probed with
        // the real reader on generated symbols), and a UPC-E symbol as its expansion.
        it('drops the leading 0 of a UPC-A symbol that the reader names upc_a or upc_e, which it gives in 13 digits too', () => {
            expect(normalizeScan('0012345678905', 'upc_a', 'upca')).toBe('012345678905');
            expect(normalizeScan('0012345000065', 'upc_e', 'upca')).toBe('012345000065');
        });

        it('keeps a code that is already 12 digits', () => {
            expect(normalizeScan('012345678905', 'upc_a', 'upca')).toBe('012345678905');
        });

        it('leaves another symbology alone, even a 13-digit one', () => {
            expect(normalizeScan('0012345678905', 'code_128', 'upca')).toBe('0012345678905');
        });
    });

    describe('in an ISBN-10 field', () => {
        // The ISBN-10 of a book is its EAN-13 without the 978 and without the check digit, plus a check digit of its own.
        it.each([
            ['9780306406157', '0306406152'],
            ['9780131103627', '0131103628'],
            ['9780596517748', '0596517742'],
            // The check digit is 10, which is written X.
            ['9780804429573', '080442957X'],
            // The check digit is 0.
            ['9780000000002', '0000000000'],
        ])('rebuilds the ISBN-10 of %s, check digit included', (ean, isbn) => {
            expect(normalizeScan(ean, 'ean_13', 'isbn10')).toBe(isbn);
        });

        it('keeps an EAN-13 that starts with 979, which has no ISBN-10 (the server refuses it)', () => {
            expect(normalizeScan('9791032305690', 'ean_13', 'isbn10')).toBe('9791032305690');
        });

        it('keeps an EAN-13 that is not a book', () => {
            expect(normalizeScan('3017620422003', 'ean_13', 'isbn10')).toBe('3017620422003');
        });

        it('leaves another symbology alone', () => {
            expect(normalizeScan('9780306406157', 'code_128', 'isbn10')).toBe('9780306406157');
        });
    });
});

describe('the reads a field can take', () => {
    it('takes every read when the field takes any code, in a search, and for a format it does not know', () => {
        for (const format of [null, undefined, 'any', 'gs1', 'constructor']) {
            expect(fitsFormat('ABC-123', format)).toBe(true);
            expect(fitsFormat('3017620422003', format)).toBe(true);
            expect(fitsFormat('', format)).toBe(true);
        }
    });

    it.each([
        [
            'an EAN-13 field',
            'ean13',
            ['3017620422003', '9780306406157', '0012345678905'],
            ['012345678905', '96385074', '30176204220031', 'A017620422003', ''],
        ],
        ['an EAN-8 field', 'ean8', ['96385074', '00000000'], ['3017620422003', '9638507', '963850741', '9638507A', '']],
        [
            'an ISBN-13 field',
            'isbn13',
            ['9780306406157', '9791032305690'],
            ['3017620422003', '0012345678905', '978030640615', ''],
        ],
        [
            'an ISBN-10 field',
            'isbn10',
            ['9780306406157'],
            ['9791032305690', '3017620422003', '0306406152', '978030640615', ''],
        ],
    ])('takes the reads that have the shape of the code of %s, and only those', (_name, format, fitting, others) => {
        for (const read of fitting) {
            expect(fitsFormat(read, format), read).toBe(true);
        }

        for (const read of others) {
            expect(fitsFormat(read, format), read).toBe(false);
        }
    });

    it('takes, in a UPC-A field, the 12 digits of the code and its EAN-13 form (a 0 and the 12 digits)', () => {
        expect(fitsFormat('012345678905', 'upca')).toBe(true);
        expect(fitsFormat('0012345678905', 'upca')).toBe(true);
        expect(fitsFormat('3017620422003', 'upca')).toBe(false);
        expect(fitsFormat('9780306406157', 'upca')).toBe(false);
        expect(fitsFormat('96385074', 'upca')).toBe(false);
        expect(fitsFormat('01234567890', 'upca')).toBe(false);
    });

    it('looks at the read without its spaces, as the field will take it', () => {
        expect(fitsFormat('  3017620422003 \n', 'ean13')).toBe(true);
        expect(fitsFormat(' 3017620422003 ', 'isbn13')).toBe(false);
        expect(fitsFormat(undefined, 'ean13')).toBe(false);
        expect(fitsFormat(null, 'ean8')).toBe(false);
    });
});

describe('the words a scanned code is searched for', () => {
    it('are the 12 digits of a UPC-A code, which the reader gives in 13: they are found in the UPC-A and in the EAN-13 form', () => {
        expect(searchTermFor('0012345678905')).toBe('012345678905');
    });

    it('are the 9 digits that the EAN-13 of a book shares with its ISBN-10, found in the ISBN-13 and in the ISBN-10', () => {
        // 978 + 030640615 + check digit 7 is the ISBN-13 of 0306406152.
        expect(searchTermFor('9780306406157')).toBe('030640615');
        expect(searchTermFor('9780804429573')).toBe('080442957');
        expect(searchTermFor('9780000000002')).toBe('000000000');
    });

    it('are really found in both forms of the code of a book, whatever the way the item holds it', () => {
        const core = searchTermFor('9780306406157');

        expect('9780306406157').toContain(core);
        expect('0306406152').toContain(core);
        // An ISBN-10 that ends with X, or a code in capitals: the digits do not change with the case.
        expect('080442957x').toContain(searchTermFor('9780804429573'));
    });

    it('are the code itself otherwise', () => {
        expect(searchTermFor('3017620422003')).toBe('3017620422003');
        // 979 has no ISBN-10: whole.
        expect(searchTermFor('9791032305690')).toBe('9791032305690');
        expect(searchTermFor('96385074')).toBe('96385074');
        expect(searchTermFor('012345678905')).toBe('012345678905');
        expect(searchTermFor('00123456789051')).toBe('00123456789051');
        expect(searchTermFor('97803064061571')).toBe('97803064061571');
        expect(searchTermFor('978030640615')).toBe('978030640615');
        expect(searchTermFor('978030640615A')).toBe('978030640615A');
        expect(searchTermFor('0ABC123456789')).toBe('0ABC123456789');
        expect(searchTermFor('')).toBe('');
    });
});
