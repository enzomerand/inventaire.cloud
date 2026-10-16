/**
 * The export dialog of the page of an inventory: the choice of a format, of the items and of the options of the format, the
 * exports the person asked for lately, and the file of each once it is ready.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`), because "the current view" is the search, the
 * filters and the sort the grid holds (`query`, see `../grid/criteria.js`): the view is sent to the API as the very
 * parameters the list is read with (see `toBody` in `../grid/query.js`), so the file holds the items the person sees.
 *
 * An export is made by a job, not by the request that asks for it: the API answers `202` with the export still `pending`,
 * and the list asks again every two seconds, for as long as the dialog is open and one of the exports has not ended. A
 * reading that is on its way when the dialog closes is cancelled, and a reading that began before a change made here (an
 * export added or deleted) is dropped and made again, so a list is never shown with what the person just did missing from
 * it. The polls are silent (the helper does not toast each failure of a request that comes every two seconds): a failure
 * stops them and the list offers to try again.
 *
 * The file is a plain link to the address the API gives (`download_url`): the browser downloads it with the session cookie,
 * so the link must not carry `rel=noreferrer` (Sanctum recognises the front by its Referer).
 *
 * Whatever is said to the person while they cannot see it (an export started, ready, deleted) goes to one polite live
 * region that is always in the page.
 */
import { firstMessages } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { POLL_INTERVAL_MS, defaultDelimiter, formatChoices, isActive, requestBody, rowOf } from './exports.js';

/** The code the API gives to the refusal of an export when the person already has too many of this inventory. */
const LIMIT_CODE = 'export_limit_reached';

/** The code the API gives to the refusal of an export when the exports of the person would take too much room. */
const STORAGE_CODE = 'export_storage_full';

