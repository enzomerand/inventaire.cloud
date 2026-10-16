/**
 * The totals of the list of items: the sum the API gives for each field that adds up, shown like the values of the column.
 */
import { describeValue, isSummable } from '../fields/index.js';

/**
 * @typedef {object} TotalCell The foot of one column of the table.
 * @property {string} key The id of its field.
 * @property {string} text The total, formatted like the column ("€1,234.50", "12.50 kg"); "" for a column that does not add up.
 * @property {boolean} first Whether it is the first column, which also carries the word "Total".
 */

/**
 * The parameters that ask the API for the total of each field that adds up (`totals[0]`, `totals[1]`...). Nothing when no
 * field does, so that a list of such an inventory asks for none.
 *
 * @param {import('../fields/types.js').Field[]} fields
 * @returns {Record<string, string>}
 */
export function totalsParams(fields) {
    /** @type {Record<string, string>} */
    const params = {};

    fields.filter(isSummable).forEach((field, index) => {
        params[`totals[${index}]`] = field.id;
    });

    return params;
}

/**
 * The foot of each column, in the order of the fields.
 *
 * @param {import('../fields/types.js').Field[]} fields
 * @param {Record<string, string>} totals The sums of `meta.totals`, by the id of the field.
 * @returns {TotalCell[]}
 */
export function totalCells(fields, totals) {
    return fields.map((field, index) => {
        const total = isSummable(field) ? totals[field.id] : undefined;

        return {
            key: field.id,
            text: typeof total === 'string' ? describeValue(field, total).text : '',
            first: index === 0,
        };
    });
}

/**
 * Whether any column has a total to show.
 *
 * @param {TotalCell[]} cells
 * @returns {boolean}
 */
export function hasTotals(cells) {
    return cells.some((cell) => cell.text !== '');
}
