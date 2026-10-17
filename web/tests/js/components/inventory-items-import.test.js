/**
 * The import dialog of the page of an inventory, at the level of the component: what it asks the API for, with which body,
 * how the steps follow one another (the file, the mapping, the result), how an import that is on its way is followed and
 * resumed, and what a refusal leaves.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { HttpError, http, uploadTimeoutFor } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';
import { onModalHidden, showModal } from '../../../resources/js/lib/modal.js';
import { INVENTORY, ITEMS, field, inventoryAnswer, mountComponent, page } from '../support/inventory.js';
import { dismiss, forgetDialogs } from '../support/modal.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', async () => import('../support/modal.js'));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const IMPORTS = `${INVENTORY}/imports`;
const NAME = '01HZX0000000000000000000NA';
const WEIGHT = '01HZX0000000000000000000WE';

/** The fields of the inventory: a required name, a weight, a file the columns cannot go to. */
const FIELDS = [
    field(NAME, 'text', {}, { name: 'Name', required: true }),
    field(WEIGHT, 'number', { decimals: 2 }, { name: 'Weight' }),
    field('01HZX0000000000000000000DO', 'file', {}, { name: 'Manual' }),
];

/** A column of the file, as the API describes it. */
function column(index, header, samples = [], guess = 'text') {
    return { index, header, samples, guess };
}