/**
 * @typedef {import('./exports.js').ExportRecord} ExportRecord
 */

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function exportDialog() {
    // Plumbing that is not state the page shows, so it stays out of the reactive data: the timer of the next poll, the
    // cancellation of the reading that is on its way, and whether the dialog's `hidden` event is listened to yet.
    /** @type {ReturnType<typeof setTimeout> | null} */
    let pollTimer = null;
    /** @type {AbortController | null} */
    let reading = null;
    let hiddenBound = false;

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
        /** Whether the dialog is open: the list is kept up to date only then. */
        exportOpen: false,

        /** The format chosen: `xlsx`, `ods`, `csv`, `json`, `pdf` or `zip`. */
        exportFormat: 'xlsx',

        /** The items to export: `all` of them or the `view` the grid shows. */
        exportScope: 'all',

        /** The separator of a CSV file; "" until the dialog first opens, when the language of the page picks one. */
        exportDelimiter: '',

        /** The files of a ZIP: the `photos` of the photo fields, or `all` the attachments. */
        exportFiles: 'photos',

        /** True while the request that starts an export is on its way. */
        exportBusy: false,

        /** Why the last request to start an export was refused ("" when it was not). */
        exportError: '',

        /** The exports of the person for this inventory, newest first. */
        exportList: [],

        /** True while the list is being read with a spinner (the reading that follows the opening of the dialog, a retry). */
        exportsLoading: false,

        /** True when the last reading of the list failed: the dialog offers to try again. */
        exportsFailed: false,

        /** True once the list was read since the dialog opened. */
        exportsLoaded: false,

        /** What the live region says; changing it is how a screen reader is told. */
        exportStatus: '',

        /** The ids of the exports whose deletion is on its way. */
        deletingExports: [],

        /** Counts the changes made here to the list: a reading that began before one is out of date. */
        exportRevision: 0,

        /** Endpoint of the exports of the inventory, from the address of the inventory. */
        get exportsUrl() {
            return this.url + '/exports';
        },

        /** The formats the dialog offers, with what each is good for. */
        get exportFormats() {
            return formatChoices();
        },

        /** Whether the choice of the separator is shown: for a CSV file only. */
        get showDelimiter() {
            return this.exportFormat === 'csv';
        },

        /** Whether the choice of the files is shown: for a ZIP only. */
        get showFiles() {
            return this.exportFormat === 'zip';
        },

        /** Whether the view can be exported on its own: it is narrowed down or sorted, else it is everything (a sort is a view too). */
        get viewScopeAvailable() {
            return this.hasCriteria || this.isSorted;
        },

        /** What the choice of the view says: how many items it holds, or why it cannot be chosen. */
        get viewScopeHint() {
            return this.viewScopeAvailable
                ? tn('exports.scope.view_hint', this.total)
                : t('exports.scope.view_unavailable');
        },

        /** The items an export asks for: the view only when it can be exported on its own. */
        get effectiveExportScope() {
            return this.exportScope === 'view' && this.viewScopeAvailable ? 'view' : 'all';
        },

        /** The exports as the dialog shows them. */
        get exportRows() {
            return this.exportList.map((one) => rowOf(one, { deleting: this.deletingExports.includes(one.id) }));
        },

        /** Whether there is an export to list. */
        get hasExports() {
            return this.exportList.length > 0;
        },

        /** Whether the list was read and holds nothing. */
        get noExports() {
            return this.exportsLoaded && !this.exportsFailed && this.exportList.length === 0;
        },

        /**
         * Opens the dialog on the format and the items last chosen, with the cursor on the format, and reads the exports.
         * The view can no longer be chosen when it was left since; the separator of a CSV file starts as the language asks.
         *
         * @returns {Promise<void>}
         */
        async openExport() {
            this.exportOpen = true;
            this.exportError = '';
            this.exportStatus = '';

            if (this.exportDelimiter === '') {
                this.exportDelimiter = defaultDelimiter();
            }

            if (!this.viewScopeAvailable) {
                this.exportScope = 'all';
            }

            // Once: Bootstrap fires `hidden.bs.modal` for every closing, however it was done.
            if (!hiddenBound) {
                hiddenBound = true;
                onModalHidden(this.$refs.exportModal, () => this.onExportHidden());
            }

            // The cursor goes to the format that is chosen (the radios are one tab stop), not to the first one.
            showModal(this.$refs.exportModal, { focus: ['input[name="export-format"]:checked'] });

            await this.loadExports();
        },

        /**
         * The dialog has been closed, however it was: nothing is read any more and the list is forgotten, so that it never
         * shows exports that expired in the meantime when it opens again.
         *
         * @returns {void}
         */
        onExportHidden() {
            this.exportOpen = false;
            this.stopExportPolling();
            this.exportList = [];
            this.exportsLoaded = false;
            this.exportsFailed = false;
            this.exportError = '';
            this.exportStatus = '';
        },

        /**
         * Stops asking for the list: the next poll is not waited for and the reading that is on its way is cancelled.
         *
         * @returns {void}
         */
        stopExportPolling() {
            cancelReading();
            this.exportsLoading = false;
        },

        /**
         * Reads the list with a spinner: when the dialog opens, and when the person asks to try again.
         *
         * @returns {Promise<void>}
         */
        loadExports() {
            return this.readExports({ silent: false });
        },

        /**
         * Reads the exports of the person. The answer replaces the list unless the reading was cancelled, or began before the
         * person added or deleted an export (it is then made again, as it would not tell what was just done). When an export
         * is still on its way, the next reading is planned.
         *
         * @param {{ silent: boolean }} options A poll is silent: the helper then shows nothing when it fails.
         * @returns {Promise<void>}
         */
        async readExports({ silent }) {
            cancelReading();

            const mine = new AbortController();
            const revision = this.exportRevision;

            reading = mine;
            this.exportsLoading = !silent;

            try {
                const answer = await http.get(this.exportsUrl, { signal: mine.signal, silent });

                if (reading !== mine) {
                    return;
                }

                if (revision !== this.exportRevision) {
                    await this.readExports({ silent });

                    return;
                }

                this.exportsFailed = false;
                this.exportsLoaded = true;
                this.applyExports(answer.data);
                this.planExportPoll();
            } catch {
                // A cancelled reading is not a failure: it is no longer the one in `reading` (the helper lets the abort through).
                if (reading === mine) {
                    this.exportsFailed = true;
                }
            } finally {
                if (reading === mine) {
                    reading = null;
                    this.exportsLoading = false;
                }
            }
        },

        /**
         * Takes the list the server gave, and tells (in the live region) the exports that ended since the last one.
         *
         * @param {ExportRecord[]} list
         * @returns {void}
         */
        applyExports(list) {
            const known = new Map(this.exportList.map((one) => [one.id, one]));
            const next = Array.isArray(list) ? list : [];
            const news = next
                .filter((one) => known.has(one.id) && isActive(known.get(one.id)) && !isActive(one))
                .map((one) =>
                    one.status === 'completed'
                        ? t('exports.notice.ready', { name: one.file_name ?? t(`exports.format.${one.format}`) })
                        : t('exports.notice.failed'),
                );

            this.exportList = next;

            if (news.length > 0) {
                this.announceExport(news.join(' '));
            }
        },

        /**
         * Plans the next reading, two seconds from now, when the dialog is open and an export is still on its way; plans
         * nothing otherwise (and forgets the reading that was planned).
         *
         * @returns {void}
         */
        planExportPoll() {
            if (pollTimer !== null) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            if (this.exportOpen && this.exportList.some(isActive)) {
                pollTimer = setTimeout(() => {
                    pollTimer = null;
                    this.readExports({ silent: true });
                }, POLL_INTERVAL_MS);
            }
        },

        /**
         * Asks for an export of the format and the items chosen. The export is added to the list at once, still waiting, and
         * the list starts to follow it. A refusal is said under the buttons.
         *
         * @returns {Promise<void>}
         */
        async submitExport() {
            if (this.exportBusy) {
                return;
            }

            this.exportBusy = true;
            this.exportError = '';

            try {
                const answer = await http.post(
                    this.exportsUrl,
                    requestBody({
                        format: this.exportFormat,
                        scope: this.effectiveExportScope,
                        query: this.query,
                        delimiter: this.exportDelimiter,
                        files: this.exportFiles,
                    }),
                );

                this.exportRevision += 1;

                // The dialog was closed while the request was on its way: there is no list to add to, so the toast tells.
                if (!this.exportOpen) {
                    notify('success', t('exports.notice.started'));

                    return;
                }

                this.exportList = [answer.data, ...this.exportList.filter((one) => one.id !== answer.data.id)];
                this.announceExport(t('exports.notice.started'));
                this.planExportPoll();
            } catch (error) {
                this.failExport(error);
            } finally {
                this.exportBusy = false;
            }
        },

        /**
         * Says why an export was not started. A refusal the helper did not report is told under the buttons; so is the
         * throttling, which the helper only toasts: the person is looking at the dialog.
         *
         * @param {unknown} error
         * @returns {void}
         */
        failExport(error) {
            if (!(error instanceof HttpError)) {
                this.exportError = t('error.generic');

                return;
            }

            if (error.status === 409 && error.code === LIMIT_CODE) {
                this.exportError = t('exports.error.limit');
            } else if (error.status === 409 && error.code === STORAGE_CODE) {
                this.exportError = t('exports.error.storage_full');
            } else if (error.status === 422) {
                // The limits of the format come as the refusal of `format` (a PDF that is too big, a ZIP with no file...).
                this.exportError = [...new Set(Object.values(firstMessages(error.errors)))].join(' ') || error.message;
            } else if (!error.reported || error.status === 429) {
                this.exportError = error.message;
            }
        },

        /**
         * Deletes an export (its file goes with it). One that is already gone (it expired, or was deleted elsewhere) leaves
         * the list all the same.
         *
         * @param {{ id: string }} row
         * @returns {Promise<void>}
         */
        async removeExport(row) {
            // Taken now: once the button is gone with its row, Alpine may no longer answer `$refs` to a call made from its scope.
            const refs = this.$refs;

            if (this.deletingExports.includes(row.id)) {
                return;
            }

            this.deletingExports = [...this.deletingExports, row.id];

            try {
                await http.delete(`${this.exportsUrl}/${encodeURIComponent(row.id)}`);
                this.dropExport(row.id, refs);
            } catch (error) {
                if (error instanceof HttpError && error.status === 404) {
                    this.dropExport(row.id, refs);
                } else if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.message);
                }
            } finally {
                this.deletingExports = this.deletingExports.filter((id) => id !== row.id);
            }
        },

        /**
         * Takes an export out of the list, tells it, and puts the cursor where the button that was pressed was: on the
         * button of the export that took its place, else on the one before, else on the button that starts an export.
         *
         * @param {string} id
         * @param {Record<string, any>} refs The refs of the component, taken before the request (see `removeExport`).
         * @returns {void}
         */
        dropExport(id, refs) {
            const position = this.exportList.findIndex((one) => one.id === id);

            this.exportRevision += 1;
            this.exportList = this.exportList.filter((one) => one.id !== id);
            this.announceExport(t('exports.notice.deleted'));
            this.planExportPoll();

            this.$nextTick(() => {
                const buttons = [...(refs.exportModal?.querySelectorAll?.('[data-export-delete]') ?? [])];

                (buttons[Math.min(position, buttons.length - 1)] ?? refs.exportSubmit)?.focus();
            });
        },

        /**
         * Says something in the live region. It is emptied first, so that the same sentence said twice in a row (two
         * exports deleted one after the other) is announced twice.
         *
         * @param {string} message
         * @returns {void}
         */
        announceExport(message) {
            this.exportStatus = '';
            this.$nextTick(() => {
                this.exportStatus = message;
            });
        },
    };
}
