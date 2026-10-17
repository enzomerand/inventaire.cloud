/**
 * The import dialog of the page of an inventory: a spreadsheet goes in, its columns are matched with the fields of the
 * inventory (or with fields the import creates), and the rows become items.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`), because what an import does is read in the
 * component: the fields the columns can go to, what the person may do with them (`canManageFields`), and the list to read
 * again when the items have come in (`loadInventory`, `loadItems`).
 *
 * The dialog has three steps, and the API tells in which one an import is (`status`):
 *
 * - the file: it goes up at once when chosen (`POST`), and the answer, which comes back with the file read, is the next step;
 * - the mapping (`uploaded`): one row per column of the file, with the destination the API proposes. The row of the headers,
 *   and the separator and the encoding of a CSV file, change how the file is read, so the API reads it again (`PATCH`) and
 *   the mapping starts over; the decimal sign, the order of the dates and what a wrong row does are sent with the mapping
 *   when the import starts (`POST .../start`);
 * - the result (`pending`, `running`, then `completed` or `failed`): an import is made by a job, so the dialog asks
 *   about it again every two seconds, for as long as it is open and the import has not ended. A poll is silent (the
 *   helper does not toast every failure of a request that comes every two seconds): a failure stops them and the dialog
 *   offers to try again. When the import has ended, the grid is read again.
 *
 * Whatever is on its way is not lost by closing the dialog: it opens again where the import is (the most recent one the
 * API lists that has not ended), or, if the import ended meanwhile, on its result. The import is also watched while the
 * dialog is closed (the dialog says "you can close this window"): a quiet poll every few seconds, one request at a time,
 * until the import ends; the grid is then read again, once, and a toast tells how it ended. The watch is the dialog's
 * poll with another pace, never both at once: opening the dialog hands the import back to it.
 *
 * The source file is deleted by the server as soon as the job is over, so an import that ended cannot be restarted: the
 * person picks the file again.
 *
 * Whatever is said to the person while they cannot see it (a file read, an import started, over) goes to one polite live
 * region that is always in the page.
 */
import { firstMessages } from '../lib/forms.js';
import { formatSize } from '../fields/files.js';
import { HttpError, http, uploadTimeoutFor } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { defaultDelimiter } from '../export/exports.js';
import {
    ACCEPTED_EXTENSIONS,
    BACKGROUND_POLL_INTERVAL_MS,
    NEW_FIELD_TYPES,
    POLL_INTERVAL_MS,
    analysisBody,
    defaultDateOrder,
    defaultDecimal,
    endNotice,
    failureText,
    importableFields,
    isFinished,
    isTooLarge,
    isWaiting,
    mappingRows,
    mappingSummary,
    maxSizeFrom,
    optionsOf,
    parseDestination,
    pickResumable,
    reportNote,
    reportRows,
    resultLines,
    rowView,
    startBody,
    statusTitle,
    validate,
} from './imports.js';

/** The code the API gives to the refusal of a file when the person already has too many imports on their way. */
const LIMIT_CODE = 'import_limit_reached';

/** The code the API gives to a request that changes an import that is not waiting for its mapping any more. */
const NOT_EDITABLE_CODE = 'import_not_editable';

/** The code the API gives to the deletion of an import that a worker is writing. */
const RUNNING_CODE = 'import_running';