/** An import as the API describes it: waiting for a worker, unless the test says otherwise. */
function record(id, status = 'pending', extra = {}) {
    return {
        id,
        format: 'csv',
        status,
        error: null,
        file_name: 'tools.csv',
        size: 2048,
        options: {
            has_header: true,
            delimiter: 'semicolon',
            encoding: 'windows-1252',
            decimal: 'comma',
            date_order: 'dmy',
            on_error: 'abort',
        },
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

/** An import that waits for its mapping, with four columns and a mapping to start from. */
function uploaded(id = 'i1', extra = {}) {
    return record(id, 'uploaded', {
        columns: [
            column(0, 'Name', ['Hammer', 'Saw']),
            column(1, 'Weight', ['1,5', '2'], 'number'),
            column(2, 'Unit price', ['9,90 €'], 'money'),
            column(3, 'Created on', ['2026-10-15'], 'datetime'),
        ],
        mapping: [
            { column: 0, target: 'field', field: NAME },
            { column: 1, target: 'field', field: WEIGHT },
            { column: 2, target: 'new', type: 'money', name: 'Unit price' },
            { column: 3, target: 'ignore' },
        ],
        ...extra,
    });
}

/** An import that a worker has finished with. */
function completed(id = 'i1', extra = {}) {
    return record(id, 'completed', {
        rows_total: 12,
        rows_imported: 12,
        rows_failed: 0,
        fields_created: 1,
        completed_at: '2026-10-15T10:00:09Z',
        expires_at: '2026-10-16T10:00:09Z',
        ...extra,
    });
}

/** The event a file chooser fires when a person picks a file. */
function picked(file) {
    return { target: { files: [file], value: 'C:\\fakepath\\' + file.name } };
}

/** The event a select or an input fires. */
function changed(value) {
    return { target: { value } };
}

describe('the import dialog', () => {
    let component;

    /** The readings of the list of imports so far. */
    const listReads = () => http.get.mock.calls.filter((call) => call[0] === IMPORTS);

    /** The readings of one import so far (the polls). */
    const pollsOf = (id) => http.get.mock.calls.filter((call) => call[0] === `${IMPORTS}/${id}`);

    /** The readings of the items of the inventory so far. */
    const itemReads = () => http.get.mock.calls.filter((call) => call[0] === ITEMS);

    /**
     * Mounts the component with a dialog, and says what each reading of the list of imports answers, and each poll of one
     * import (the last answer repeats).
     */
    function mount({ list = [[]], polls = [], ...options } = {}) {
        component = mountComponent(inventoryItems, options);
        component.$refs.importModal = document.createElement('div');
        component.fields = FIELDS;

        const lists = [...list];
        const reads = [...polls];

        http.get.mockImplementation(async (url) => {
            if (url === IMPORTS) {
                const next = lists.length > 1 ? lists.shift() : lists[0];

                if (next instanceof Error) {
                    throw next;
                }

                return { data: next };
            }

            if (url.startsWith(`${IMPORTS}/`)) {
                const next = reads.length > 1 ? reads.shift() : reads[0];

                if (next === undefined) {
                    throw new Error(`unexpected poll of ${url}`);
                }

                if (next instanceof Error) {
                    throw next;
                }

                return { data: next };
            }

            if (url === INVENTORY) {
                return inventoryAnswer(FIELDS, 12);
            }

            if (url === ITEMS) {
                return page([]);
            }

            throw new Error(`unexpected reading of ${url}`);
        });
    }

    /**
     * Makes the polls of the import `id` answer what the test says (the last answer repeats), whatever mounted the component.
     */
    function answerPolls(id, ...answers) {
        const others = http.get.getMockImplementation();
        const left = [...answers];

        http.get.mockImplementation(async (url, options) => {
            if (url !== `${IMPORTS}/${id}`) {
                return others(url, options);
            }

            const next = left.length > 1 ? left.shift() : left[0];

            if (next instanceof Error) {
                throw next;
            }

            return { data: next };
        });
    }

    /** The body the last request that sent one carried. */
    const lastBody = (method = 'post') => http[method].mock.calls.at(-1)[1];

    /** A CSV file of the given size. */
    const csv = (name = 'tools.csv', text = 'Name;Weight\nHammer;1,5\n') =>
        new File([text], name, { type: 'text/csv' });

    beforeEach(() => {
        http.get.mockReset();
        http.post.mockReset();
        http.patch.mockReset();
        http.delete.mockReset();
        forgetDialogs();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('opening it', () => {
        it('shows the dialog with the cursor on the file chooser, and reads the imports', async () => {
            mount();

            await component.openImport();

            expect(showModal).toHaveBeenCalledWith(component.$refs.importModal, { focus: ['#import-file'] });
            expect(http.get).toHaveBeenCalledWith(
                IMPORTS,
                expect.objectContaining({ signal: expect.any(AbortSignal) }),
            );
            expect(component.importOpen).toBe(true);
            expect(component.importOnFile).toBe(true);
            expect(component.importRecord).toBeNull();
        });

        it('waits for the imports before it shows a step', async () => {
            mount();
            const pending = component.openImport();

            expect(component.importOnLoading).toBe(true);
            expect(component.importOnFile).toBe(false);

            await pending;

            expect(component.importOnLoading).toBe(false);
        });

        it('asks for the file when the imports cannot be read', async () => {
            mount({ list: [new HttpError(500, 'Down.')] });

            await component.openImport();

            expect(component.importOnFile).toBe(true);
        });

        it('leaves the page when the inventory is gone, as every other request of the dialog does', async () => {
            mount({ list: [new HttpError(404, 'Gone.')] });

            await component.openImport();

            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
            expect(component.importOnFile).toBe(false);
        });

        it('does not hide a bug behind the choice of a file', async () => {
            mount({ list: [[uploaded()]] });
            const bug = new TypeError('boom');

            vi.spyOn(component, 'showImport').mockImplementation(() => {
                throw bug;
            });

            await expect(component.openImport()).rejects.toBe(bug);
            expect(component.importOnFile).toBe(false);
        });

        it('says nothing of a reading the closing of the dialog cancelled', async () => {
            mount();
            http.get.mockImplementation(
                (url, { signal }) =>
                    new Promise((resolve, reject) => {
                        signal.addEventListener('abort', () =>
                            reject(new DOMException('The operation was aborted.', 'AbortError')),
                        );
                    }),
            );

            const opening = component.openImport();

            component.onImportHidden();

            await expect(opening).resolves.toBeUndefined();
            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.importOnFile).toBe(false);
        });

        describe('the cursor', () => {
            /** The dialog with its three steps, in the page, so that the cursor can be on something. */
            function dialogInPage() {
                const modal = component.$refs.importModal;

                modal.innerHTML = ['file', 'mapping', 'result']
                    .map(
                        (step) =>
                            `<div data-import-step="${step}"><h3 tabindex="-1" data-import-heading>${step}</h3>` +
                            (step === 'file' ? '<input type="file" id="import-file">' : '') +
                            '</div>',
                    )
                    .join('');
                document.body.append(modal);

                return modal;
            }

            afterEach(() => {
                component.$refs.importModal.remove();
            });

            it('goes to the file chooser when the file step shows, and to the title of the other steps', async () => {
                http.post.mockResolvedValue({ data: uploaded() });
                http.delete.mockResolvedValue({});
                mount();
                const modal = dialogInPage();

                await component.openImport();

                expect(component.importOnFile).toBe(true);
                expect(document.activeElement).toBe(modal.querySelector('#import-file'));

                await component.onImportFile(picked(csv()));

                expect(document.activeElement).toBe(modal.querySelector('[data-import-step="mapping"] h3'));

                await component.cancelImport();

                expect(component.importOnFile).toBe(true);
                expect(document.activeElement).toBe(modal.querySelector('#import-file'));
            });

            it('goes to the file chooser even when the imports took their time, as the step only shows then', async () => {
                const answer = Promise.withResolvers();

                mount();
                const modal = dialogInPage();

                http.get.mockReturnValue(answer.promise);
                const opening = component.openImport();

                expect(document.activeElement).toBe(document.body);

                answer.resolve({ data: [] });
                await opening;

                expect(document.activeElement).toBe(modal.querySelector('#import-file'));
            });
        });

        it('listens to the closing of the dialog once, however many times it opens', async () => {
            mount();

            await component.openImport();
            dismiss(component.$refs.importModal);
            await vi.waitFor(() => expect(component.importOpen).toBe(false));
            await component.openImport();
            dismiss(component.$refs.importModal);
            await vi.waitFor(() => expect(component.importOpen).toBe(false));

            expect(onModalHidden.mock.calls.filter((call) => call[0] === component.$refs.importModal)).toHaveLength(1);
        });

        it('forgets the import and what was said when it closes', async () => {
            mount({ list: [[uploaded()]] });
            await component.openImport();
            component.importError = 'Nope.';
            component.importStatus = 'Done.';

            dismiss(component.$refs.importModal);
            await vi.waitFor(() => expect(component.importOpen).toBe(false));

            expect(component.importRecord).toBeNull();
            expect(component.importRows).toEqual([]);
            expect(component.importError).toBe('');
            expect(component.importStatus).toBe('');
        });

        it('says the largest file the page says, else the default of the server', () => {
            mount({ importMaxSize: 5 * 1024 * 1024 });

            expect(component.importMaxSize).toBe(5 * 1024 * 1024);
            expect(component.importMaxSizeLabel).toBe('5 MB');
            expect(component.importFileHint).toBe(
                'CSV, TSV, TXT, Excel (.xlsx) or OpenDocument (.ods) file, up to 5 MB.',
            );

            mount();

            expect(component.importMaxSize).toBe(10 * 1024 * 1024);
        });

        it('lists the extensions of the files the chooser offers', () => {
            mount();

            expect(component.importAccept).toBe('.csv,.tsv,.txt,.xlsx,.ods');
        });
    });

    describe('sending the file', () => {
        it('sends the file as soon as it is chosen, and goes to the mapping with the columns it comes back with', async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();
            const file = csv();
            const event = picked(file);

            await component.onImportFile(event);

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(http.post.mock.calls[0][0]).toBe(IMPORTS);
            expect(lastBody()).toBeInstanceOf(FormData);
            expect(lastBody().get('file').name).toBe('tools.csv');
            expect(http.post.mock.calls[0][2].timeout).toBeGreaterThanOrEqual(120_000);
            expect(event.target.value).toBe('');
            expect(component.importOnMapping).toBe(true);
            expect(component.importRecord.id).toBe('i1');
            expect(component.importWorking).toBe(false);
            expect(component.importStatus).toBe('File read: 4 columns found.');
        });

        it('takes the mapping the API proposes and the options it read the file with', async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();

            await component.onImportFile(picked(csv()));

            expect(component.importMappingRows.map((row) => [row.header, row.value, row.name])).toEqual([
                ['Name', `field:${NAME}`, 'Name'],
                ['Weight', `field:${WEIGHT}`, 'Weight'],
                ['Unit price', 'new', 'Unit price'],
                ['Created on', 'ignore', 'Created on'],
            ]);
            expect(component.importMappingRows[1].samples).toBe('1,5 · 2');
            expect(component.importIsCsv).toBe(true);
            expect(component.importHasHeader).toBe(true);
            expect(component.importDelimiter).toBe('semicolon');
            expect(component.importEncoding).toBe('windows-1252');
            expect(component.importDecimal).toBe('comma');
            expect(component.importDateOrder).toBe('dmy');
            expect(component.importOnError).toBe('abort');
            expect(component.importFileInfo).toBe('tools.csv · 2 kB');
            expect(component.importMappingSummary).toBe('3 columns will be imported, 1 new field will be created');
        });

        it('lists the fields a column can go to without the files, and the types a new field can have without the files', async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();
            await component.onImportFile(picked(csv()));

            expect(component.importFieldOptions.map((option) => option.name)).toEqual(['Name', 'Weight']);
            expect(component.importTypeOptions).toHaveLength(17);
            expect(component.importTypeOptions[0]).toEqual({ id: 'text', label: 'Text' });
            expect(component.importTypeOptions.map((option) => option.id)).not.toContain('photo');
        });

        it('takes a spreadsheet for a file with no separator nor encoding', async () => {
            http.post.mockResolvedValue({
                data: uploaded('i1', {
                    format: 'xlsx',
                    options: {
                        has_header: true,
                        delimiter: null,
                        encoding: null,
                        decimal: 'point',
                        date_order: 'mdy',
                        on_error: 'abort',
                    },
                }),
            });
            mount();
            await component.openImport();

            await component.onImportFile(picked(csv('tools.xlsx')));

            expect(component.importIsCsv).toBe(false);
            expect(component.importDecimal).toBe('point');
            expect(component.importDateOrder).toBe('mdy');
        });

        it('refuses a file larger than the server takes, before it goes up', async () => {
            mount({ importMaxSize: 1024 });
            await component.openImport();

            await component.onImportFile(picked(csv('big.csv', 'x'.repeat(2048))));

            expect(http.post).not.toHaveBeenCalled();
            expect(component.importError).toBe('This file is larger than 1 kB.');
            expect(component.importOnFile).toBe(true);
            expect(component.importAlerts.map((alert) => alert.text)).toEqual(['This file is larger than 1 kB.']);
        });

        it('takes a file of exactly the largest size', async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount({ importMaxSize: 8 });
            await component.openImport();

            await component.onImportFile(picked(csv('ok.csv', 'x'.repeat(8))));

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('does nothing when the chooser is closed with no file', async () => {
            mount();
            await component.openImport();

            await component.onImportFile({ target: { files: [], value: '' } });

            expect(http.post).not.toHaveBeenCalled();
        });

        it('sends one file at a time', async () => {
            const sending = Promise.withResolvers();

            http.post.mockReturnValue(sending.promise);
            mount();
            await component.openImport();

            const first = component.onImportFile(picked(csv()));

            expect(component.importUploading).toBe(true);
            expect(component.importUploadText).toBe('Sending tools.csv…');

            await component.onImportFile(picked(csv('other.csv')));
            sending.resolve({ data: uploaded() });
            await first;

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.importUploading).toBe(false);
        });

        it('says what is wrong with the file, in the words of the server', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', { errors: { file: ['The file is empty.', 'Nope.'] } }),
            );
            mount();
            await component.openImport();

            await component.onImportFile(picked(csv()));

            expect(component.importError).toBe('The file is empty.');
            expect(component.importOnFile).toBe(true);
            expect(component.importWorking).toBe(false);
        });

        it.each([
            [
                409,
                'import_limit_reached',
                'You already have too many imports under way for this inventory. Cancel one or wait for it to end, then try again.',
            ],
            [429, null, 'Slow down.'],
        ])('says why a file was refused (%s)', async (status, code, message) => {
            http.post.mockRejectedValue(new HttpError(status, 'Slow down.', { code }));
            mount();
            await component.openImport();

            await component.onImportFile(picked(csv()));

            expect(component.importError).toBe(message);
        });

        it('does not repeat a failure the helper already reported', async () => {
            const error = new HttpError(500, 'Down.');

            error.reported = true;
            http.post.mockRejectedValue(error);
            mount();
            await component.openImport();

            await component.onImportFile(picked(csv()));

            expect(component.importError).toBe('');
        });

        it('says something went wrong for a failure that is not an HTTP one', async () => {
            http.post.mockRejectedValue(new Error('boom'));
            mount();
            await component.openImport();

            await component.onImportFile(picked(csv()));

            // The messages of the tests do not hold this one: the key stands for it.
            expect(component.importError).toBe('error.generic');
        });

        it('leaves the page when the inventory is gone', async () => {
            http.post.mockRejectedValue(new HttpError(404, 'Gone.'));
            mount();
            await component.openImport();

            await component.onImportFile(picked(csv()));

            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
        });
    });

    describe('reading the file again', () => {
        beforeEach(async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();
            await component.onImportFile(picked(csv()));
        });

        it('asks for the separator and the encoding that were chosen, and takes the columns that come back', async () => {
            http.patch.mockResolvedValue({
                data: uploaded('i1', {
                    options: { ...uploaded().options, delimiter: 'comma' },
                    columns: [column(0, 'Name;Weight', ['Hammer;1,5'])],
                    mapping: [{ column: 0, target: 'new', type: 'text', name: 'Name;Weight' }],
                }),
            });
            component.importDelimiter = 'comma';
            component.importEncoding = 'utf-8';

            await component.reanalyzeImport();

            expect(http.patch).toHaveBeenCalledWith(
                `${IMPORTS}/i1`,
                { has_header: true, delimiter: 'comma', encoding: 'utf-8' },
                { timeout: uploadTimeoutFor(2048) },
            );
            expect(component.importMappingRows.map((row) => row.header)).toEqual(['Name;Weight']);
            expect(component.importDelimiter).toBe('comma');
            expect(component.importStatus).toBe('The file was read again. The columns have been matched anew.');
            expect(component.importWorking).toBe(false);
        });

        it('gives the reading of a large file the time an upload of it would have', async () => {
            const size = 20 * 1024 * 1024;

            component.importRecord = { ...component.importRecord, size };
            http.patch.mockResolvedValue({ data: uploaded('i1', { size }) });
            component.importHasHeader = false;

            await component.reanalyzeImport();

            const { timeout } = http.patch.mock.calls.at(-1)[2];

            expect(timeout).toBe(uploadTimeoutFor(size));
            expect(timeout).toBeGreaterThan(uploadTimeoutFor(2048));
        });

        it('asks again when the row of the headers is the one that changed', async () => {
            http.patch.mockResolvedValue({
                data: uploaded('i1', { options: { ...uploaded().options, has_header: false } }),
            });
            component.importHasHeader = false;

            await component.reanalyzeImport();

            expect(lastBody('patch')).toEqual({ has_header: false, delimiter: 'semicolon', encoding: 'windows-1252' });
            expect(component.importHasHeader).toBe(false);
        });

        it('sends the row of the headers alone for a spreadsheet', async () => {
            component.importRecord = { ...component.importRecord, format: 'xlsx' };
            http.patch.mockResolvedValue({ data: uploaded('i1', { format: 'xlsx' }) });
            component.importHasHeader = false;

            await component.reanalyzeImport();

            expect(lastBody('patch')).toEqual({ has_header: false });
        });

        it('forgets what had been decided for the old columns', async () => {
            component.setImportDestination({ index: 3 }, changed('new'));
            http.patch.mockResolvedValue({ data: uploaded() });

            await component.reanalyzeImport();

            expect(component.importMappingRows[3].value).toBe('ignore');
        });

        it('keeps the decimal sign the person chose, and follows the one it set itself', async () => {
            component.importDecimal = 'point';
            http.patch.mockResolvedValue({
                data: uploaded('i1', { options: { ...uploaded().options, decimal: 'point', date_order: 'mdy' } }),
            });

            await component.reanalyzeImport();

            expect(component.importDecimal).toBe('point');
            // The order of the dates was left as the dialog set it: it follows what the API now says.
            expect(component.importDateOrder).toBe('mdy');
        });

        it('puts the controls back to what the file is read with when the API refuses', async () => {
            http.patch.mockRejectedValue(
                new HttpError(422, 'Invalid.', { errors: { file: ['The file cannot be read.'] } }),
            );
            component.importDelimiter = 'tab';
            component.importHasHeader = false;

            await component.reanalyzeImport();

            expect(component.importDelimiter).toBe('semicolon');
            expect(component.importHasHeader).toBe(true);
            expect(component.importError).toBe('The file cannot be read.');
            expect(component.importOnMapping).toBe(true);
        });

        it('goes back to the file when the import is gone', async () => {
            http.patch.mockRejectedValue(new HttpError(404, 'Gone.'));

            await component.reanalyzeImport();

            expect(component.importOnFile).toBe(true);
            expect(component.importError).toBe('This import is no longer available. Choose the file again.');
        });

        it('reads the imports again when the import was started elsewhere', async () => {
            http.patch.mockRejectedValue(new HttpError(409, 'Started.', { code: 'import_not_editable' }));
            const before = listReads().length;

            await component.reanalyzeImport();

            expect(component.importError).toBe('This import has already started and can no longer be changed.');
            await vi.waitFor(() => expect(listReads().length).toBe(before + 1));
        });
    });

    describe('matching the columns', () => {
        beforeEach(async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();
            await component.onImportFile(picked(csv()));
        });

        it('sends a column to an existing field, to a new one, or nowhere', () => {
            component.setImportDestination({ index: 3 }, changed(`field:${WEIGHT}`));
            component.setImportDestination({ index: 0 }, changed('new'));
            component.setImportDestination({ index: 1 }, changed('ignore'));

            expect(component.importMappingRows.map((row) => row.value)).toEqual([
                'new',
                'ignore',
                'new',
                `field:${WEIGHT}`,
            ]);
        });

        it('shows the type and the name of a field to create, and keeps them when the column goes elsewhere and back', () => {
            component.setImportType({ index: 2 }, changed('select'));
            component.setImportName({ index: 2 }, changed('Price list'));

            expect(component.importMappingRows[2]).toMatchObject({
                isNew: true,
                isSelect: true,
                type: 'select',
                name: 'Price list',
            });

            component.setImportDestination({ index: 2 }, changed('ignore'));
            component.setImportDestination({ index: 2 }, changed('new'));

            expect(component.importMappingRows[2]).toMatchObject({ isNew: true, type: 'select', name: 'Price list' });
        });

        it('refuses a destination or a type the dialog does not offer', () => {
            component.setImportDestination({ index: 0 }, changed('field:gone'));
            component.setImportDestination({ index: 0 }, changed('field:01HZX0000000000000000000DO'));
            component.setImportType({ index: 2 }, changed('photo'));
            component.setImportType({ index: 2 }, changed('multiselect'));

            expect(component.importMappingRows[0].value).toBe(`field:${NAME}`);
            expect(component.importMappingRows[2].type).toBe('money');
        });

        it('does nothing for a column it does not know', () => {
            component.setImportDestination({ index: 9 }, changed('new'));
            component.setImportType({ index: 9 }, changed('text'));
            component.setImportName({ index: 9 }, changed('Nothing'));

            expect(component.importRows).toHaveLength(4);
        });
    });

    describe('columns whose values look like fractions', () => {
        const DISCOUNT = '01HZX0000000000000000000DI';
        const WARNING = 'These values look like fractions (0.125 for 12.5%): they will be imported as they are.';

        beforeEach(async () => {
            http.post.mockResolvedValue({
                data: uploaded('i1', {
                    columns: [
                        column(0, 'Name', ['Hammer']),
                        { ...column(1, 'Rate', ['0.125', '0.5'], 'percentage'), fraction_like: true },
                        { ...column(2, 'Weight', ['0.5', '1'], 'number'), fraction_like: true },
                        { ...column(3, 'Margin', ['12.5', '30'], 'percentage'), fraction_like: false },
                    ],
                    mapping: [
                        { column: 0, target: 'field', field: NAME },
                        { column: 1, target: 'field', field: DISCOUNT },
                        { column: 2, target: 'field', field: WEIGHT },
                        { column: 3, target: 'new', type: 'percentage', name: 'Margin' },
                    ],
                }),
            });
            mount();
            component.fields = [...FIELDS, field(DISCOUNT, 'percentage', { decimals: 1 }, { name: 'Discount' })];
            await component.openImport();
            await component.onImportFile(picked(csv()));
        });

        const warnings = () => component.importMappingRows.map((row) => row.warning);

        it('warns about the column that looks like fractions and goes to a percentage field', () => {
            expect(warnings()).toEqual(['', WARNING, '', '']);
            expect(component.importMappingRows[1].describedBy).toBe('import-error-1 import-warning-1');
            expect(component.importMappingRows[0].describedBy).toBe('import-error-0');
        });

        it('follows the destination and the type the person chooses', () => {
            component.setImportDestination({ index: 1 }, changed(`field:${WEIGHT}`));
            component.setImportDestination({ index: 2 }, changed(`field:${DISCOUNT}`));

            expect(warnings()).toEqual(['', '', WARNING, '']);

            component.setImportDestination({ index: 2 }, changed('new'));
            component.setImportType({ index: 2 }, changed('percentage'));

            expect(warnings()).toEqual(['', '', WARNING, '']);

            component.setImportType({ index: 2 }, changed('number'));
            component.setImportDestination({ index: 1 }, changed('ignore'));

            expect(warnings()).toEqual(['', '', '', '']);
        });

        it('only warns: the import starts, and the values go as they are', async () => {
            http.post.mockResolvedValue({ data: record('i1', 'pending') });

            await component.startImport();

            expect(component.importOnResult).toBe(true);
            expect(lastBody().mapping[1]).toEqual({ column: 1, target: 'field', field: DISCOUNT });
            expect(component.hasImportAlerts).toBe(false);
        });

        it('judges the columns of the file as it was read again', async () => {
            http.patch.mockResolvedValue({
                data: uploaded('i1', {
                    columns: [{ ...column(0, 'Rate', ['12.5'], 'percentage'), fraction_like: false }],
                    mapping: [{ column: 0, target: 'field', field: DISCOUNT }],
                }),
            });

            await component.reanalyzeImport();

            expect(warnings()).toEqual(['']);
        });
    });

    describe('when the person may not create fields', () => {
        beforeEach(async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount({ canManageFields: false });
            await component.openImport();
            await component.onImportFile(picked(csv()));
        });

        it('does not take the new field the mapping proposes', () => {
            expect(component.canManageFields).toBe(false);
            expect(component.importMappingRows.map((row) => row.value)).toEqual([
                `field:${NAME}`,
                `field:${WEIGHT}`,
                'ignore',
                'ignore',
            ]);
        });

        it('does not let a column go to a new field', () => {
            component.setImportDestination({ index: 3 }, changed('new'));

            expect(component.importMappingRows[3].value).toBe('ignore');
            expect(component.importMappingRows.some((row) => row.isNew)).toBe(false);
        });
    });

    describe('checking the mapping', () => {
        beforeEach(async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();
            await component.onImportFile(picked(csv()));
            http.post.mockReset();
        });

        it('does not start an import whose mapping has something wrong, and says what, column by column', async () => {
            component.setImportDestination({ index: 1 }, changed(`field:${NAME}`));
            component.setImportName({ index: 2 }, changed('  '));

            await component.startImport();

            expect(http.post).not.toHaveBeenCalled();
            expect(component.importOnMapping).toBe(true);
            expect(component.importMappingRows.map((row) => row.error)).toEqual([
                '',
                'Another column already goes to this field.',
                'Give the new field a name.',
                '',
            ]);
            expect(component.importMappingRows.map((row) => [row.destinationInvalid, row.nameInvalid])).toEqual([
                ['false', 'false'],
                ['true', 'false'],
                ['false', 'true'],
                ['false', 'false'],
            ]);
            expect(component.importAlerts.map((alert) => alert.text)).toEqual(['Some columns need your attention.']);
            expect(component.importWorking).toBe(false);
        });

        it('asks for a column for a field that is required', async () => {
            component.setImportDestination({ index: 0 }, changed('ignore'));

            await component.startImport();

            expect(http.post).not.toHaveBeenCalled();
            expect(component.importAlerts.map((alert) => alert.text)).toEqual([
                'The field Name is required: match a column with it.',
            ]);
        });

        it('refuses a mapping with no column to import', async () => {
            for (const index of [0, 1, 2]) {
                component.setImportDestination({ index }, changed('ignore'));
            }

            await component.startImport();

            expect(http.post).not.toHaveBeenCalled();
            expect(component.importProblems).toContain('Match at least one column with a field.');
        });

        it('leaves a column where it was when the field chosen for it is none', async () => {
            component.setImportDestination({ index: 0 }, changed('field:'));

            await component.startImport();

            // A value that names no field is no choice at all: the column stays where it was.
            expect(component.importMappingRows[0].value).toBe(`field:${NAME}`);
        });

        it('forgets what was wrong with a column once the person changes it', async () => {
            component.setImportName({ index: 2 }, changed(''));
            await component.startImport();

            expect(component.importMappingRows[2].error).toBe('Give the new field a name.');

            component.setImportName({ index: 2 }, changed('Price'));

            expect(component.importMappingRows[2].error).toBe('');
            expect(component.importAlerts).toEqual([]);
        });

        it('puts the cursor on the first column that has something wrong', async () => {
            const input = document.createElement('input');
            const step = document.createElement('div');

            input.setAttribute('aria-invalid', 'true');
            step.dataset.importStep = 'mapping';
            step.append(input);
            component.$refs.importModal.append(step);
            document.body.append(component.$refs.importModal);
            component.setImportName({ index: 2 }, changed(''));

            await component.startImport();

            expect(document.activeElement).toBe(input);
            component.$refs.importModal.remove();
        });
    });

    describe('starting the import', () => {
        beforeEach(async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();
            await component.onImportFile(picked(csv()));
            http.post.mockReset();
            http.post.mockResolvedValue({ data: record('i1', 'pending') });
        });

        it('sends what becomes of each column, then how the cells are read', async () => {
            component.importDecimal = 'point';
            component.importDateOrder = 'mdy';
            component.importOnError = 'skip';
            component.setImportType({ index: 2 }, changed('percentage'));
            component.setImportName({ index: 2 }, changed(' Discount '));

            await component.startImport();

            expect(http.post).toHaveBeenCalledWith(`${IMPORTS}/i1/start`, {
                mapping: [
                    { column: 0, target: 'field', field: NAME },
                    { column: 1, target: 'field', field: WEIGHT },
                    { column: 2, target: 'new', type: 'percentage', name: 'Discount' },
                    { column: 3, target: 'ignore' },
                ],
                decimal: 'point',
                date_order: 'mdy',
                on_error: 'skip',
            });
        });

        it('goes to the result, with the import waiting', async () => {
            await component.startImport();

            expect(component.importOnResult).toBe(true);
            expect(component.importWaiting).toBe(true);
            expect(component.importResultTitle).toBe('Import waiting');
            expect(component.importStatus).toBe('Import started. It will be done in a moment.');
            expect(component.importWorking).toBe(false);
        });

        it('takes an import that comes back as it was for a waiting one', async () => {
            http.post.mockResolvedValue({ data: uploaded() });

            await component.startImport();

            expect(component.importWaiting).toBe(true);
        });

        it('starts once, however many times it is asked for', async () => {
            const starting = Promise.withResolvers();

            http.post.mockReturnValue(starting.promise);
            const first = component.startImport();

            expect(component.importStarting).toBe(true);

            await component.startImport();
            starting.resolve({ data: record('i1', 'pending') });
            await first;

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('tells with a toast, and watches the import, when the dialog was closed while the request was on its way', async () => {
            vi.useFakeTimers();
            const starting = Promise.withResolvers();

            http.post.mockReturnValue(starting.promise);
            answerPolls('i1', record('i1', 'running'), completed('i1', { rows_imported: 12 }));
            const sending = component.startImport();

            component.onImportHidden();
            starting.resolve({ data: record('i1', 'pending') });
            await sending;

            expect(notify).toHaveBeenCalledWith('success', 'Import started. It will be done in a moment.');
            expect(component.importOpen).toBe(false);
            expect(pollsOf('i1')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(5000);
            await vi.advanceTimersByTimeAsync(5000);

            expect(pollsOf('i1')).toHaveLength(2);
            expect(notify).toHaveBeenLastCalledWith('success', 'Import finished: 12 items imported.');
            expect(itemReads()).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(2);
            expect(itemReads()).toHaveLength(1);
        });

        it('tells the end at once when the import was over by the time the request answered and the dialog was closed', async () => {
            vi.useFakeTimers();
            const starting = Promise.withResolvers();

            http.post.mockReturnValue(starting.promise);
            const sending = component.startImport();

            component.onImportHidden();
            starting.resolve({ data: completed('i1', { rows_imported: 3 }) });
            await sending;
            await vi.advanceTimersByTimeAsync(60_000);

            expect(notify).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('success', 'Import finished: 3 items imported.');
            expect(itemReads()).toHaveLength(1);
            expect(pollsOf('i1')).toHaveLength(0);
        });

        it('says what the mapping got wrong, in the words of the server', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: {
                        mapping: ['A field of the mapping does not exist.'],
                        'mapping.1': ['Two columns go to one field.'],
                    },
                }),
            );

            await component.startImport();

            expect(component.importError).toBe('A field of the mapping does not exist. Two columns go to one field.');
            expect(component.importOnMapping).toBe(true);
            expect(component.importWorking).toBe(false);
        });

        it('reads the imports again when the import is no longer waiting for its mapping', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Started.', { code: 'import_not_editable' }));
            const before = listReads().length;

            await component.startImport();

            expect(component.importError).toBe('This import has already started and can no longer be changed.');
            await vi.waitFor(() => expect(listReads().length).toBe(before + 1));
        });

        it('goes back to the file when the import is gone', async () => {
            http.post.mockRejectedValue(new HttpError(404, 'Gone.'));

            await component.startImport();

            expect(component.importOnFile).toBe(true);
            expect(component.importError).toBe('This import is no longer available. Choose the file again.');
        });

        it('says the throttling under the buttons', async () => {
            http.post.mockRejectedValue(new HttpError(429, 'Too many attempts.'));

            await component.startImport();

            expect(component.importError).toBe('Too many attempts.');
        });
    });

    describe('following the import', () => {
        beforeEach(() => {
            vi.useFakeTimers();
        });

        /** Starts an import that is waiting, with the answers of the polls that follow. */
        async function startFollowing(polls) {
            http.post
                .mockResolvedValueOnce({ data: uploaded() })
                .mockResolvedValueOnce({ data: record('i1', 'pending') });
            mount({ polls });
            await component.openImport();
            await component.onImportFile(picked(csv()));
            await component.startImport();
        }

        it('reads the import again every two seconds while it has not ended, and silently', async () => {
            await startFollowing([record('i1', 'running'), record('i1', 'running'), completed()]);

            expect(pollsOf('i1')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(1999);

            expect(pollsOf('i1')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(1);

            expect(pollsOf('i1')).toHaveLength(1);
            expect(pollsOf('i1')[0][1]).toEqual(expect.objectContaining({ silent: true }));
            expect(component.importResultTitle).toBe('Import in progress');

            await vi.advanceTimersByTimeAsync(2000);

            expect(pollsOf('i1')).toHaveLength(2);

            await vi.advanceTimersByTimeAsync(2000);

            expect(pollsOf('i1')).toHaveLength(3);
            expect(component.importResultTitle).toBe('Import finished');
        });

        it('stops once the import has ended, and tells how many items it added', async () => {
            await startFollowing([completed('i1', { rows_imported: 12, fields_created: 1 })]);

            await vi.advanceTimersByTimeAsync(2000);
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(1);
            expect(component.importCompleted).toBe(true);
            expect(component.importWaiting).toBe(false);
            expect(component.importResultLines).toEqual(['12 items imported', '1 field created']);
            expect(component.importStatus).toBe('Import finished: 12 items imported.');
        });

        it('reads the fields and the first page of the items again when the import is completed, once', async () => {
            await startFollowing([completed()]);
            component.page = 3;
            http.get.mockClear();
            http.get.mockImplementation(async (url) => {
                if (url === INVENTORY) {
                    return inventoryAnswer(FIELDS, 12);
                }

                if (url === ITEMS) {
                    return page([]);
                }

                return { data: completed() };
            });

            await vi.advanceTimersByTimeAsync(2000);
            await vi.advanceTimersByTimeAsync(60_000);

            const urls = http.get.mock.calls.map((call) => call[0]);

            expect(urls.filter((url) => url === INVENTORY)).toHaveLength(1);
            expect(urls.filter((url) => url === ITEMS)).toHaveLength(1);
            expect(urls.indexOf(INVENTORY)).toBeLessThan(urls.indexOf(ITEMS));
            expect(http.get.mock.calls.find((call) => call[0] === ITEMS)[1].params).toHaveProperty('page', 1);
            // The totals are read with the page: what the items hold has changed.
            expect(http.get.mock.calls.find((call) => call[0] === ITEMS)[1].params).toHaveProperty('totals[0]', WEIGHT);
        });

        it('does not read the items again when the import failed', async () => {
            await startFollowing([record('i1', 'failed', { error: 'Some rows are not valid.' })]);

            await vi.advanceTimersByTimeAsync(2000);

            expect(itemReads()).toHaveLength(0);
            expect(component.importFailed).toBe(true);
            expect(component.importStatus).toBe('The import failed.');
        });

        it('shows why an import failed, and the rows of the file that could not be read', async () => {
            await startFollowing([
                record('i1', 'failed', {
                    error: 'Some rows are not valid, so nothing was imported.',
                    rows_failed: 130,
                    errors: [
                        { row: 4, column: 'Weight', message: 'Not a number.' },
                        { row: 9, column: 'Created on', message: 'Not a date.' },
                    ],
                }),
            ]);

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.importResultTitle).toBe('Import failed');
            expect(component.importFailureText).toBe('Some rows are not valid, so nothing was imported.');
            expect(component.importResultLines).toEqual(['130 rows are not valid']);
            expect(component.hasImportReport).toBe(true);
            expect(component.importReport.map((line) => [line.row, line.column, line.message])).toEqual([
                [4, 'Weight', 'Not a number.'],
                [9, 'Created on', 'Not a date.'],
            ]);
            expect(component.importReportNote).toBe('Only the first 2 rows in error out of 130 are listed.');
        });

        it('shows the rows that were left out of an import that was completed', async () => {
            await startFollowing([
                completed('i1', {
                    rows_imported: 10,
                    rows_failed: 2,
                    errors: [{ row: 5, column: 'Weight', message: 'Not a number.' }],
                }),
            ]);

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.importResultLines).toEqual([
                '10 items imported',
                '1 field created',
                '2 rows skipped because of errors',
            ]);
            expect(component.hasImportReport).toBe(true);
        });

        it('offers to try again when the import cannot be read, and carries on when it can', async () => {
            await startFollowing([new HttpError(500, 'Down.'), completed()]);

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.importFollowFailed).toBe(true);
            expect(notify).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(1);

            await component.retryImport();

            // The reading the person asked for is not silent: the helper tells them if it fails too.
            expect(pollsOf('i1').at(-1)[1]).toEqual(expect.objectContaining({ silent: false }));
            expect(component.importFollowFailed).toBe(false);
            expect(component.importCompleted).toBe(true);
        });

        it('does not hide a bug behind the offer to try again', async () => {
            await startFollowing([record('i1', 'running')]);
            const bug = new TypeError('boom');

            vi.spyOn(component, 'followImport').mockImplementation(() => {
                throw bug;
            });

            await expect(component.readImport()).rejects.toBe(bug);
            expect(component.importFollowFailed).toBe(false);
        });

        it('says nothing of a reading that was cancelled', async () => {
            await startFollowing([record('i1', 'running')]);
            http.get.mockImplementationOnce(async () => {
                throw new DOMException('The operation was aborted.', 'AbortError');
            });

            await expect(component.readImport()).resolves.toBeUndefined();
            expect(component.importFollowFailed).toBe(false);
            expect(component.importOnFile).toBe(false);
        });

        it('asks for a file again when the import is gone', async () => {
            await startFollowing([new HttpError(404, 'Gone.')]);

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.importOnFile).toBe(true);
            expect(component.importRecord).toBeNull();
            expect(component.importError).toBe('This import is no longer available. Choose the file again.');
        });

        it('hands the import to the slower watch when the dialog closes', async () => {
            await startFollowing([record('i1', 'running')]);
            await vi.advanceTimersByTimeAsync(2000);

            expect(pollsOf('i1')).toHaveLength(1);

            component.onImportHidden();
            await vi.advanceTimersByTimeAsync(4999);

            expect(pollsOf('i1')).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(1);

            expect(pollsOf('i1')).toHaveLength(2);
        });

        it('stops reading when the page is taken apart, open or closed', async () => {
            await startFollowing([record('i1', 'running')]);
            await vi.advanceTimersByTimeAsync(2000);

            expect(pollsOf('i1')).toHaveLength(1);

            component.destroy();
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(1);

            // The last event of the dialog may come after the page went: it starts nothing.
            component.onImportHidden();
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(1);
        });

        it('shows the message of the server for whatever made the import fail', async () => {
            await startFollowing([
                record('i1', 'failed', { error: 'The file holds more rows than an import can take.' }),
            ]);

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.importResultTitle).toBe('Import failed');
            expect(component.importFailureText).toBe('The file holds more rows than an import can take.');
        });

        it('says the import failed when the server gives no reason', async () => {
            await startFollowing([record('i1', 'failed', { error: null })]);

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.importFailureText).toBe('The import failed.');
        });

        it('drops the answer of a reading that was cancelled', async () => {
            const answer = Promise.withResolvers();

            await startFollowing([record('i1', 'running')]);
            http.get.mockImplementation(async (url) => (url.startsWith(`${IMPORTS}/`) ? answer.promise : { data: [] }));

            await vi.advanceTimersByTimeAsync(2000);
            component.onImportHidden();
            answer.resolve({ data: completed() });
            await vi.advanceTimersByTimeAsync(0);

            expect(component.importRecord).toBeNull();
            expect(itemReads()).toHaveLength(0);
        });

        it('goes back to the choice of a file once the import has ended', async () => {
            await startFollowing([completed()]);
            await vi.advanceTimersByTimeAsync(2000);

            component.restartImport();

            expect(component.importOnFile).toBe(true);
            expect(component.importRecord).toBeNull();
            expect(component.importError).toBe('');
        });
    });

    describe('watching the import while the dialog is closed', () => {
        beforeEach(() => {
            vi.useFakeTimers();
        });

        /** Opens the dialog on an import a worker has, with the answers of the polls that follow, then closes it. */
        async function closeWhileRunning(polls, list = [[record('i1', 'running')]]) {
            mount({ list, polls });
            await component.openImport();
            component.onImportHidden();
        }

        it('asks every five seconds, silently, until the import has ended, then reads the grid once and tells with a toast', async () => {
            await closeWhileRunning([
                record('i1', 'running'),
                record('i1', 'running'),
                completed('i1', { rows_imported: 7 }),
            ]);

            await vi.advanceTimersByTimeAsync(4999);

            expect(pollsOf('i1')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(1);

            expect(pollsOf('i1')).toHaveLength(1);
            expect(pollsOf('i1')[0][1]).toEqual(expect.objectContaining({ silent: true }));

            await vi.advanceTimersByTimeAsync(5000);

            expect(pollsOf('i1')).toHaveLength(2);
            expect(itemReads()).toHaveLength(0);
            expect(notify).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(5000);

            expect(pollsOf('i1')).toHaveLength(3);
            expect(notify).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('success', 'Import finished: 7 items imported.');

            const urls = http.get.mock.calls.map((call) => call[0]);

            expect(urls.filter((url) => url === INVENTORY)).toHaveLength(1);
            expect(urls.filter((url) => url === ITEMS)).toHaveLength(1);
            expect(urls.indexOf(INVENTORY)).toBeLessThan(urls.indexOf(ITEMS));
            expect(http.get.mock.calls.find((call) => call[0] === ITEMS)[1].params).toHaveProperty('page', 1);

            // It ended: nothing is asked any more, and the grid is not read again.
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(3);
            expect(itemReads()).toHaveLength(1);
        });

        it('tells with the message of the server when the import failed, and does not read the grid', async () => {
            await closeWhileRunning([
                record('i1', 'failed', { error: 'The file holds more rows than an import can take.' }),
            ]);

            await vi.advanceTimersByTimeAsync(5000);

            expect(notify).toHaveBeenCalledTimes(1);
            expect(notify).toHaveBeenCalledWith('danger', 'The file holds more rows than an import can take.');
            expect(itemReads()).toHaveLength(0);
        });

        it('says the import failed when the server gives no reason', async () => {
            await closeWhileRunning([record('i1', 'failed', { error: null })]);

            await vi.advanceTimersByTimeAsync(5000);

            expect(notify).toHaveBeenCalledWith('danger', 'The import failed.');
        });

        it('has one question on its way at a time', async () => {
            await closeWhileRunning([record('i1', 'running')]);
            const unanswered = Promise.withResolvers();

            http.get.mockImplementation(async (url) => (url === `${IMPORTS}/i1` ? unanswered.promise : { data: [] }));
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(1);

            unanswered.resolve({ data: record('i1', 'running') });
            await vi.advanceTimersByTimeAsync(5000);

            expect(pollsOf('i1')).toHaveLength(2);
        });

        it('cancels the question on its way when the page is taken apart, and asks nothing after', async () => {
            await closeWhileRunning([record('i1', 'running')]);
            let signal = null;

            http.get.mockImplementation(async (url, options) => {
                signal = options.signal;

                return new Promise(() => {});
            });
            await vi.advanceTimersByTimeAsync(5000);

            expect(signal.aborted).toBe(false);

            component.destroy();

            expect(signal.aborted).toBe(true);

            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(1);
        });

        it('starts no watch for a request that answers after the page was taken apart', async () => {
            mount({ list: [[uploaded()]] });
            await component.openImport();
            const starting = Promise.withResolvers();

            http.post.mockReturnValue(starting.promise);
            answerPolls('i1', record('i1', 'running'));
            const sending = component.startImport();

            component.onImportHidden();
            component.destroy();
            starting.resolve({ data: record('i1', 'pending') });
            await sending;
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(0);
        });

        it('hands the import to the dialog when it opens, and takes it back when it closes', async () => {
            await closeWhileRunning([record('i1', 'running')]);
            await vi.advanceTimersByTimeAsync(5000);

            expect(pollsOf('i1')).toHaveLength(1);

            await component.openImport();

            expect(component.importOnResult).toBe(true);

            // Open: every two seconds, and only the dialog asks.
            await vi.advanceTimersByTimeAsync(2000);
            await vi.advanceTimersByTimeAsync(2000);

            expect(pollsOf('i1')).toHaveLength(3);

            component.onImportHidden();
            await vi.advanceTimersByTimeAsync(4999);

            expect(pollsOf('i1')).toHaveLength(3);

            await vi.advanceTimersByTimeAsync(1);

            expect(pollsOf('i1')).toHaveLength(4);
        });

        it('does not read the grid again when the dialog opens on the end of an import the toast already told', async () => {
            await closeWhileRunning([completed('i1')], [[record('i1', 'running')], [completed('i1')]]);
            await vi.advanceTimersByTimeAsync(5000);

            expect(itemReads()).toHaveLength(1);
            expect(notify).toHaveBeenCalledTimes(1);

            await component.openImport();
            await vi.advanceTimersByTimeAsync(0);

            // The dialog still opens on the result, to read the report.
            expect(component.importOnResult).toBe(true);
            expect(component.importCompleted).toBe(true);
            expect(itemReads()).toHaveLength(1);
            expect(notify).toHaveBeenCalledTimes(1);
        });

        it('asks again after a failure that may pass', async () => {
            await closeWhileRunning([
                new HttpError(500, 'Down.'),
                new HttpError(0, 'Offline.', { kind: 'network' }),
                new HttpError(429, 'Slow down.'),
                completed('i1'),
            ]);

            await vi.advanceTimersByTimeAsync(15_000);

            expect(pollsOf('i1')).toHaveLength(3);
            expect(notify).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(5000);

            expect(pollsOf('i1')).toHaveLength(4);
            expect(notify).toHaveBeenCalledWith('success', 'Import finished: 12 items imported.');
        });

        it.each([404, 403])('gives up, quietly, on an import that answers %i', async (status) => {
            await closeWhileRunning([new HttpError(status, 'No.')], [[record('i1', 'running')], []]);

            await vi.advanceTimersByTimeAsync(5000);
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(1);
            expect(notify).not.toHaveBeenCalled();
            expect(itemReads()).toHaveLength(0);

            await component.openImport();

            expect(component.importOnFile).toBe(true);
        });

        it('watches nothing that waits for its mapping, or that ended in front of the person', async () => {
            await closeWhileRunning([record('i1', 'running')], [[uploaded('i1')]]);
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(0);

            component.onImportHidden();
            mount({ list: [[completed('i2')]] });
            await component.openImport();
            component.onImportHidden();
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i2')).toHaveLength(0);
            expect(notify).not.toHaveBeenCalled();
        });

        it('forgets an import that is no longer listed when the dialog opens, and does not watch it afterwards', async () => {
            await closeWhileRunning([record('i1', 'running')], [[record('i1', 'running')], []]);

            await component.openImport();

            expect(component.importOnFile).toBe(true);

            component.onImportHidden();
            await vi.advanceTimersByTimeAsync(60_000);

            expect(pollsOf('i1')).toHaveLength(0);
        });
    });

    describe('going on with an import the person left', () => {
        it('opens on the mapping of an import that waits for it', async () => {
            mount({ list: [[uploaded('i1')]] });

            await component.openImport();

            expect(component.importOnMapping).toBe(true);
            expect(component.importMappingRows).toHaveLength(4);
            expect(component.importDelimiter).toBe('semicolon');
            expect(http.post).not.toHaveBeenCalled();
        });

        it('opens on the most recent import that has not ended', async () => {
            mount({
                list: [
                    [
                        uploaded('old', { created_at: '2026-10-15T08:00:00Z' }),
                        uploaded('new', { created_at: '2026-10-15T09:00:00Z' }),
                        completed('done', { created_at: '2026-10-15T11:00:00Z' }),
                    ],
                ],
            });

            await component.openImport();

            expect(component.importRecord.id).toBe('new');
        });

        it('opens on the progress of an import that is on its way, and follows it', async () => {
            vi.useFakeTimers();
            mount({ list: [[record('i1', 'running')]], polls: [completed()] });

            await component.openImport();

            expect(component.importOnResult).toBe(true);
            expect(component.importWaiting).toBe(true);

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.importCompleted).toBe(true);
        });

        it('opens on the file when every import has ended', async () => {
            mount({ list: [[completed('done'), record('bad', 'failed')]] });

            await component.openImport();

            expect(component.importOnFile).toBe(true);
            expect(itemReads()).toHaveLength(0);
        });

        it('shows the end of the import the person was waiting for when they closed the dialog, and reads the items', async () => {
            vi.useFakeTimers();
            mount({ list: [[record('i1', 'running')], [completed('i1')]], polls: [record('i1', 'running')] });
            await component.openImport();

            component.onImportHidden();
            await component.openImport();

            expect(component.importOnResult).toBe(true);
            expect(component.importCompleted).toBe(true);
            expect(component.importStatus).toBe('Import finished: 12 items imported.');
            await vi.advanceTimersByTimeAsync(0);
            expect(itemReads()).toHaveLength(1);

            // It is shown once: the next opening asks for a file.
            component.onImportHidden();
            mount({ list: [[completed('i1')]] });
            await component.openImport();

            expect(component.importOnFile).toBe(true);
        });

        it('reads the items again once for an import, however many times its end is shown', async () => {
            mount({ list: [[completed('i1')]] });
            component.fields = FIELDS;

            component.showImport(completed('i1'));
            component.showImport(completed('i1'));
            await vi.waitFor(() => expect(itemReads()).toHaveLength(1));

            expect(itemReads()).toHaveLength(1);
        });
    });

    describe('cancelling the import', () => {
        beforeEach(async () => {
            http.post.mockResolvedValue({ data: uploaded() });
            mount();
            await component.openImport();
            await component.onImportFile(picked(csv()));
        });

        it('deletes the import and asks for a file again', async () => {
            http.delete.mockResolvedValue({});

            await component.cancelImport();

            expect(http.delete).toHaveBeenCalledWith(`${IMPORTS}/i1`);
            expect(component.importOnFile).toBe(true);
            expect(component.importRecord).toBeNull();
            expect(component.importRows).toEqual([]);
            expect(component.importStatus).toBe('Import cancelled.');
            expect(component.importWorking).toBe(false);
        });

        it('starts over with the options the language asks for', async () => {
            http.delete.mockResolvedValue({});
            component.importOnError = 'skip';
            component.importDecimal = 'point';

            await component.cancelImport();

            expect(component.importOnError).toBe('abort');
        });

        it('gives up an import that is already gone', async () => {
            http.delete.mockRejectedValue(new HttpError(404, 'Gone.'));

            await component.cancelImport();

            expect(component.importOnFile).toBe(true);
            expect(component.importError).toBe('');
        });

        it('says the import has started when it cannot be deleted any more', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Running.', { code: 'import_running' }));

            await component.cancelImport();

            expect(component.importError).toBe('This import has already started and can no longer be changed.');
            expect(component.importRecord).not.toBeNull();
        });

        it('does nothing while a request is on its way', async () => {
            const deleting = Promise.withResolvers();

            http.delete.mockReturnValue(deleting.promise);
            const first = component.cancelImport();

            expect(component.importCancelling).toBe(true);

            await component.cancelImport();
            deleting.resolve({});
            await first;

            expect(http.delete).toHaveBeenCalledTimes(1);
        });
    });

    describe('the live region and the language', () => {
        it('expects the decimals with a comma and the day first in French', async () => {
            http.post.mockResolvedValue({
                data: uploaded('i1', { options: { delimiter: 'comma', encoding: 'utf-8', has_header: true } }),
            });
            mount();
            document.documentElement.lang = 'fr';
            await component.openImport();

            await component.onImportFile(picked(csv()));

            expect(component.importDecimal).toBe('comma');
            expect(component.importDateOrder).toBe('dmy');
        });

        it('says the same sentence twice when it is asked to', async () => {
            mount();
            component.announceImport('Same.');
            expect(component.importStatus).toBe('Same.');

            component.announceImport('Same.');
            expect(component.importStatus).toBe('Same.');
        });
    });
});
