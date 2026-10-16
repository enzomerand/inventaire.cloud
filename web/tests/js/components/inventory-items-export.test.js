/**
 * The export dialog of the page of an inventory, at the level of the component: what it asks the API for, with which body,
 * how the list of exports follows the ones that are on their way, and what a refusal leaves.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { toBody, toParams } from '../../../resources/js/grid/query.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { hideModal, onModalHidden, showModal } from '../../../resources/js/lib/modal.js';
import { INVENTORY, deferred, mountComponent, ulid } from '../support/inventory.js';
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

const EXPORTS = `${INVENTORY}/exports`;

/** An export as the API describes it: finished, with its file, unless the test says otherwise. */
function record(id, extra = {}) {
    return {
        id,
        format: 'xlsx',
        scope: 'all',
        status: 'completed',
        error: null,
        file_name: `tools-${id}.xlsx`,
        size: 2048,
        items_count: 3,
        created_at: '2026-10-15T10:00:00Z',
        completed_at: '2026-10-15T10:00:05Z',
        expires_at: '2026-10-16T10:00:00Z',
        download_url: `${EXPORTS}/${id}/download`,
        ...extra,
    };
}

/** An export that has not ended: no file, no size, nothing counted. */
function onItsWay(id, status = 'pending', extra = {}) {
    return record(id, {
        status,
        file_name: null,
        size: null,
        items_count: null,
        completed_at: null,
        expires_at: null,
        download_url: null,
        ...extra,
    });
}

/** A text with the no-break spaces `Intl` writes turned into plain ones. */
const plain = (text) => text.replace(/[\u00a0\u202f]/g, ' ');

/** Writes a nested body back the way a query string writes it: `filter[<field>][in][0]`. */
function flatten(value, prefix = '') {
    return Object.entries(value).flatMap(([key, one]) => {
        const name = prefix === '' ? key : `${prefix}[${key}]`;

        return typeof one === 'object' ? flatten(one, name) : [[name, one]];
    });
}