/**
 * @typedef {import('./imports.js').ImportRecord} ImportRecord
 * @typedef {import('./imports.js').ImportRow} ImportRow
 */

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function importDialog() {
    // Plumbing that is not state the page shows, so it stays out of the reactive data: the timer of the next poll, the
    // cancellation of the reading that is on its way, and whether the dialog's `hidden` event is listened to yet.
    /** @type {ReturnType<typeof setTimeout> | null} */
    let pollTimer = null;
    /** @type {AbortController | null} */
    let reading = null;
    let hiddenBound = false;
    // The import the person was waiting for when they closed the dialog, until its end has been shown to them in the dialog.
    /** @type {string | null} */
    let unseen = null;
    // The import that is not over and whose end the grid is waiting for, whether the dialog is open or not (null once it
    // has ended, or is gone). While the dialog is closed it is the one that is watched.
    /** @type {string | null} */
    let watched = null;
    // Set when the page is taken apart: nothing is watched any more, even if the dialog's last event comes late.
    let destroyed = false;
    // The imports whose end has already made the grid read its items again.
    const reloaded = new Set();
    // What the decimal sign and the order of the dates were last set to by the dialog itself: a choice of the person is
    // a value that is not that, and is not overwritten when the file is read again. Null before the first analysis.
    /** @type {string | null} */
    let decimalDefault = null;
    /** @type {string | null} */
    let dateOrderDefault = null;

    /**
     * Stops waiting for the next poll and cancels the reading that is on its way.
     *
     * @returns {void}
     */
    function cancelReading() {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        reading?.abort();
        reading = null;
    }

    return {
        /** Whether the dialog is open: an import is followed only then. */
        importOpen: false,

        /** The step: `loading` (the imports are being read), `file`, `mapping` or `result`. */
        importStep: 'loading',

        /** The import the dialog is about, as the API describes it (null while there is none). */
        importRecord: null,

        /** The mapping: one row per column of the file, with what the person decided for it. */
        importRows: [],

        /** Whether the first row of the file holds the names of the columns. */
        importHasHeader: true,

        /** The separator of a CSV file: `semicolon`, `comma` or `tab`. */
        importDelimiter: 'semicolon',

        /** The encoding of a CSV file: `utf-8` or `windows-1252`. */
        importEncoding: 'utf-8',

        /** The sign the decimals of the numbers are written with: `comma` or `point`. */
        importDecimal: 'point',

        /** The order the dates are written in: `dmy`, `mdy` or `ymd`. */
        importDateOrder: 'dmy',

        /** What a row that cannot be read does: `abort` the import, or `skip` the row. */
        importOnError: 'abort',

        /** What the request on its way is for: `upload`, `analyse`, `start` or `cancel` ("" when there is none). */
        importBusy: '',

        /** The name of the file that is going up. */
        importUploadName: '',

        /** Why the last request was refused ("" when it was not). */
        importError: '',

        /** What is wrong with the mapping as a whole, as the last try to start the import found it. */
        importProblems: [],

        /** What is wrong with each column, by its index, as the last try to start the import found it. */
        importRowErrors: {},

        /** What the live region says; changing it is how a screen reader is told. */
        importStatus: '',

        /** True when the last reading of the import on its way failed: the dialog offers to try again. */
        importFollowFailed: false,

        /** Endpoint of the imports of the inventory, from the address of the inventory. */
        get importsUrl() {
            return this.url + '/imports';
        },

        /** The largest file the server takes, in bytes, from `data-import-max-size` on the component root. */
        get importMaxSize() {
            return maxSizeFrom(this.rootElement.dataset.importMaxSize);
        },

        /** The largest file the server takes, as a person reads it. */
        get importMaxSizeLabel() {
            return formatSize(this.importMaxSize);
        },

        /** The `accept` attribute of the file chooser: the extensions of the files the import reads. */
        get importAccept() {
            return ACCEPTED_EXTENSIONS.join(',');
        },

        /** What the file chooser is described by: the formats the import reads, and the largest file it takes. */
        get importFileHint() {
            return t('imports.hint.file', { max: this.importMaxSizeLabel });
        },

        /** Whether the imports are being read: the dialog does not know yet where the person is. */
        get importOnLoading() {
            return this.importStep === 'loading';
        },

        /** Whether the dialog asks for the file. */
        get importOnFile() {
            return this.importStep === 'file';
        },

        /** Whether the dialog asks how the columns of the file are matched. */
        get importOnMapping() {
            return this.importStep === 'mapping';
        },

        /** Whether the dialog tells how the import goes, or how it ended. */
        get importOnResult() {
            return this.importStep === 'result';
        },

        /** Whether a request is on its way: the controls wait. */
        get importWorking() {
            return this.importBusy !== '';
        },

        /** Whether the file is going up. */
        get importUploading() {
            return this.importBusy === 'upload';
        },

        /** Whether the file is being read again. */
        get importAnalysing() {
            return this.importBusy === 'analyse';
        },

        /** Whether the import is being started. */
        get importStarting() {
            return this.importBusy === 'start';
        },

        /** Whether the import is being cancelled. */
        get importCancelling() {
            return this.importBusy === 'cancel';
        },

        /** What the file that is going up says: its name. */
        get importUploadText() {
            return t('imports.uploading', { name: this.importUploadName });
        },

        /** Whether the file is a CSV file: only then does it have a separator and an encoding. */
        get importIsCsv() {
            return this.importRecord?.format === 'csv';
        },

        /** What the file is: its name and its size. */
        get importFileInfo() {
            return this.importRecord === null
                ? ''
                : `${this.importRecord.file_name} · ${formatSize(this.importRecord.size)}`;
        },

        /** The types a field to create can be given, with their names. */
        get importTypeOptions() {
            return NEW_FIELD_TYPES.map((id) => ({ id, label: t(`imports.type.${id}`) }));
        },

        /** The fields of the inventory a column can go to, as the options of the select of a column. */
        get importFieldOptions() {
            return importableFields(this.fields).map((field) => ({
                id: field.id,
                value: `field:${field.id}`,
                name: field.name,
            }));
        },

        /** Whether there is a field a column can go to. */
        get hasImportFields() {
            return this.importFieldOptions.length > 0;
        },

        /** The columns of the file as the dialog shows them, each with what is wrong with it if the last try said so. */
        get importMappingRows() {
            return this.importRows.map((row) => rowView(row, this.importRowErrors[row.index] ?? '', this.fields));
        },

        /** What the mapping comes to: how many columns are imported, how many fields are created. */
        get importMappingSummary() {
            return mappingSummary(this.importRows);
        },

        /** What stops the import from starting, as a list: the refusal of the API, then what the check of the mapping found. */
        get importAlerts() {
            const columns = Object.keys(this.importRowErrors).length > 0 ? [t('imports.error.fix_columns')] : [];

            return [this.importError, ...this.importProblems, ...columns]
                .filter((text) => text !== '')
                .map((text, position) => ({ key: position, text }));
        },

        /** Whether there is something to say about why the import does not start. */
        get hasImportAlerts() {
            return this.importAlerts.length > 0;
        },

        /** Whether the import is in the hands of a worker. */
        get importWaiting() {
            return this.importRecord !== null && isWaiting(this.importRecord);
        },

        /** Whether the import ended, done. */
        get importCompleted() {
            return this.importRecord?.status === 'completed';
        },

        /** Whether the import ended, failed. */
        get importFailed() {
            return this.importRecord?.status === 'failed';
        },

        /** The title of the result. */
        get importResultTitle() {
            return this.importRecord === null ? '' : statusTitle(this.importRecord);
        },

        /** What the import did, one sentence for each thing. */
        get importResultLines() {
            return this.importRecord === null ? [] : resultLines(this.importRecord);
        },

        /** Why the import failed. */
        get importFailureText() {
            return this.importRecord === null ? '' : failureText(this.importRecord);
        },

        /** The rows of the file that could not be read. */
        get importReport() {
            return this.importRecord === null ? [] : reportRows(this.importRecord);
        },

        /** Whether there are rows to list. */
        get hasImportReport() {
            return this.importReport.length > 0;
        },

        /** What the report says when it does not hold every row at fault. */
        get importReportNote() {
            return this.importRecord === null ? '' : reportNote(this.importRecord);
        },

        /**
         * Opens the dialog and looks for the import the person left on its way: the dialog then goes on with it, else it asks
         * for a file.
         *
         * @returns {Promise<void>}
         */
        async openImport() {
            this.importOpen = true;
            this.resetImport();

            // Once: Bootstrap fires `hidden.bs.modal` for every closing, however it was done.
            if (!hiddenBound) {
                hiddenBound = true;
                onModalHidden(this.$refs.importModal, () => this.onImportHidden());
            }

            showModal(this.$refs.importModal, { focus: ['#import-file'] });

            await this.resumeImport();
        },

        /**
         * The dialog has been closed, however it was: what it showed is forgotten (it is read again from the API when it
         * opens), the reading that was on its way is cancelled, and an import that is not over is watched from now on in
         * the background, so that the grid is read again when it ends.
         *
         * @returns {void}
         */
        onImportHidden() {
            this.importOpen = false;
            cancelReading();
            this.resetImport();

            if (watched !== null) {
                this.planImportWatch();
            }
        },

        /**
         * Stops asking about the import for good, when the page is taken apart: the next reading is not waited for, the one
         * that is on its way is cancelled, and nothing is watched in the background any more.
         *
         * @returns {void}
         */
        stopImportPolling() {
            destroyed = true;
            watched = null;
            cancelReading();
        },

        /**
         * Forgets what the dialog showed: no import, an empty mapping, the options the language asks for, nothing said. A
         * request that is on its way is not forgotten (see `importBusy`).
         *
         * @returns {void}
         */
        resetImport() {
            this.importStep = 'loading';
            this.importRecord = null;
            this.importRows = [];
            this.importRowErrors = {};
            this.importProblems = [];
            this.importError = '';
            this.importStatus = '';
            this.importFollowFailed = false;
            this.importHasHeader = true;
            this.importDelimiter = defaultDelimiter();
            this.importEncoding = 'utf-8';
            this.importDecimal = defaultDecimal(this.importDelimiter);
            this.importDateOrder = defaultDateOrder();
            this.importOnError = 'abort';
            decimalDefault = null;
            dateOrderDefault = null;
        },

        /**
         * Reads the imports of the person and goes on with the one they left: the most recent that has not ended, or the one
         * they were waiting for when they closed the dialog. A reading that fails leaves the dialog on the choice of a file.
         *
         * @returns {Promise<void>}
         */
        async resumeImport() {
            cancelReading();

            const mine = new AbortController();

            reading = mine;
            this.importStep = 'loading';

            try {
                const answer = await http.get(this.importsUrl, { signal: mine.signal });

                if (reading !== mine) {
                    return;
                }

                const found = pickResumable(answer.data, unseen);

                if (found === null) {
                    // Nothing is under way any more, so there is nothing left to watch either.
                    watched = null;
                    this.goToImportStep('file');
                } else {
                    this.showImport(found);
                }
            } catch (error) {
                if (error instanceof HttpError) {
                    // Only the latest reading speaks for the dialog. A 404 on the address of the imports is the inventory's own:
                    // it is gone, and so is everything on this page.
                    if (reading === mine && !this.leaveIfGone(error)) {
                        this.goToImportStep('file');
                    }
                } else if (error?.name !== 'AbortError') {
                    // Not a failure of the request (the helper lets a cancellation through as an AbortError): a bug, which must show.
                    throw error;
                }
            } finally {
                if (reading === mine) {
                    reading = null;
                }
            }
        },

        /**
         * Puts an import in the dialog, on the step its status says: the mapping for a file that waits for it, the result for
         * the others (followed, when a worker has not finished with it).
         *
         * @param {ImportRecord} record
         * @returns {void}
         */
        showImport(record) {
            this.importRecord = record;

            if (record.status === 'uploaded') {
                this.applyAnalysis(record);
                this.goToImportStep('mapping');
            } else {
                this.goToImportStep('result');
                this.followImport(record);
            }
        },

        /**
         * Takes an import that is in the hands of a worker, or is over, as the result: it is waited for, or its end is shown.
         *
         * @param {ImportRecord} record
         * @returns {void}
         */
        followImport(record) {
            this.importRecord = record;
            this.importFollowFailed = false;

            if (isFinished(record)) {
                this.settleImport(record);
            } else {
                unseen = record.id;
                watched = record.id;
                this.planImportPoll();
            }
        },

        /**
         * Shows the end of an import: said in the live region, and the items it created are read. It is done once for each
         * import, however many times it is shown.
         *
         * @param {ImportRecord} record An import that is over.
         * @returns {void}
         */
        settleImport(record) {
            unseen = null;
            watched = null;
            this.announceImport(endNotice(record));
            this.reloadOnce(record);
        },

        /**
         * Reads the items again for an import that was completed, unless it already has: however many times the end of an
         * import is found (by the dialog, by the watch, by the dialog again when it opens on the result), the grid is read
         * once.
         *
         * @param {ImportRecord} record An import that is over.
         * @returns {void}
         */
        reloadOnce(record) {
            if (record.status === 'completed' && !reloaded.has(record.id)) {
                reloaded.add(record.id);
                this.reloadAfterImport();
            }
        },

        /**
         * Tells the end of an import that came while the dialog was closed: a toast, as nobody is looking at the dialog, and
         * the grid is read again. The import stays the one the dialog opens on, so that the person can read its report.
         *
         * @param {ImportRecord} record An import that is over.
         * @returns {void}
         */
        settleInBackground(record) {
            watched = null;
            this.reloadOnce(record);

            if (record.status === 'completed') {
                notify('success', endNotice(record));
            } else {
                notify('danger', failureText(record));
            }
        },

        /**
         * Reads the fields and the first page of the items again, as the import may have created fields and added items. The
         * totals are read again too.
         *
         * @returns {Promise<void>}
         */
        async reloadAfterImport() {
            this.totalsFor = null;
            await this.loadInventory();
            await this.loadItems(1);
        },

        /**
         * Plans the next reading of the import, two seconds from now, when the dialog is open and the import is in the
         * hands of a worker; plans nothing otherwise (and forgets the reading that was planned).
         *
         * @returns {void}
         */
        planImportPoll() {
            if (pollTimer !== null) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            if (this.importOpen && this.importStep === 'result' && this.importWaiting) {
                pollTimer = setTimeout(() => {
                    pollTimer = null;
                    this.readImport();
                }, POLL_INTERVAL_MS);
            }
        },

        /**
         * Reads the import that is on its way. The answer is taken unless the reading was cancelled; when the import is not
         * over, the next reading is planned. An import that is gone (it was deleted, or kept too long) sends the person back
         * to the choice of a file; any other failure stops the readings and offers to try again.
         *
         * @param {boolean} [silent] Whether the helper says nothing when the reading fails: a poll is silent, a reading the
         *   person asked for (to try again) is not.
         * @returns {Promise<void>}
         */
        async readImport(silent = true) {
            const record = this.importRecord;

            if (record === null) {
                return;
            }

            cancelReading();

            const mine = new AbortController();

            reading = mine;
            this.importFollowFailed = false;

            try {
                const answer = await http.get(this.importUrl(record.id), { signal: mine.signal, silent });

                if (reading !== mine || this.importRecord?.id !== record.id) {
                    return;
                }

                this.followImport(answer.data);
            } catch (error) {
                if (error instanceof HttpError) {
                    if (reading === mine) {
                        if (error.status === 404) {
                            this.dropImport(t('imports.error.gone'));
                        } else {
                            this.importFollowFailed = true;
                        }
                    }
                } else if (error?.name !== 'AbortError') {
                    // Not a failure of the request (the helper lets a cancellation through as an AbortError): a bug, which must show.
                    throw error;
                }
            } finally {
                if (reading === mine) {
                    reading = null;
                }
            }
        },

        /**
         * Plans the next reading of the import that is watched while the dialog is closed, a few seconds from now (a closed
         * dialog shows nothing, so the readings are rarer than the dialog's own). Plans nothing, and forgets the reading that
         * was planned, when no import is watched, when the dialog is open (it follows the import itself) or when the page is
         * taken apart.
         *
         * @returns {void}
         */
        planImportWatch() {
            if (pollTimer !== null) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            if (watched !== null && !this.importOpen && !destroyed) {
                pollTimer = setTimeout(() => {
                    pollTimer = null;
                    this.readWatchedImport();
                }, BACKGROUND_POLL_INTERVAL_MS);
            }
        },

        /**
         * Reads the import that is watched, silently, one request at a time (the next is planned once this one has answered).
         * When the import is over the grid is read again and a toast tells how it ended; otherwise the next reading is
         * planned. An import that is gone, or that the person may not read any more, is given up; a failure that may pass
         * (the server is down, the network is out, the throttling) is tried again.
         *
         * @returns {Promise<void>}
         */
        async readWatchedImport() {
            const id = watched;

            if (id === null || this.importOpen) {
                return;
            }

            cancelReading();

            const mine = new AbortController();

            reading = mine;

            try {
                const answer = await http.get(this.importUrl(id), { signal: mine.signal, silent: true });

                // A reading that was cancelled (the dialog opened, the page went) or that is about another import is not the
                // watch's any more.
                if (reading !== mine || watched !== id) {
                    return;
                }

                if (isFinished(answer.data)) {
                    this.settleInBackground(answer.data);
                } else {
                    this.planImportWatch();
                }
            } catch (error) {
                if (reading !== mine) {
                    return;
                }

                if (!(error instanceof HttpError)) {
                    // A bug, not a failure of the request: it must show.
                    throw error;
                }

                if (error.status >= 400 && error.status < 500 && error.status !== 408 && error.status !== 429) {
                    // Gone (404) or refused: asking again would get the same answer.
                    if (unseen === id) {
                        unseen = null;
                    }

                    watched = null;
                } else {
                    this.planImportWatch();
                }
            } finally {
                if (reading === mine) {
                    reading = null;
                }
            }
        },

        /**
         * Address of one import.
         *
         * @param {string} id
         * @returns {string}
         */
        importUrl(id) {
            return `${this.importsUrl}/${encodeURIComponent(id)}`;
        },

        /**
         * Takes the file the person chose and sends it. The chooser is emptied, so that the same file can be chosen again
         * (after it was corrected, say).
         *
         * @param {Event} event The `change` event of the file chooser.
         * @returns {Promise<void>}
         */
        async onImportFile(event) {
            const input = /** @type {HTMLInputElement} */ (event.target);
            const file = input.files?.[0] ?? null;

            input.value = '';

            if (file !== null) {
                await this.uploadImport(file);
            }
        },

        /**
         * Sends a file to the API, which reads it and answers with its columns and a mapping to start from. A file larger
         * than the server takes is refused here, before it goes up.
         *
         * @param {File} file
         * @returns {Promise<void>}
         */
        async uploadImport(file) {
            if (this.importWorking) {
                return;
            }

            this.importError = '';

            if (isTooLarge(file, this.importMaxSize)) {
                this.importError = t('imports.error.too_large', { max: this.importMaxSizeLabel });

                return;
            }

            this.importBusy = 'upload';
            this.importUploadName = file.name;

            try {
                const body = new FormData();

                body.append('file', file);

                const answer = await http.post(this.importsUrl, body, { timeout: uploadTimeoutFor(file.size) });

                this.importRecord = answer.data;
                this.importRowErrors = {};
                this.importProblems = [];
                this.applyAnalysis(answer.data);
                this.goToImportStep('mapping');
                this.announceImport(tn('imports.notice.analysed', this.importRows.length));
            } catch (error) {
                // A 404 on the address of the imports is the inventory's own: it is gone, and so is everything on this page.
                if (!this.leaveIfGone(error)) {
                    this.failImport(error);
                }
            } finally {
                this.importBusy = '';
            }
        },

        /**
         * Takes what the API found in the file: the options the file is read with, the options the cells are read with
         * (unless the person chose them), and the mapping to start from.
         *
         * @param {ImportRecord} record
         * @returns {void}
         */
        applyAnalysis(record) {
            const options = optionsOf(record);
            const fresh = decimalDefault === null;
            const decimal = options.decimal ?? defaultDecimal(options.delimiter);
            const dateOrder = options.dateOrder ?? defaultDateOrder();

            this.applyReading(record);

            // A choice of the person (a value other than the one the dialog set) stays.
            if (fresh || this.importDecimal === decimalDefault) {
                this.importDecimal = decimal;
            }

            if (fresh || this.importDateOrder === dateOrderDefault) {
                this.importDateOrder = dateOrder;
            }

            if (fresh) {
                this.importOnError = options.onError;
            }

            decimalDefault = decimal;
            dateOrderDefault = dateOrder;

            this.importRows = mappingRows(record.columns, record.mapping, {
                fields: this.fields,
                canManageFields: this.canManageFields,
            });
        },

        /**
         * Puts the controls that decide how the file is read (the row of the headers, the separator, the encoding) back to
         * what the import holds: when the file is read again, and when that failed.
         *
         * @param {ImportRecord} record
         * @returns {void}
         */
        applyReading(record) {
            const options = optionsOf(record);

            this.importHasHeader = options.hasHeader;
            this.importDelimiter = options.delimiter;
            this.importEncoding = options.encoding;
        },

        /**
         * Asks the API to read the file again with the row of the headers, the separator and the encoding that were
         * chosen. The columns that come back replace the mapping: what the person had decided for the old ones is gone, which
         * is told.
         *
         * @returns {Promise<void>}
         */
        async reanalyzeImport() {
            const record = this.importRecord;

            if (this.importWorking || record === null) {
                return;
            }

            this.importBusy = 'analyse';
            this.importError = '';

            try {
                const answer = await http.patch(
                    this.importUrl(record.id),
                    analysisBody(
                        {
                            hasHeader: this.importHasHeader,
                            delimiter: this.importDelimiter,
                            encoding: this.importEncoding,
                        },
                        this.importIsCsv,
                    ),
                    // The API reads the whole file again: as long as for the upload.
                    { timeout: uploadTimeoutFor(record.size) },
                );

                this.importRecord = answer.data;
                this.importRowErrors = {};
                this.importProblems = [];
                this.applyAnalysis(answer.data);
                this.announceImport(t('imports.notice.reanalysed'));
            } catch (error) {
                // The controls go back to how the file is read: they must not claim what the API refused.
                this.applyReading(record);
                this.failImport(error);
            } finally {
                this.importBusy = '';
            }
        },

        /**
         * Chooses where a column goes: nowhere, an existing field, or a field to create.
         *
         * @param {{ index: number }} row The column, as the dialog shows it.
         * @param {Event} event The `change` event of its select.
         * @returns {void}
         */
        setImportDestination(row, event) {
            const found = this.importRows.find((one) => one.index === row.index);
            const choice = parseDestination(/** @type {HTMLSelectElement} */ (event.target).value, {
                fields: this.fields,
                canManageFields: this.canManageFields,
            });

            if (found !== undefined && choice !== null) {
                found.target = choice.target;
                found.field = choice.field;
                this.forgetImportProblems(row.index);
            }
        },

        /**
         * Chooses the type of the field a column creates.
         *
         * @param {{ index: number }} row
         * @param {Event} event The `change` event of its select.
         * @returns {void}
         */
        setImportType(row, event) {
            const found = this.importRows.find((one) => one.index === row.index);
            const type = /** @type {HTMLSelectElement} */ (event.target).value;

            if (found !== undefined && NEW_FIELD_TYPES.includes(type)) {
                found.type = type;
                this.forgetImportProblems(row.index);
            }
        },

        /**
         * Takes the name typed for the field a column creates.
         *
         * @param {{ index: number }} row
         * @param {Event} event The `input` event of its input.
         * @returns {void}
         */
        setImportName(row, event) {
            const found = this.importRows.find((one) => one.index === row.index);

            if (found !== undefined) {
                found.name = /** @type {HTMLInputElement} */ (event.target).value;
                this.forgetImportProblems(row.index);
            }
        },

        /**
         * Forgets what the last try to start the import found wrong with a column, and with the mapping as a whole: the
         * mapping has changed, the next try finds out again.
         *
         * @param {number} index The column.
         * @returns {void}
         */
        forgetImportProblems(index) {
            delete this.importRowErrors[index];
            this.importProblems = [];
        },

        /**
         * Starts the import with the mapping and the options chosen, once the mapping holds up: a column with nothing wrong
         * with it is not asked about, a mapping with something wrong is not sent, and the cursor goes to the first column
         * that needs attention. The import is then in the hands of a worker, and the dialog follows it.
         *
         * @returns {Promise<void>}
         */
        async startImport() {
            const record = this.importRecord;

            if (this.importWorking || record === null || this.importStep !== 'mapping') {
                return;
            }

            const problems = validate(this.importRows, this.fields, { canManageFields: this.canManageFields });

            this.importRowErrors = problems.rowErrors;
            this.importProblems = problems.general;
            this.importError = '';

            if (!problems.valid) {
                this.focusFirstImportProblem();

                return;
            }

            this.importBusy = 'start';

            try {
                const answer = await http.post(
                    this.importUrl(record.id) + '/start',
                    startBody(this.importRows, {
                        decimal: this.importDecimal,
                        dateOrder: this.importDateOrder,
                        onError: this.importOnError,
                    }),
                );
                const reported = answer?.data ?? record;
                const next =
                    isWaiting(reported) || isFinished(reported) ? reported : { ...reported, status: 'pending' };

                // The dialog was closed while the request was on its way: the import is watched from the background (a toast
                // tells how it ends), and the dialog shows it when it opens again.
                if (!this.importOpen) {
                    unseen = next.id;

                    if (isFinished(next)) {
                        this.settleInBackground(next);
                    } else {
                        watched = next.id;
                        notify('success', t('imports.notice.started'));
                        this.planImportWatch();
                    }

                    return;
                }

                this.goToImportStep('result');
                // Before the import is followed: one that is already over (a queue that runs at once) says its end last.
                this.announceImport(t('imports.notice.started'));
                this.followImport(next);
            } catch (error) {
                this.failImport(error);
            } finally {
                this.importBusy = '';
            }
        },

        /**
         * Gives up the import that waits for its mapping: the API deletes it with its file, and the dialog asks for a file
         * again. An import that is already gone is given up all the same.
         *
         * @returns {Promise<void>}
         */
        async cancelImport() {
            const record = this.importRecord;

            if (this.importWorking || record === null) {
                return;
            }

            this.importBusy = 'cancel';
            this.importError = '';

            try {
                await http.delete(this.importUrl(record.id));
                this.dropImport(null);
                this.announceImport(t('imports.notice.cancelled'));
            } catch (error) {
                if (error instanceof HttpError && error.status === 404) {
                    this.dropImport(null);
                } else {
                    this.failImport(error);
                }
            } finally {
                this.importBusy = '';
            }
        },

        /**
         * Leaves the import the dialog was about and asks for a file again: for a new import, after the end of one, and when
         * the import is gone.
         *
         * @param {string | null} reason What is said under the buttons, when the import is gone ("" or null for nothing).
         * @returns {void}
         */
        dropImport(reason) {
            cancelReading();

            if (this.importRecord !== null && unseen === this.importRecord.id) {
                unseen = null;
            }

            if (this.importRecord !== null && watched === this.importRecord.id) {
                watched = null;
            }

            this.resetImport();
            this.importError = reason ?? '';
            this.goToImportStep('file');
        },

        /**
         * Goes back to the choice of a file, once an import is over.
         *
         * @returns {void}
         */
        restartImport() {
            this.dropImport(null);
        },

        /**
         * Reads again the import whose end the dialog could not follow (the poll failed), and plans the readings again.
         *
         * @returns {Promise<void>}
         */
        retryImport() {
            return this.readImport(false);
        },

        /**
         * Says why a request was refused. A refusal the helper did not report is told under the buttons; so is the
         * throttling, which the helper only toasts: the person is looking at the dialog. An import that was started or
         * deleted elsewhere is read again, to show where it stands.
         *
         * @param {unknown} error
         * @returns {void}
         */
        failImport(error) {
            if (!(error instanceof HttpError)) {
                this.importError = t('error.generic');

                return;
            }

            if (error.status === 409 && error.code === LIMIT_CODE) {
                this.importError = t('imports.error.limit');
            } else if (error.status === 409 && (error.code === NOT_EDITABLE_CODE || error.code === RUNNING_CODE)) {
                this.importError = t('imports.error.not_editable');
                this.resumeImport();
            } else if (error.status === 404) {
                this.dropImport(t('imports.error.gone'));
            } else if (error.status === 422) {
                // What is wrong with the file (`file`) or with the mapping (`mapping`...): the server says it in words.
                this.importError = [...new Set(Object.values(firstMessages(error.errors)))].join(' ') || error.message;
            } else if (!error.reported || error.status === 429) {
                this.importError = error.message;
            }
        },

        /**
         * Puts the dialog on a step, and puts the cursor where the person goes on from: the file chooser for the file (the
         * step is only visible now, so the focus the dialog asked for when it opened could not go there), the title of the
         * step for the others, so that a person who cannot see the dialog change knows where they are.
         *
         * @param {'file' | 'mapping' | 'result'} step
         * @returns {void}
         */
        goToImportStep(step) {
            const refs = this.$refs;

            this.importStep = step;
            this.$nextTick(() => {
                const selector =
                    step === 'file' ? '#import-file' : `[data-import-step="${step}"] [data-import-heading]`;

                refs.importModal?.querySelector?.(selector)?.focus();
            });
        },

        /**
         * Puts the cursor on the first control of a column that needs attention.
         *
         * @returns {void}
         */
        focusFirstImportProblem() {
            const refs = this.$refs;

            this.$nextTick(() => {
                refs.importModal?.querySelector?.('[data-import-step="mapping"] [aria-invalid="true"]')?.focus();
            });
        },

        /**
         * Says something in the live region. It is emptied first, so that the same sentence said twice in a row is
         * announced twice.
         *
         * @param {string} message
         * @returns {void}
         */
        announceImport(message) {
            this.importStatus = '';
            this.$nextTick(() => {
                this.importStatus = message;
            });
        },
    };
}
