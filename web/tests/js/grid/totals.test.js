import { beforeEach, describe, expect, it, vi } from 'vitest';

import { hasTotals, totalCells } from '../../../resources/js/grid/totals.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

/** A field of an inventory. */
function field(id, type, options = {}) {
    return { id, name: 'Field ' + id, type, options, required: false, position: 0 };
}

const FIELDS = [
    field('name', 'text'),
    field('weight', 'number', { decimals: 2, unit: 'kg' }),
    field('price', 'money', { currency: 'EUR' }),
    field('discount', 'percentage', { decimals: 1 }),
    field('grade', 'rating', { max: 5 }),
];

describe('the totals of the columns', () => {
    beforeEach(() => {
        document.documentElement.lang = 'en';
    });

    it('formats each total like the values of its column: a unit after a number, a currency on an amount', () => {
        const cells = totalCells(FIELDS, { weight: '12.5', price: '1234.5' });

        expect(cells.map((cell) => cell.text)).toEqual(['', '12.50 kg', '€1,234.50', '', '']);
    });

    it('shows a total of zero, which is a sum like another', () => {
        const cells = totalCells(FIELDS, { weight: '0.00', price: '0.00' });

        expect(cells[1].text).toBe('0.00 kg');
        expect(cells[2].text).toBe('€0.00');
    });

    it('leaves a column that does not add up empty, even when the server sends a number for it', () => {
        const cells = totalCells(FIELDS, { discount: '5.0', grade: '12', name: '3' });

        expect(cells.map((cell) => cell.text)).toEqual(['', '', '', '', '']);
    });

    it('marks the first column, which carries the word "Total"', () => {
        expect(totalCells(FIELDS, {}).map((cell) => cell.first)).toEqual([true, false, false, false, false]);
        expect(totalCells(FIELDS, {}).map((cell) => cell.key)).toEqual(FIELDS.map((one) => one.id));
    });

    it('says whether any column has a total to show', () => {
        expect(hasTotals(totalCells(FIELDS, {}))).toBe(false);
        expect(hasTotals(totalCells(FIELDS, { weight: '1.00' }))).toBe(true);
    });

    it('reads the totals of an inventory with no summable field as there being none', () => {
        expect(hasTotals(totalCells([field('name', 'text')], {}))).toBe(false);
        expect(totalCells([], {})).toEqual([]);
    });
});
