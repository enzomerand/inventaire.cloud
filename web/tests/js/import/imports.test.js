/**
 * What the import dialog knows about imports, apart from the dialog: the mapping of the columns, the check that comes before
 * an import starts, the body of the requests, and how the end of an import is told.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    BACKGROUND_POLL_INTERVAL_MS,
    NEW_FIELD_TYPES,
    POLL_INTERVAL_MS,
    analysisBody,
    cleanName,
    defaultName,
    destinationValue,
    endNotice,
    failureText,
    fractionWarning,
    importableFields,
    isActive,
    isFinished,
    isTooLarge,
    isWaiting,
    mappingRows,
    mappingSummary,
    maxSizeFrom,
    newFieldType,
    optionsOf,
    parseDestination,
    pickResumable,
    reportNote,
    reportRows,
    resultLines,
    rowView,
    samplesText,
    startBody,
    statusTitle,
    validate,
} from '../../../resources/js/import/imports.js';
import { FIELDS, field } from '../support/inventory.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const ULID_NAME = '01HZX0000000000000000000NA';
const ULID_QTY = '01HZX0000000000000000000QT';
const ULID_DISCOUNT = '01HZX0000000000000000000DI';

/** The fields of the inventory, with a required one, and the two types a column cannot go to. */
const INVENTORY_FIELDS = [
    field(ULID_NAME, 'text', {}, { name: 'Name', required: true }),
    field(ULID_QTY, 'number', { decimals: 2 }, { name: 'Weight' }),
    field('doc', 'file', {}, { name: 'Manual' }),
    field('pic', 'photo', {}, { name: 'Picture' }),
];

/** The same fields and a percentage, for the columns that look like fractions. */
const WITH_DISCOUNT = [...INVENTORY_FIELDS, field(ULID_DISCOUNT, 'percentage', { decimals: 1 }, { name: 'Discount' })];

/** A column the API found. */
function column(index, header, samples = [], guess = 'text') {
    return { index, header, samples, guess };
}

/** An import as the API describes it. */
function record(id, status, extra = {}) {
    return {
        id,
        format: 'csv',
        status,
        error: null,
        file_name: 'tools.csv',
        size: 2048,
        options: {},
        columns: [],
        mapping: null,
        rows_total: null,
        rows_imported: null,
        rows_failed: null,
        fields_created: null,
        errors: null,
        created_at: '2026-10-15T10:00:00Z',
        completed_at: null,
        expires_at: null,
        ...extra,
    };
}

/** The state of the mapping for a few columns, each going where the test says. */
function rows(...specs) {
    return specs.map(([index, target, extra = {}]) => ({
        index,
        header: `Column ${index}`,
        samples: [],
        guess: 'text',
        fractionLike: false,
        target,
        field: '',
        type: 'text',
        name: `Column ${index}`,
        ...extra,
    }));
}