describe('the export dialog', () => {
    let component;

    /** The readings of the list so far. */
    const reads = () => http.get.mock.calls.filter((call) => call[0] === EXPORTS);

    /** Mounts the component with a dialog, and says what each reading of the list answers (the last answer repeats). */
    function mount(answers = [[]], options = {}) {
        component = mountComponent(inventoryItems, options);
        component.$refs.exportModal = document.createElement('div');
        component.$refs.exportSubmit = document.createElement('button');

        const queue = answers.map((one) => (one instanceof Error ? one : { data: one }));

        http.get.mockImplementation(async (url) => {
            if (url !== EXPORTS) {
                throw new Error(`unexpected reading of ${url}`);
            }

            const next = queue.length > 1 ? queue.shift() : queue[0];

            if (next instanceof Error) {
                throw next;
            }

            return next;
        });
    }

    /** The body the last request to start an export carried. */
    const lastBody = () => http.post.mock.calls.at(-1)[1];

    beforeEach(() => {
        http.get.mockReset();
        http.post.mockReset();
        http.delete.mockReset();
        forgetDialogs();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('opening it', () => {
        it('shows the dialog with the cursor on the format that is chosen, and reads the exports', async () => {
            mount([[record('e1')]]);

            await component.openExport();

            expect(showModal).toHaveBeenCalledWith(component.$refs.exportModal, {
                focus: ['input[name="export-format"]:checked'],
            });
            expect(http.get).toHaveBeenCalledWith(EXPORTS, expect.objectContaining({ silent: false }));
            expect(component.exportOpen).toBe(true);
            expect(component.exportList.map((one) => one.id)).toEqual(['e1']);
            expect(component.exportsLoaded).toBe(true);
            expect(component.exportsLoading).toBe(false);
        });

        it('starts on an Excel file of all the items', () => {
            mount();

            expect(component.exportFormat).toBe('xlsx');
            expect(component.effectiveExportScope).toBe('all');
            expect(component.exportFormats.map((format) => format.id)).toEqual([
                'xlsx',
                'ods',
                'csv',
                'json',
                'pdf',
                'zip',
            ]);
            expect(component.exportFormats[0]).toMatchObject({ label: 'Excel (.xlsx)', inputId: 'export-format-xlsx' });
        });

        it('says the list is empty when there is no export', async () => {
            mount([[]]);

            expect(component.noExports).toBe(false);

            await component.openExport();

            expect(component.noExports).toBe(true);
            expect(component.hasExports).toBe(false);
        });

        it('offers to try again when the list cannot be read, then reads it', async () => {
            mount([new HttpError(500, 'Down.'), [record('e1')]]);

            await component.openExport();

            expect(component.exportsFailed).toBe(true);
            expect(component.noExports).toBe(false);
            expect(component.exportsLoading).toBe(false);

            await component.loadExports();

            expect(component.exportsFailed).toBe(false);
            expect(component.hasExports).toBe(true);
        });

        it('listens to the closing of the dialog once, however many times it opens', async () => {
            mount([[]]);

            await component.openExport();
            dismiss(component.$refs.exportModal);
            await vi.waitFor(() => expect(component.exportOpen).toBe(false));
            await component.openExport();
            dismiss(component.$refs.exportModal);
            await vi.waitFor(() => expect(component.exportOpen).toBe(false));

            expect(onModalHidden.mock.calls.filter((call) => call[0] === component.$refs.exportModal)).toHaveLength(1);
        });

        it('forgets the list and what was said when it closes', async () => {
            mount([[record('e1')]]);
            await component.openExport();
            component.exportError = 'Nope.';
            component.exportStatus = 'Done.';

            dismiss(component.$refs.exportModal);
            await vi.waitFor(() => expect(component.exportOpen).toBe(false));

            expect(component.exportList).toEqual([]);
            expect(component.exportsLoaded).toBe(false);
            expect(component.exportError).toBe('');
            expect(component.exportStatus).toBe('');
        });
    });

    describe('the separator of a CSV file', () => {
        it('is the comma when the page is in English', async () => {
            mount();
            document.documentElement.lang = 'en';

            await component.openExport();

            expect(component.exportDelimiter).toBe('comma');
        });

        it.each([['fr'], ['fr-CA']])('is the semicolon when the page is in %s', async (lang) => {
            mount();
            document.documentElement.lang = lang;

            await component.openExport();

            expect(component.exportDelimiter).toBe('semicolon');
        });

        it('keeps what the person chose when the dialog opens again', async () => {
            mount();
            document.documentElement.lang = 'fr';
            await component.openExport();
            component.exportDelimiter = 'tab';

            dismiss(component.$refs.exportModal);
            await vi.waitFor(() => expect(component.exportOpen).toBe(false));
            await component.openExport();

            expect(component.exportDelimiter).toBe('tab');
        });

        it('is offered with a CSV file only, and the files with a ZIP only', () => {
            mount();

            expect([component.showDelimiter, component.showFiles]).toEqual([false, false]);

            component.exportFormat = 'csv';
            expect([component.showDelimiter, component.showFiles]).toEqual([true, false]);

            component.exportFormat = 'zip';
            expect([component.showDelimiter, component.showFiles]).toEqual([false, true]);
        });
    });

    describe('the items to export', () => {
        it('cannot be the view while nothing narrows or orders the list down', () => {
            mount();
            component.total = 12;

            expect(component.viewScopeAvailable).toBe(false);
            expect(component.viewScopeHint).toBe('Search, filter or sort the items first to export only that view.');
        });

        it.each([
            ['a search', { q: 'saw' }],
            ['a filter', { filters: { [ulid('qty')]: { min: '1' } } }],
            ['a sort', { sort: 'created_at', direction: 'desc' }],
        ])('can be the view once %s is in force, and the hint counts its items', (_name, change) => {
            mount();
            component.query = { ...component.query, ...change };
            component.total = 12;

            expect(component.viewScopeAvailable).toBe(true);
            expect(component.viewScopeHint).toBe('12 items, as the list shows them.');

            component.total = 1;

            expect(component.viewScopeHint).toBe('1 item, as the list shows it.');
        });

        it('go back to all of them when the view was chosen and has been left since', async () => {
            mount();
            component.query = { ...component.query, q: 'saw' };
            component.exportScope = 'view';

            await component.openExport();

            expect(component.exportScope).toBe('view');

            dismiss(component.$refs.exportModal);
            await vi.waitFor(() => expect(component.exportOpen).toBe(false));
            component.query = { ...component.query, q: '' };
            await component.openExport();

            expect(component.exportScope).toBe('all');
        });
    });

    describe('asking for an export', () => {
        beforeEach(async () => {
            mount([[]]);
            http.post.mockResolvedValue({ data: onItsWay('new') });
            await component.openExport();
        });

        it.each([
            ['xlsx', { format: 'xlsx', scope: 'all' }],
            ['ods', { format: 'ods', scope: 'all' }],
            ['json', { format: 'json', scope: 'all' }],
            ['pdf', { format: 'pdf', scope: 'all' }],
        ])('sends the format and the items alone for %s', async (format, body) => {
            component.exportFormat = format;
            // What the options of the other formats hold means nothing here.
            component.exportDelimiter = 'tab';
            component.exportFiles = 'all';

            await component.submitExport();

            expect(http.post).toHaveBeenCalledWith(EXPORTS, body);
        });

        it.each([['semicolon'], ['comma'], ['tab']])('sends the %s with a CSV file', async (delimiter) => {
            component.exportFormat = 'csv';
            component.exportDelimiter = delimiter;

            await component.submitExport();

            expect(lastBody()).toEqual({ format: 'csv', scope: 'all', delimiter });
        });

        it.each([['photos'], ['all']])('sends the choice of files (%s) with a ZIP', async (files) => {
            component.exportFormat = 'zip';
            component.exportFiles = files;

            await component.submitExport();

            expect(lastBody()).toEqual({ format: 'zip', scope: 'all', files });
        });

        it('sends the view as the very parameters of the list, without the page nor the totals', async () => {
            component.query = {
                q: 'claw hammer',
                sort: 'price',
                direction: 'desc',
                filters: {
                    [ulid('price')]: { min: '10', max: '50.50' },
                    [ulid('state')]: { in: ['aaaaaaaa', 'bbbbbbbb'] },
                    [ulid('fragile')]: { is: false },
                    [ulid('photo')]: { empty: true },
                },
            };
            component.exportScope = 'view';

            await component.submitExport();

            expect(lastBody()).toEqual({ format: 'xlsx', scope: 'view', query: toBody(component.query) });
            // Whatever the list is read with, the view is exactly that: no page, no totals.
            expect(Object.fromEntries(flatten(lastBody().query))).toEqual(toParams(component.query));
            expect(lastBody().query).toEqual({
                q: 'claw hammer',
                sort: 'price',
                direction: 'desc',
                filter: {
                    [ulid('price')]: { min: '10', max: '50.50' },
                    [ulid('state')]: { in: ['aaaaaaaa', 'bbbbbbbb'] },
                    [ulid('fragile')]: { is: 'false' },
                    [ulid('photo')]: { empty: 'true' },
                },
            });
            expect(lastBody().query).not.toHaveProperty('page');
            expect(lastBody().query).not.toHaveProperty('totals');
        });

        it('sends a view that is only a sort', async () => {
            component.query = { ...component.query, sort: 'updated_at' };
            component.exportScope = 'view';

            await component.submitExport();

            expect(lastBody()).toEqual({ format: 'xlsx', scope: 'view', query: { sort: 'updated_at' } });
        });

        it('asks for all the items when the view was chosen and there is no view to speak of', async () => {
            component.exportScope = 'view';

            await component.submitExport();

            expect(lastBody()).toEqual({ format: 'xlsx', scope: 'all' });
        });

        it('adds the export to the top of the list at once, tells it, and keeps the dialog open', async () => {
            await component.submitExport();

            expect(component.exportList.map((one) => one.id)).toEqual(['new']);
            expect(component.exportStatus).toBe('Export started. It will be ready in a moment.');
            expect(component.exportOpen).toBe(true);
            expect(hideModal).not.toHaveBeenCalled();
            expect(component.exportBusy).toBe(false);
            expect(component.exportRows[0]).toMatchObject({
                title: 'Excel (.xlsx)',
                statusLabel: 'Waiting',
                ready: false,
                url: null,
            });
        });

        it('is busy while the request is on its way, and does not send it twice', async () => {
            const sent = deferred();

            http.post.mockReturnValue(sent.promise);

            const first = component.submitExport();
            const second = component.submitExport();

            expect(component.exportBusy).toBe(true);

            sent.resolve({ data: onItsWay('new') });
            await Promise.all([first, second]);

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.exportBusy).toBe(false);
        });

        it('tells with a toast when the dialog was closed while the request was on its way', async () => {
            const sent = deferred();

            http.post.mockReturnValue(sent.promise);

            const request = component.submitExport();

            dismiss(component.$refs.exportModal);
            await vi.waitFor(() => expect(component.exportOpen).toBe(false));
            sent.resolve({ data: onItsWay('new') });
            await request;

            expect(notify).toHaveBeenCalledWith('success', 'Export started. It will be ready in a moment.');
            expect(component.exportList).toEqual([]);
        });
    });

    describe('when an export is refused', () => {
        beforeEach(async () => {
            mount([[]]);
            await component.openExport();
        });

        it('says the person has too many exports for the code the API gives', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Something went wrong.', { code: 'export_limit_reached' }));

            await component.submitExport();

            expect(component.exportError).toBe(
                'You already have too many exports of this inventory. Delete some, then try again.',
            );
            expect(component.exportList).toEqual([]);
            expect(component.exportBusy).toBe(false);
        });

        it('says the exports take too much room for the code the API gives, whatever it wrote itself', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'The API wrote this in its own words.', { code: 'export_storage_full' }),
            );

            await component.submitExport();

            expect(component.exportError).toBe('Your exports take too much room. Delete some, then try again.');
            expect(component.exportList).toEqual([]);
            expect(component.exportBusy).toBe(false);
        });

        it('tells another 409 in the words of the API', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Something else.', { code: 'something_else' }));

            await component.submitExport();

            expect(component.exportError).toBe('Something else.');
        });

        it('says what the API objects to in the format (a PDF that is too big, a ZIP with no file)', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: { format: ['This inventory has too many items for a PDF.', 'Try a spreadsheet.'] },
                }),
            );

            await component.submitExport();

            expect(component.exportError).toBe('This inventory has too many items for a PDF.');
        });

        it('says every refused option once, and the message of the refusal when it names none', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: {
                        delimiter: ['Pick a separator.'],
                        files: ['Pick a separator.'],
                        scope: ['Pick the items.'],
                    },
                }),
            );

            await component.submitExport();

            expect(component.exportError).toBe('Pick a separator. Pick the items.');

            http.post.mockRejectedValue(new HttpError(422, 'The data is invalid.'));

            await component.submitExport();

            expect(component.exportError).toBe('The data is invalid.');
        });

        it('says that the person asked too often, which the helper only toasts', async () => {
            const throttled = new HttpError(429, 'Too many attempts. Please wait a moment before trying again.');

            throttled.reported = true;
            http.post.mockRejectedValue(throttled);

            await component.submitExport();

            expect(component.exportError).toBe('Too many attempts. Please wait a moment before trying again.');
        });

        it('says nothing more of what the helper already reported (the network, the server)', async () => {
            const down = new HttpError(500, 'The server hit an error.');

            down.reported = true;
            http.post.mockRejectedValue(down);

            await component.submitExport();

            expect(component.exportError).toBe('');
            expect(component.exportBusy).toBe(false);
        });

        it('says what the helper left alone', async () => {
            http.post.mockRejectedValue(new HttpError(403, 'You are not allowed to do this.'));

            await component.submitExport();

            expect(component.exportError).toBe('You are not allowed to do this.');
        });

        it('says something went wrong when it is not an error of the API', async () => {
            http.post.mockRejectedValue(new TypeError('boom'));

            await component.submitExport();

            // The mocked messages know no "error.generic": the key stands for the message.
            expect(component.exportError).toBe('error.generic');
        });

        it('forgets the refusal when the person asks again', async () => {
            http.post.mockRejectedValueOnce(new HttpError(409, 'No.', { code: 'export_limit_reached' }));
            http.post.mockResolvedValueOnce({ data: onItsWay('new') });

            await component.submitExport();
            await component.submitExport();

            expect(component.exportError).toBe('');
            expect(component.exportList).toHaveLength(1);
        });
    });

    describe('the list of exports', () => {
        it('tells each export: its format, its items, when, how many, how big, until when, and its file', async () => {
            mount([
                [
                    record('e1'),
                    record('e2', { format: 'csv', scope: 'view', size: 5, items_count: 1 }),
                    onItsWay('e3', 'running', { format: 'pdf' }),
                    onItsWay('e4', 'failed', { error: 'The export could not be made.' }),
                ],
            ]);
            await component.openExport();

            const rows = component.exportRows;

            expect(rows.map((row) => row.title)).toEqual(['Excel (.xlsx)', 'CSV', 'PDF', 'Excel (.xlsx)']);
            expect(rows.map((row) => row.statusLabel)).toEqual(['Ready', 'Ready', 'In progress', 'Failed']);
            expect(rows[0].details).toContain('All items');
            expect(rows[0].details).toContain('3 items');
            expect(plain(rows[0].details)).toContain('2 kB');
            expect(rows[0].details).toContain('Available until');
            expect(rows[1].details).toContain('Current view');
            expect(rows[1].details).toContain('1 item');
            expect(plain(rows[1].details)).toContain('5 byte');
            expect(rows[2].details).not.toContain('Available until');
            expect(rows[2].details).not.toMatch(/\d+ items?/);
            expect(rows.map((row) => row.error)).toEqual(['', '', '', 'The export could not be made.']);
        });

        it('links only the exports that are ready to their file, named by it', async () => {
            mount([[record('e1'), onItsWay('e2'), record('e3', { download_url: null })]]);
            await component.openExport();

            const [ready, waiting, lost] = component.exportRows;

            expect(ready).toMatchObject({
                ready: true,
                url: `${EXPORTS}/e1/download`,
                downloadLabel: 'Download tools-e1.xlsx',
            });
            expect(waiting).toMatchObject({ ready: false, url: null });
            expect(lost).toMatchObject({ ready: false, url: null });
        });

        it('names the button that deletes an export by its format and its date', async () => {
            mount([[record('e1')]]);
            await component.openExport();

            expect(component.exportRows[0].deleteLabel).toMatch(/^Delete the Excel \(\.xlsx\) export of /);
        });

        it('uses the style of its status for the badge', async () => {
            mount([[onItsWay('a'), onItsWay('b', 'running'), record('c'), onItsWay('d', 'failed')]]);
            await component.openExport();

            expect(component.exportRows.map((row) => row.badgeClass)).toEqual([
                'bg-secondary-lt',
                'bg-blue-lt',
                'bg-green-lt',
                'bg-red-lt',
            ]);
        });
    });

    describe('following the exports that are on their way', () => {
        beforeEach(() => {
            vi.useFakeTimers();
        });

        it('reads the list again every two seconds while an export has not ended, and silently', async () => {
            mount([[onItsWay('e1')], [onItsWay('e1', 'running')], [record('e1')]]);

            await component.openExport();

            expect(reads()).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(1999);

            expect(reads()).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(1);

            expect(reads()).toHaveLength(2);
            expect(reads()[1][1]).toEqual(expect.objectContaining({ silent: true }));
            expect(component.exportRows[0].statusLabel).toBe('In progress');

            await vi.advanceTimersByTimeAsync(2000);

            expect(reads()).toHaveLength(3);
            expect(component.exportRows[0].statusLabel).toBe('Ready');
        });

        it('stops once every export has ended', async () => {
            mount([[onItsWay('e1')], [record('e1')]]);
            await component.openExport();

            await vi.advanceTimersByTimeAsync(2000);
            await vi.advanceTimersByTimeAsync(60_000);

            expect(reads()).toHaveLength(2);
        });

        it('does not start when the list holds nothing that is on its way', async () => {
            mount([[record('e1'), onItsWay('e2', 'failed')]]);

            await component.openExport();
            await vi.advanceTimersByTimeAsync(60_000);

            expect(reads()).toHaveLength(1);
        });

        it('starts when an export is asked for, and tells the one that ended', async () => {
            mount([[], [onItsWay('new', 'running')], [record('new')]]);
            http.post.mockResolvedValue({ data: onItsWay('new') });
            await component.openExport();
            await vi.advanceTimersByTimeAsync(60_000);

            expect(reads()).toHaveLength(1);

            await component.submitExport();
            await vi.advanceTimersByTimeAsync(2000);
            await vi.advanceTimersByTimeAsync(2000);

            expect(reads()).toHaveLength(3);
            expect(component.exportStatus).toBe('Export ready: tools-new.xlsx');
            await vi.advanceTimersByTimeAsync(60_000);
            expect(reads()).toHaveLength(3);
        });

        it('tells the export that failed', async () => {
            mount([[onItsWay('e1')], [onItsWay('e1', 'failed', { error: 'Broken.' })]]);
            await component.openExport();

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.exportStatus).toBe('An export failed.');
            expect(component.exportRows[0].error).toBe('Broken.');
        });

        it('does not tell an export that was already over, nor one that is new', async () => {
            mount([[record('e1')], [record('e1'), record('e2')]]);
            await component.openExport();
            component.exportList = [...component.exportList, onItsWay('e9')];

            await component.readExports({ silent: true });

            expect(component.exportStatus).toBe('');
        });

        it('stops when the dialog closes, and cancels the reading that is on its way', async () => {
            mount([[onItsWay('e1')]]);
            await component.openExport();

            const pending = deferred();
            let signal;

            http.get.mockImplementation((_url, options) => {
                signal = options.signal;

                return pending.promise;
            });
            await vi.advanceTimersByTimeAsync(2000);

            expect(signal.aborted).toBe(false);

            dismiss(component.$refs.exportModal);
            await vi.advanceTimersByTimeAsync(0);

            expect(signal.aborted).toBe(true);
            expect(component.exportOpen).toBe(false);

            // The answer that comes anyway changes nothing, and nothing is planned.
            pending.resolve({ data: [onItsWay('e1')] });
            await vi.advanceTimersByTimeAsync(60_000);

            expect(component.exportList).toEqual([]);
            expect(reads()).toHaveLength(2);
        });

        it('does not wait for the next reading once the dialog closed', async () => {
            mount([[onItsWay('e1')]]);
            await component.openExport();

            dismiss(component.$refs.exportModal);
            await vi.advanceTimersByTimeAsync(0);
            await vi.advanceTimersByTimeAsync(60_000);

            expect(reads()).toHaveLength(1);
        });

        it('stops when the page is taken apart', async () => {
            mount([[onItsWay('e1')]]);
            await component.openExport();

            component.destroy();
            await vi.advanceTimersByTimeAsync(60_000);

            expect(reads()).toHaveLength(1);
        });

        it('stops when a reading fails, offers to try again, and does not toast each failure', async () => {
            mount([[onItsWay('e1')], new HttpError(500, 'Down.'), [record('e1')]]);
            await component.openExport();

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.exportsFailed).toBe(true);
            // What was on the screen stays.
            expect(component.exportRows).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(60_000);

            expect(reads()).toHaveLength(2);

            await component.loadExports();

            expect(component.exportsFailed).toBe(false);
            expect(component.exportRows[0].statusLabel).toBe('Ready');
        });

        it('reads once at a time: a reading that is slow is not overtaken by the next poll', async () => {
            mount([[onItsWay('e1')]]);
            await component.openExport();

            const slow = deferred();

            http.get.mockImplementation(() => slow.promise);
            await vi.advanceTimersByTimeAsync(2000);
            await vi.advanceTimersByTimeAsync(10_000);

            expect(reads()).toHaveLength(2);

            slow.resolve({ data: [onItsWay('e1')] });
            await vi.advanceTimersByTimeAsync(2000);

            expect(reads()).toHaveLength(3);
        });

        it('drops the answer of a reading that began before an export was asked for, and reads again', async () => {
            mount([[]]);
            await component.openExport();

            const stale = deferred();

            http.get.mockReturnValueOnce(stale.promise);
            http.get.mockResolvedValue({ data: [onItsWay('new')] });
            http.post.mockResolvedValue({ data: onItsWay('new') });

            const reading = component.readExports({ silent: true });

            await component.submitExport();
            // The server answered before it knew of the export.
            stale.resolve({ data: [] });
            await reading;

            expect(component.exportList.map((one) => one.id)).toEqual(['new']);
            expect(reads()).toHaveLength(3);
        });
    });

    describe('deleting an export', () => {
        beforeEach(async () => {
            mount([[record('e1'), record('e2'), record('e3')]]);
            http.delete.mockResolvedValue(null);
            await component.openExport();
        });

        it('asks the API, takes the export out of the list and tells it', async () => {
            await component.removeExport({ id: 'e2' });

            expect(http.delete).toHaveBeenCalledWith(`${EXPORTS}/e2`);
            expect(component.exportList.map((one) => one.id)).toEqual(['e1', 'e3']);
            expect(component.exportStatus).toBe('Export deleted.');
            expect(component.deletingExports).toEqual([]);
        });

        it('puts the cursor on the delete button that took its place, else the one before, else on the button that starts an export', async () => {
            const modal = component.$refs.exportModal;

            modal.innerHTML = '<button data-export-delete></button>'.repeat(3);
            component.$refs.exportSubmit.focus = vi.fn();

            const buttons = [...modal.querySelectorAll('[data-export-delete]')];

            document.body.append(modal);
            buttons.forEach((button) => (button.focus = vi.fn()));

            // The second one: the button that is left in third place is now second.
            http.delete.mockImplementationOnce(async () => {
                buttons[1].remove();
            });
            await component.removeExport({ id: 'e2' });

            expect(modal.querySelectorAll('[data-export-delete]')).toHaveLength(2);
            expect(buttons[2].focus).toHaveBeenCalled();

            // The last one: the one before it.
            http.delete.mockImplementationOnce(async () => {
                buttons[2].remove();
            });
            await component.removeExport({ id: 'e3' });

            expect(buttons[0].focus).toHaveBeenCalled();

            // The only one that is left.
            http.delete.mockImplementationOnce(async () => {
                buttons[0].remove();
            });
            await component.removeExport({ id: 'e1' });

            expect(component.$refs.exportSubmit.focus).toHaveBeenCalled();
            modal.remove();
        });

        it('marks the export while its deletion is on its way, and does not delete it twice', async () => {
            const gone = deferred();

            http.delete.mockReturnValue(gone.promise);

            const first = component.removeExport({ id: 'e1' });
            const second = component.removeExport({ id: 'e1' });

            expect(component.exportRows.map((row) => row.deleting)).toEqual([true, false, false]);

            gone.resolve(null);
            await Promise.all([first, second]);

            expect(http.delete).toHaveBeenCalledTimes(1);
            expect(component.exportRows.map((row) => row.deleting)).toEqual([false, false]);
        });

        it('takes an export that is already gone out of the list all the same', async () => {
            const missing = new HttpError(404, 'This item no longer exists.');

            missing.reported = true;
            http.delete.mockRejectedValue(missing);

            await component.removeExport({ id: 'e1' });

            expect(component.exportList.map((one) => one.id)).toEqual(['e2', 'e3']);
            expect(notify).not.toHaveBeenCalled();
        });

        it('leaves the export in the list and says why when it could not be deleted', async () => {
            http.delete.mockRejectedValue(new HttpError(403, 'You are not allowed to do this.'));

            await component.removeExport({ id: 'e1' });

            expect(component.exportList).toHaveLength(3);
            expect(notify).toHaveBeenCalledWith('danger', 'You are not allowed to do this.');
            expect(component.deletingExports).toEqual([]);
        });

        it('says nothing more of what the helper already reported', async () => {
            const down = new HttpError(500, 'The server hit an error.');

            down.reported = true;
            http.delete.mockRejectedValue(down);

            await component.removeExport({ id: 'e1' });

            expect(component.exportList).toHaveLength(3);
            expect(notify).not.toHaveBeenCalled();
        });

        it('empties the live region before it speaks, so that a sentence repeated is announced again', async () => {
            const spoken = [];
            const later = [];

            component.$nextTick = (callback) => later.push(callback);
            component.exportStatus = 'Export deleted.';

            component.announceExport('Export deleted.');
            spoken.push(component.exportStatus);
            later.forEach((callback) => callback());
            spoken.push(component.exportStatus);

            expect(spoken).toEqual(['', 'Export deleted.']);
        });
    });
});