describe('the imports', () => {
    beforeEach(() => {
        document.documentElement.lang = 'en';
    });

    describe('the statuses', () => {
        it('tells an import that is not over from one that is', () => {
            const statuses = ['uploaded', 'pending', 'running', 'completed', 'failed'];

            expect(statuses.map((status) => isActive({ status }))).toEqual([true, true, true, false, false]);
            expect(statuses.map((status) => isWaiting({ status }))).toEqual([false, true, true, false, false]);
            expect(statuses.map((status) => isFinished({ status }))).toEqual([false, false, false, true, true]);
        });

        it('asks again every two seconds, and every five while nobody looks at the dialog', () => {
            expect(POLL_INTERVAL_MS).toBe(2000);
            expect(BACKGROUND_POLL_INTERVAL_MS).toBe(5000);
        });
    });

    describe('the size of the file', () => {
        it('reads the largest file the page says, else the default of the server', () => {
            expect(maxSizeFrom('5242880')).toBe(5242880);
            expect(maxSizeFrom(undefined)).toBe(10 * 1024 * 1024);
            expect(maxSizeFrom('0')).toBe(10 * 1024 * 1024);
            expect(maxSizeFrom('lots')).toBe(10 * 1024 * 1024);
        });

        it('refuses a file larger than that, not one of exactly that size', () => {
            expect(isTooLarge({ size: 101 }, 100)).toBe(true);
            expect(isTooLarge({ size: 100 }, 100)).toBe(false);
        });
    });

    describe('the import to go on with', () => {
        it('picks the most recent import that is not over', () => {
            const list = [
                record('old', 'uploaded', { created_at: '2026-10-15T08:00:00Z' }),
                record('new', 'running', { created_at: '2026-10-15T09:00:00Z' }),
                record('done', 'completed', { created_at: '2026-10-15T10:00:00Z' }),
            ];

            expect(pickResumable(list).id).toBe('new');
        });

        it('picks nothing when every import is over, and nothing is waited for', () => {
            expect(pickResumable([record('done', 'completed'), record('bad', 'failed')])).toBeNull();
            expect(pickResumable([])).toBeNull();
            expect(pickResumable(undefined)).toBeNull();
        });

        it('picks the import whose end the person has not seen, when it is over now', () => {
            const list = [record('done', 'completed'), record('other', 'failed')];

            expect(pickResumable(list, 'done').id).toBe('done');
            expect(pickResumable(list, 'gone')).toBeNull();
        });

        it('prefers an import that is not over to the one whose end was not seen', () => {
            const list = [record('done', 'completed'), record('wait', 'uploaded')];

            expect(pickResumable(list, 'done').id).toBe('wait');
        });
    });

    describe('the options of the file', () => {
        it('reads the options of an import, and starts a spreadsheet from the separator of the language and UTF-8', () => {
            const options = optionsOf(
                record('a', 'uploaded', {
                    options: {
                        has_header: false,
                        delimiter: 'tab',
                        encoding: 'windows-1252',
                        decimal: 'comma',
                        date_order: 'ymd',
                        on_error: 'skip',
                    },
                }),
            );

            expect(options).toEqual({
                hasHeader: false,
                delimiter: 'tab',
                encoding: 'windows-1252',
                decimal: 'comma',
                dateOrder: 'ymd',
                onError: 'skip',
            });
            expect(
                optionsOf(
                    record('b', 'uploaded', {
                        options: { delimiter: null, encoding: null, decimal: 'point', date_order: 'dmy' },
                    }),
                ),
            ).toEqual({
                hasHeader: true,
                delimiter: 'comma',
                encoding: 'utf-8',
                decimal: 'point',
                dateOrder: 'dmy',
                onError: 'abort',
            });
            expect(optionsOf(null).hasHeader).toBe(true);
        });

        it('sends the separator and the encoding of a CSV file only', () => {
            const choice = { hasHeader: false, delimiter: 'tab', encoding: 'utf-16' };

            expect(analysisBody(choice, true)).toEqual({ has_header: false, delimiter: 'tab', encoding: 'utf-16' });
            expect(analysisBody(choice, false)).toEqual({ has_header: false });
        });
    });

    describe('the names of the new fields', () => {
        it('puts a header on one line and keeps it within the length of a name', () => {
            expect(defaultName('  Unit\n price  ')).toBe('Unit price');
            expect(defaultName('x'.repeat(200))).toHaveLength(120);
            expect(cleanName('  Café  ')).toBe('Café');
        });

        it('offers every type but the files, the photos and the multiple choice', () => {
            expect(NEW_FIELD_TYPES).toHaveLength(17);
            expect(NEW_FIELD_TYPES).not.toContain('file');
            expect(NEW_FIELD_TYPES).not.toContain('photo');
            expect(NEW_FIELD_TYPES).not.toContain('multiselect');
            expect(['money', 'multiselect', 'file', null, 'nonsense'].map(newFieldType)).toEqual([
                'money',
                'text',
                'text',
                'text',
                'text',
            ]);
        });

        it('offers every existing field but the files and the photos as a destination', () => {
            expect(importableFields(FIELDS).some((one) => one.type === 'multiselect')).toBe(true);
            expect(importableFields(INVENTORY_FIELDS).map((one) => one.name)).toEqual(['Name', 'Weight']);
        });
    });

    describe('the mapping', () => {
        const context = { fields: INVENTORY_FIELDS, canManageFields: true };

        it('starts from the mapping the API proposes, one row per column', () => {
            const state = mappingRows(
                [
                    column(0, 'Name', ['Hammer']),
                    column(1, 'Weight', ['1.5'], 'number'),
                    column(2, 'Price', ['9,90'], 'money'),
                ],
                [
                    { column: 0, target: 'field', field: ULID_NAME },
                    { column: 1, target: 'ignore' },
                    { column: 2, target: 'new', type: 'money', name: 'Unit price' },
                ],
                context,
            );

            expect(state.map((row) => [row.index, row.target, row.field, row.type, row.name])).toEqual([
                [0, 'field', ULID_NAME, 'text', 'Name'],
                [1, 'ignore', '', 'number', 'Weight'],
                [2, 'new', '', 'money', 'Unit price'],
            ]);
            expect(state[0].samples).toEqual(['Hammer']);
        });

        it('ignores a column the proposal says nothing about, and builds the new field from the header and the guess', () => {
            const [row] = mappingRows([column(4, 'Date bought', [], 'date')], null, context);

            expect(row).toMatchObject({ target: 'ignore', field: '', type: 'date', name: 'Date bought' });
        });

        it('leaves out a field that is not in the inventory, or cannot take a column, and a new field the person may not create', () => {
            const state = mappingRows(
                [column(0, 'A'), column(1, 'B'), column(2, 'C')],
                [
                    { column: 0, target: 'field', field: 'gone' },
                    { column: 1, target: 'field', field: 'doc' },
                    { column: 2, target: 'new', type: 'text', name: 'C' },
                ],
                { fields: INVENTORY_FIELDS, canManageFields: false },
            );

            expect(state.map((row) => row.target)).toEqual(['ignore', 'ignore', 'ignore']);
        });

        it('finds the field whatever the case of its id, and keeps the id the inventory gives it', () => {
            const [row] = mappingRows(
                [column(0, 'Name')],
                [{ column: 0, target: 'field', field: ULID_NAME.toLowerCase() }],
                context,
            );

            expect(row.field).toBe(ULID_NAME);
        });

        it('gives a type it cannot create the text type, and tolerates columns that are not what they should be', () => {
            const state = mappingRows(
                [{ index: 0, header: null, samples: null, guess: 'multiselect' }, column(1, 'B', ['ok', 5, null])],
                [
                    { column: 0, target: 'new', type: 'photo', name: '' },
                    { column: 7, target: 'field', field: ULID_QTY },
                ],
                context,
            );

            expect(state[0]).toMatchObject({ header: '', samples: [], type: 'text', name: '', target: 'new' });
            expect(state[1].samples).toEqual(['ok']);
            expect(mappingRows(undefined, undefined, context)).toEqual([]);
        });

        it('says what the select of a column holds, and what a value of it means', () => {
            expect(destinationValue({ target: 'ignore', field: '' })).toBe('ignore');
            expect(destinationValue({ target: 'new', field: '' })).toBe('new');
            expect(destinationValue({ target: 'field', field: ULID_QTY })).toBe(`field:${ULID_QTY}`);

            expect(parseDestination('ignore', context)).toEqual({ target: 'ignore', field: '' });
            expect(parseDestination('new', context)).toEqual({ target: 'new', field: '' });
            expect(parseDestination(`field:${ULID_QTY}`, context)).toEqual({ target: 'field', field: ULID_QTY });
        });

        it('refuses a value of the select that is not one it offers', () => {
            expect(parseDestination('new', { ...context, canManageFields: false })).toBeNull();
            expect(parseDestination('field:gone', context)).toBeNull();
            expect(parseDestination('field:doc', context)).toBeNull();
            expect(parseDestination('anything', context)).toBeNull();
        });

        it('shows the samples on one line, each cut when long', () => {
            expect(samplesText(['a', '', 'b'])).toBe('a · b');
            expect(samplesText([])).toBe('');
            expect(samplesText(['x'.repeat(60)])).toBe('x'.repeat(40) + '…');
        });

        it('names the controls of a column, and says when it needs attention', () => {
            const [row] = rows([3, 'new', { header: 'Price', type: 'select', name: 'Price' }]);
            const view = rowView(row, 'Give the new field a name.');

            expect(view).toMatchObject({
                index: 3,
                value: 'new',
                isNew: true,
                isSelect: true,
                destinationInvalid: 'false',
                nameInvalid: 'true',
                destinationId: 'import-destination-3',
                typeId: 'import-type-3',
                nameId: 'import-name-3',
                errorId: 'import-error-3',
                destinationLabel: 'Destination of the column Price',
                typeLabel: 'Type of the new field for the column Price',
                nameLabel: 'Name of the new field for the column Price',
            });
            expect(rowView(rows([0, 'ignore'])[0])).toMatchObject({
                isNew: false,
                isSelect: false,
                destinationInvalid: 'false',
                nameInvalid: 'false',
                error: '',
            });
            // The error of a column that goes to a field is about its destination.
            expect(rowView(rows([0, 'field'])[0], 'Choose a field.')).toMatchObject({
                destinationInvalid: 'true',
                nameInvalid: 'false',
            });
        });

        it('knows which columns hold values that look like fractions, and only when the API says so', () => {
            const state = mappingRows(
                [
                    { ...column(0, 'Rate', ['12.5%']), fraction_like: true },
                    { ...column(1, 'Weight', ['1.5']), fraction_like: false },
                    column(2, 'Other', ['x']),
                    { ...column(3, 'Odd', ['0.1']), fraction_like: 'yes' },
                ],
                null,
                context,
            );

            expect(state.map((row) => row.fractionLike)).toEqual([true, false, false, false]);
        });

        it('warns about fractions only for a column that goes to a percentage field, existing or to create', () => {
            const fraction = { fractionLike: true };
            const toDiscount = rows([0, 'field', { ...fraction, field: ULID_DISCOUNT }])[0];
            const toWeight = rows([0, 'field', { ...fraction, field: ULID_QTY }])[0];
            const message = 'These values look like fractions (0.125 for 12.5%): they will be imported as they are.';

            expect(fractionWarning(toDiscount, WITH_DISCOUNT)).toBe(message);
            expect(fractionWarning(rows([0, 'new', { ...fraction, type: 'percentage' }])[0], WITH_DISCOUNT)).toBe(
                message,
            );
            // Not a percentage: a number between -1 and 1 is just a number.
            expect(fractionWarning(toWeight, WITH_DISCOUNT)).toBe('');
            expect(fractionWarning(rows([0, 'new', { ...fraction, type: 'number' }])[0], WITH_DISCOUNT)).toBe('');
            // Nowhere to go, or a field that is not (any more) one of the inventory's.
            expect(fractionWarning(rows([0, 'ignore', { ...fraction, type: 'percentage' }])[0], WITH_DISCOUNT)).toBe(
                '',
            );
            expect(fractionWarning(rows([0, 'field', { ...fraction, field: 'gone' }])[0], WITH_DISCOUNT)).toBe('');
            // Whole percentages are fine.
            expect(
                fractionWarning(rows([0, 'field', { fractionLike: false, field: ULID_DISCOUNT }])[0], WITH_DISCOUNT),
            ).toBe('');
        });

        it('gives the warning to the view of a column, and describes the controls by it', () => {
            const [row] = rows([5, 'field', { fractionLike: true, field: ULID_DISCOUNT }]);

            expect(rowView(row, '', WITH_DISCOUNT)).toMatchObject({
                warning: 'These values look like fractions (0.125 for 12.5%): they will be imported as they are.',
                warningId: 'import-warning-5',
                errorId: 'import-error-5',
                describedBy: 'import-error-5 import-warning-5',
            });
            // Without the fields of the inventory the view cannot tell where the column goes: no warning, no reference to it.
            expect(rowView(row)).toMatchObject({ warning: '', describedBy: 'import-error-5' });
        });

        it('sums the mapping up: the columns imported and the fields created', () => {
            expect(mappingSummary(rows([0, 'ignore'], [1, 'field'], [2, 'new'], [3, 'new']))).toBe(
                '3 columns will be imported, 2 new fields will be created',
            );
            expect(mappingSummary(rows([0, 'ignore']))).toBe('0 columns will be imported');
            expect(mappingSummary(rows([0, 'field']))).toBe('1 column will be imported');
        });
    });

    describe('the check before the import starts', () => {
        const check = (state, fields = INVENTORY_FIELDS, canManageFields = true) =>
            validate(state, fields, { canManageFields });

        it('accepts a mapping with nothing wrong', () => {
            const state = rows([0, 'field', { field: ULID_NAME }], [1, 'new', { name: 'Price' }], [2, 'ignore']);

            expect(check(state)).toEqual({ valid: true, rowErrors: {}, general: [] });
        });

        it('asks for a field when a column goes to one and none is chosen', () => {
            const result = check(rows([0, 'field', { field: ULID_NAME }], [1, 'field']));

            expect(result.valid).toBe(false);
            expect(result.rowErrors).toEqual({ 1: 'Choose a field.' });
        });

        it('refuses two columns for one field, and names the second', () => {
            const result = check(rows([0, 'field', { field: ULID_NAME }], [1, 'field', { field: ULID_NAME }]));

            expect(result.rowErrors).toEqual({ 1: 'Another column already goes to this field.' });
        });

        it('refuses a new field with no name, a name that is too long, taken, or used twice', () => {
            const result = check(
                rows(
                    [0, 'field', { field: ULID_NAME }],
                    [1, 'new', { name: '   ' }],
                    [2, 'new', { name: 'x'.repeat(121) }],
                    [3, 'new', { name: ' weight ' }],
                    [4, 'new', { name: 'Price' }],
                    [5, 'new', { name: 'PRICE' }],
                ),
            );

            expect(result.rowErrors).toEqual({
                1: 'Give the new field a name.',
                2: 'The name of a field is limited to 120 characters.',
                3: 'A field already has this name.',
                5: 'Another new field has the same name.',
            });
        });

        it('refuses a new field when the person may not create fields', () => {
            const result = check(
                rows([0, 'field', { field: ULID_NAME }], [1, 'new', { name: 'Price' }]),
                INVENTORY_FIELDS,
                false,
            );

            expect(result.rowErrors).toEqual({ 1: 'You are not allowed to create fields.' });
        });

        it('refuses a mapping with no column at all', () => {
            const result = check(rows([0, 'ignore'], [1, 'ignore']), [field('x', 'text')]);

            expect(result.general).toEqual(['Match at least one column with a field.']);
            expect(check([], [])).toMatchObject({ valid: false, general: ['Match at least one column with a field.'] });
        });

        it('asks for a column for each field that is required', () => {
            const result = check(rows([0, 'field', { field: ULID_QTY }]));

            expect(result.general).toEqual(['The field Name is required: match a column with it.']);
            expect(result.valid).toBe(false);
        });

        it('does not ask for a column for a required field that no column can go to', () => {
            const fields = [field('doc', 'file', {}, { name: 'Manual', required: true }), INVENTORY_FIELDS[0]];

            expect(check(rows([0, 'field', { field: ULID_NAME }]), fields).valid).toBe(true);
        });
    });

    describe('the request that starts the import', () => {
        it('says what becomes of each column, then how the cells are read', () => {
            const state = rows(
                [0, 'field', { field: ULID_NAME }],
                [1, 'ignore', { field: ULID_QTY }],
                [2, 'new', { type: 'money', name: '  Unit price ' }],
            );

            expect(startBody(state, { decimal: 'comma', dateOrder: 'dmy', onError: 'skip' })).toEqual({
                mapping: [
                    { column: 0, target: 'field', field: ULID_NAME },
                    { column: 1, target: 'ignore' },
                    { column: 2, target: 'new', type: 'money', name: 'Unit price' },
                ],
                decimal: 'comma',
                date_order: 'dmy',
                on_error: 'skip',
            });
        });
    });

    describe('the end of an import', () => {
        it('titles an import by its status', () => {
            expect(['pending', 'running', 'completed', 'failed'].map((status) => statusTitle({ status }))).toEqual([
                'Import waiting',
                'Import in progress',
                'Import finished',
                'Import failed',
            ]);
        });

        it('says what a finished import did, and leaves out what it did not do', () => {
            expect(
                resultLines(record('a', 'completed', { rows_imported: 12, fields_created: 2, rows_failed: 3 })),
            ).toEqual(['12 items imported', '2 fields created', '3 rows skipped because of errors']);
            expect(
                resultLines(record('b', 'completed', { rows_imported: 1, fields_created: 0, rows_failed: 0 })),
            ).toEqual(['1 item imported']);
            expect(resultLines(record('c', 'completed'))).toEqual(['0 items imported']);
        });

        it('says how many rows were not valid in an import that failed, and nothing when none was', () => {
            expect(resultLines(record('a', 'failed', { rows_failed: 4 }))).toEqual(['4 rows are not valid']);
            expect(resultLines(record('b', 'failed'))).toEqual([]);
        });

        it('tells why an import failed in the words of the server, or in its own', () => {
            expect(
                failureText(record('a', 'failed', { error: 'The file holds more rows than an import may take.' })),
            ).toBe('The file holds more rows than an import may take.');
            expect(failureText(record('b', 'failed'))).toBe('The import failed.');
            expect(failureText(record('c', 'failed', { error: '' }))).toBe('The import failed.');
        });

        it('lists the rows in error with their row, column and message', () => {
            const list = reportRows(
                record('a', 'failed', {
                    errors: [
                        { row: 3, column: 'Price', message: 'Not a number.' },
                        { row: 7, column: null, message: 'Unknown choice.' },
                    ],
                }),
            );

            expect(list.map(({ row, column, message }) => [row, column, message])).toEqual([
                [3, 'Price', 'Not a number.'],
                [7, '', 'Unknown choice.'],
            ]);
            expect(new Set(list.map((one) => one.key)).size).toBe(2);
            expect(reportRows(record('b', 'completed'))).toEqual([]);
        });

        it('says when the report holds only the first rows in error', () => {
            const errors = [{ row: 2, column: 'A', message: 'No.' }];

            expect(reportNote(record('a', 'failed', { errors, rows_failed: 250 }))).toBe(
                'Only the first 1 rows in error out of 250 are listed.',
            );
            expect(reportNote(record('b', 'failed', { errors, rows_failed: 1 }))).toBe('');
            expect(reportNote(record('c', 'completed'))).toBe('');
        });

        it('announces the end of an import', () => {
            expect(endNotice(record('a', 'completed', { rows_imported: 3 }))).toBe(
                'Import finished: 3 items imported.',
            );
            expect(endNotice(record('b', 'completed', { rows_imported: 1 }))).toBe('Import finished: 1 item imported.');
            expect(endNotice(record('c', 'failed'))).toBe('The import failed.');
        });
    });
});
