/**
 * The dialog that adds an item from photos, on the page of an inventory: the editor sends one to five photos of an object, Claude
 * reads them and proposes values for the fields, the editor reviews the proposals one by one, and the usual form of a new item
 * opens with the values they accepted (and the photos in the photo field).
 *
 * It is a piece of the `inventoryItems` component (see `../lib/compose.js`), because what it ends with is that component's form:
 * the fields of the inventory, whether the person may add items (`canAdd`), the form that adds one (`startCreate`, `set`) and the
 * files the form keeps until the item exists (`receive`). It has its own Bootstrap dialog (`photoModal`), which is never open at
 * the same time as the dialog of the item: it is closed, and its `hidden.bs.modal` awaited, before the form opens.
 *
 * The dialog has four steps:
 *
 * - `choose`: the photos (an `<input type="file" multiple accept="image/*">` with no `capture`, so that a phone offers the camera
 *   and the gallery), shown as thumbnails with a button that takes each back. The note says where the photos go;
 * - `working`: every photo is redrawn as a JPEG of at most 1568 pixels (`./photos.js`), they go up in one multipart request
 *   (`POST`, answered `202`), and the analysis, which a job makes, is asked about every two seconds (a silent `GET`, cancelled
 *   with an `AbortController` when the dialog closes or the person cancels). The server keeps the photos only until the job ends;
 * - `review`: one row for each value Claude proposes (`./proposals.js`): the field, the value as the table shows it, how sure Claude
 *   is and where it read it. Every checkbox starts unchecked: nothing is accepted for the person;
 * - `error`: what went wrong, in words, with a way back to the photos and, when trying again can end differently, a retry.
 *
 * "Continue" closes the dialog; when it has been hidden, the form of a new item opens, the values that were checked are set in it
 * (the same `set` as a typed value, so the server stays the judge of them when the form is saved) and the original photos, not the
 * redrawn ones, are kept for the first photo field of the inventory, up to what that field takes (the person is told when some are
 * left out). The redrawn photo goes instead of an original the photo field would refuse (a HEIC photo that Safari can decode).
 *
 * Whatever is said to the person while they cannot see it (a step reached, an analysis that ends) goes to one polite live region.
 */
import { PHOTO_TYPES, maxFilesOf } from '../fields/files.js';
import { controlOf, toInput } from '../fields/index.js';
import { HttpError, http, leaveStalePage, uploadTimeoutFor } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import { DEFAULT_MAX_PHOTOS, UnreadablePhoto, looksLikeImage, prepareAnalysisPhoto } from './photos.js';
import {
    MAX_POLL_FAILURES,
    MAX_WAIT_MS,
    POLL_INTERVAL_MS,
    STALE_STATUSES,
    failureOf,
    isEnded,
    requestFailure,
    reviewRows,
} from './proposals.js';

/**
 * @typedef {import('../fields/types.js').Field} Field
 * @typedef {import('./proposals.js').Analysis} Analysis
 * @typedef {import('./proposals.js').Failure} Failure
 *
 * @typedef {object} ChosenPhoto A photo the person chose, as the dialog lists it.
 * @property {string} key
 * @property {string} name
 * @property {string} url The address of its thumbnail (made for the file, revoked when the photo goes).
 * @property {string} removeLabel The accessible name of the button that takes it back.
 *
 * @typedef {object} Accepted What the person accepted, kept while the dialog closes.
 * @property {{ fieldId: string, value: any }[]} entries The checked proposals.
 * @property {{ original: File, prepared: File | undefined }[]} photos The photos, as chosen and as sent.
 */

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function photoEntryDialog() {
    // Plumbing that is not state the page shows, so it stays out of the reactive data: the timer of the next reading, the request
    // that is on its way, the files (a File in a reactive object is wrapped for nothing) and the counters.
    /** @type {ReturnType<typeof setTimeout> | null} */
    let pollTimer = null;
    /** @type {AbortController | null} */
    let working = null;
    // Ticket of the work the dialog does: what was started under an older one (a cancelled analysis, a closed dialog) is dropped.
    let run = 0;
    // Ticket of the last thing said to the live region: a repeat that waits for the page to draw the empty region gives way to whatever
    // was said, or cleared, after it.
    let announced = 0;
    let hiddenBound = false;
    let counter = 0;
    // The analysis being followed, since when, and how many readings in a row failed.
    /** @type {string | null} */
    let analysisId = null;
    let startedAt = 0;
    let failures = 0;
    /** @type {Map<string, File>} */
    const originals = new Map();
    /** @type {Map<string, File>} */
    const prepared = new Map();
    /** @type {Accepted | null} */
    let accepted = null;

    /**
     * Stops waiting for the next reading, cancels the request that is on its way, and makes whatever was started stale.
     *
     * @returns {void}
     */
    function cancelWork() {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        working?.abort();
        working = null;
        run += 1;
    }

    return {
        /** Whether the dialog is open: an analysis is followed only then. */
        photoOpen: false,

        /** The step: `choose`, `working`, `review` or `error`. */
        photoStep: 'choose',

        /** What the request or the work on its way is: `preparing` (the photos are redrawn), `uploading` or `analysing` ("" when none). */
        photoBusy: '',

        /** The photos the person chose, in order. */
        photoPhotos: [],

        /** Why the last photo was refused, at the step of the choice ("" when none was). */
        photoMessage: '',

        /** The rows of the review: one for each value Claude proposes. */
        photoRows: [],

        /** Which rows the person checked, by field id. */
        photoChecked: {},

        /** What went wrong, and what the dialog offers about it (see `./proposals.js`). */
        photoFailure: { text: '', retry: false, resume: false },

        /** What the live region says; changing it is how a screen reader is told. */
        photoStatus: '',

        /** Whether the page offers to add an item from photos: from `data-photo-entry` on the component root (the server decides). */
        get photoEnabled() {
            return this.rootElement.dataset.photoEntry === '1';
        },

        /** Endpoint of the analyses of the inventory (POST), from `data-photo-entry-url` on the component root. */
        get photoEntryUrl() {
            return this.rootElement.dataset.photoEntryUrl ?? '';
        },

        /** How many photos an analysis takes, from `data-photo-entry-max` (5 when the page does not say). */
        get photoMax() {
            const wanted = Math.trunc(Number(this.rootElement.dataset.photoEntryMax));

            return Number.isFinite(wanted) && wanted > 0 ? wanted : DEFAULT_MAX_PHOTOS;
        },

        /** Whether there is a field the photos could fill in (a photo or file field is filled by uploading, not by reading). */
        get photoFillable() {
            return this.fields.some((field) => controlOf(field) !== 'files');
        },

        /** Whether the "Add from photos" button is offered: the page allows it, the person may add items, and there is a field to fill. */
        get canPhotoEntry() {
            return this.photoEnabled && this.canAdd && this.photoFillable;
        },

        /** Whether the dialog asks for the photos. */
        get photoOnChoose() {
            return this.photoStep === 'choose';
        },

        /** Whether the failure the dialog shows is one that trying again can end differently. */
        get photoCanRetry() {
            return this.photoStep === 'error' && this.photoFailure.retry;
        },

        /** Whether the dialog sends the photos and waits for the analysis. */
        get photoOnWorking() {
            return this.photoStep === 'working';
        },

        /** Whether the dialog shows what Claude proposes. */
        get photoOnReview() {
            return this.photoStep === 'review';
        },

        /** Whether the dialog says something went wrong. */
        get photoOnError() {
            return this.photoStep === 'error';
        },

        /** Whether the photos are being sent or analysed. */
        get photoWorking() {
            return this.photoBusy !== '';
        },

        /** What the dialog is doing while it works, in words. */
        get photoWorkingText() {
            return t(`photoEntry.working.${this.photoBusy === '' ? 'analysing' : this.photoBusy}`);
        },

        /** How many photos can still be added. */
        get photoRoom() {
            return Math.max(0, this.photoMax - this.photoPhotos.length);
        },

        /** Whether the file chooser is offered: there is room for a photo and nothing is being sent. */
        get canChoosePhotos() {
            return this.photoRoom > 0 && !this.photoWorking;
        },

        /** Whether the analysis can start: there is at least one photo and nothing is being sent. */
        get canAnalysePhotos() {
            return this.photoPhotos.length > 0 && !this.photoWorking;
        },

        /** What the file chooser is described by: how many photos, and of what. */
        get photoChooseHint() {
            return t('photoEntry.choose.hint', { count: this.photoMax });
        },

        /** How many photos are chosen out of how many an analysis takes. */
        get photoCountText() {
            return t('photoEntry.choose.count', { count: this.photoPhotos.length, max: this.photoMax });
        },

        /** Whether Claude found at least one value. */
        get photoHasRows() {
            return this.photoRows.length > 0;
        },

        /** Whether the review has nothing to show: Claude found no value on the photos. */
        get photoNoRows() {
            return this.photoRows.length === 0;
        },

        /** How many proposals the person checked. */
        get photoCheckedCount() {
            return this.photoRows.filter((row) => this.photoChecked[row.key] === true).length;
        },

        /** What the review comes to: how many values are found, how many are checked. */
        get photoSummary() {
            return t('photoEntry.review.summary', { count: this.photoCheckedCount, total: this.photoRows.length });
        },

        /**
         * Opens the dialog, on the choice of the photos.
         *
         * @returns {void}
         */
        openPhotoEntry() {
            if (!this.canPhotoEntry) {
                return;
            }

            this.resetPhotoEntry();
            this.photoOpen = true;

            // Once: Bootstrap fires `hidden.bs.modal` for every closing, however it was done.
            if (!hiddenBound) {
                hiddenBound = true;
                onModalHidden(this.$refs.photoModal, () => this.onPhotoEntryHidden());
            }

            showModal(this.$refs.photoModal, { focus: ['#photo-entry-pick'] });
        },

        /**
         * The dialog has been closed, however it was: the analysis stops being followed, the photos are let go of, and, when the
         * person pressed "Continue", the form of the new item opens (after the other listeners of this very event have run: the
         * focus goes back to the button that opened the dialog first, so that the form returns it there when it closes).
         *
         * @returns {void}
         */
        onPhotoEntryHidden() {
            const next = accepted;

            accepted = null;
            this.photoOpen = false;
            this.resetPhotoEntry();

            if (next !== null) {
                this.$nextTick(() => this.createFromPhotos(next));
            }
        },

        /**
         * Stops everything for good, when the page is taken apart: no reading is waited for, the one on its way is cancelled and the
         * addresses made for the thumbnails are revoked.
         *
         * @returns {void}
         */
        stopPhotoEntry() {
            accepted = null;
            this.resetPhotoEntry();
        },

        /**
         * Forgets what the dialog held: the work, the photos (their thumbnails are revoked), the review and the failure.
         *
         * @returns {void}
         */
        resetPhotoEntry() {
            cancelWork();

            for (const photo of this.photoPhotos) {
                URL.revokeObjectURL(photo.url);
            }

            originals.clear();
            prepared.clear();
            analysisId = null;
            failures = 0;
            this.photoPhotos = [];
            this.clearPhotoOutcome();
            this.photoStep = 'choose';
            this.photoBusy = '';
            this.photoMessage = '';
            announced += 1;
            this.photoStatus = '';
        },

        /**
         * Forgets the review and the failure: the dialog is about to look at the photos again.
         *
         * @returns {void}
         */
        clearPhotoOutcome() {
            this.photoRows = [];
            this.photoChecked = {};
            this.photoFailure = { text: '', retry: false, resume: false };
        },

        /**
         * Takes the photos the person chose.
         *
         * @param {Event} event The `change` of the file chooser.
         * @returns {void}
         */
        choosePhotos(event) {
            const input = /** @type {HTMLInputElement} */ (event.target);
            const files = [...(input.files ?? [])];

            input.value = '';
            this.addPhotos(files);
        },

        /**
         * Adds photos to the ones chosen, as many as an analysis takes. The ones that do not fit, and the files that are no
         * image, are left out, and the person is told.
         *
         * @param {File[]} files
         * @returns {void}
         */
        addPhotos(files) {
            if (this.photoWorking) {
                return;
            }

            let room = this.photoRoom;
            let message = '';

            for (const file of files) {
                if (!looksLikeImage(file)) {
                    message ||= t('photoEntry.error.not_image', { name: file.name });
                } else if (room <= 0) {
                    message = t('photoEntry.error.too_many', { count: this.photoMax });
                } else {
                    room -= 1;
                    counter += 1;

                    const key = `photo-${counter}`;

                    originals.set(key, file);
                    this.photoPhotos.push({
                        key,
                        name: file.name,
                        url: URL.createObjectURL(file),
                        removeLabel: t('photoEntry.remove_named', { name: file.name }),
                    });
                }
            }

            this.photoMessage = message;
        },

        /**
         * Takes a photo back, and puts the cursor on the file chooser.
         *
         * @param {ChosenPhoto} photo
         * @returns {void}
         */
        removePhoto(photo) {
            if (this.photoWorking) {
                return;
            }

            URL.revokeObjectURL(photo.url);
            originals.delete(photo.key);
            prepared.delete(photo.key);
            this.photoPhotos = this.photoPhotos.filter((one) => one.key !== photo.key);
            this.photoMessage = '';
            this.focusPhoto('#photo-entry-pick');
        },

        /**
         * Sends the photos for the analysis: each is redrawn as a JPEG of at most 1568 pixels, they go up together, and the dialog
         * goes on with the analysis the server started.
         *
         * @returns {Promise<void>}
         */
        async analysePhotos() {
            if (!this.canAnalysePhotos) {
                return;
            }

            cancelWork();

            const mine = run;
            const controller = new AbortController();

            working = controller;
            this.clearPhotoOutcome();
            this.photoMessage = '';
            this.goToPhotoStep('working');
            this.photoBusy = 'preparing';
            this.announcePhoto(t('photoEntry.working.preparing'));

            try {
                const files = [];

                for (const photo of [...this.photoPhotos]) {
                    let file = prepared.get(photo.key);

                    if (file === undefined) {
                        file = await prepareAnalysisPhoto(/** @type {File} */ (originals.get(photo.key)));

                        if (mine !== run) {
                            return;
                        }

                        prepared.set(photo.key, file);
                    }

                    files.push(file);
                }

                const body = new FormData();

                for (const file of files) {
                    body.append('photos[]', file, file.name);
                }

                this.photoBusy = 'uploading';
                this.announcePhoto(t('photoEntry.working.uploading'));

                const size = files.reduce((total, file) => total + file.size, 0);
                const answer = await http.post(this.photoEntryUrl, body, {
                    signal: controller.signal,
                    timeout: uploadTimeoutFor(size),
                });

                if (mine !== run) {
                    return;
                }

                analysisId = answer.data.id;
                startedAt = Date.now();
                failures = 0;
                this.photoBusy = 'analysing';
                this.announcePhoto(t('photoEntry.working.analysing'));
                this.followPhotoAnalysis(answer.data);
            } catch (error) {
                if (mine !== run) {
                    return;
                }

                this.onAnalysisRequestFailed(error);
            } finally {
                if (working === controller) {
                    working = null;
                }
            }
        },

        /**
         * Says what came of the request that sends the photos: a photo that cannot be read sends the person back to their choice,
         * an inventory that is gone sends them to the workspace, and a refusal is told in the dialog. A failure that the helper
         * already told with a toast (the network, the server, an ended session) is not told twice, except the throttling, which
         * it only toasts and the person, who is looking at the dialog, needs the wait of: the photos stay chosen, to be sent again.
         *
         * @param {unknown} error
         * @returns {void}
         */
        onAnalysisRequestFailed(error) {
            if (error instanceof UnreadablePhoto) {
                this.photoBusy = '';
                this.photoMessage = t('photoEntry.error.unreadable', { name: error.fileName });
                this.goToPhotoStep('choose');
            } else if (error instanceof HttpError) {
                // A 404 on the address of the analyses is the inventory's own: it is gone, and so is everything on this page.
                if (this.leaveIfGone(error)) {
                    return;
                }

                if (error.reported && error.status !== 429) {
                    this.photoBusy = '';
                    this.goToPhotoStep('choose');

                    return;
                }

                this.failPhotoEntry(requestFailure(error));
            } else if (/** @type {any} */ (error)?.name !== 'AbortError') {
                // Not a failure of the request (the helper lets a cancellation through as an AbortError): a bug, which must show.
                throw error;
            }
        },

        /**
         * Takes what the API says of the analysis: its end is shown, otherwise the next reading is planned.
         *
         * @param {Analysis} analysis
         * @returns {void}
         */
        followPhotoAnalysis(analysis) {
            if (isEnded(analysis)) {
                this.settlePhotoAnalysis(analysis);
            } else {
                this.planPhotoPoll();
            }
        },

        /**
         * Plans the next reading of the analysis, two seconds from now, when the dialog is open and waiting for it; plans nothing
         * otherwise (and forgets the reading that was planned).
         *
         * @returns {void}
         */
        planPhotoPoll() {
            if (pollTimer !== null) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            if (this.photoOpen && this.photoStep === 'working' && analysisId !== null) {
                pollTimer = setTimeout(() => {
                    pollTimer = null;
                    this.readPhotoAnalysis();
                }, POLL_INTERVAL_MS);
            }
        },

        /**
         * Reads the analysis that is on its way, silently (a poll that fails is the dialog's to tell, not a toast every two seconds).
         * A reading that fails because of the connection or the server is tried again a few times; an analysis that is gone, a
         * session that ended or a wait that is too long stops the readings, and the dialog says so.
         *
         * @returns {Promise<void>}
         */
        async readPhotoAnalysis() {
            const id = analysisId;

            if (id === null) {
                return;
            }

            if (Date.now() - startedAt > MAX_WAIT_MS) {
                this.failPhotoEntry({ text: t('photoEntry.error.slow'), retry: true, resume: true });

                return;
            }

            if (pollTimer !== null) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            working?.abort();

            const mine = run;
            const controller = new AbortController();

            working = controller;

            try {
                const answer = await http.get(`${this.photoEntryUrl}/${encodeURIComponent(id)}`, {
                    signal: controller.signal,
                    silent: true,
                });

                if (mine !== run) {
                    return;
                }

                failures = 0;
                this.followPhotoAnalysis(answer.data);
            } catch (error) {
                if (mine !== run) {
                    return;
                }

                this.onPollFailed(error);
            } finally {
                if (working === controller) {
                    working = null;
                }
            }
        },

        /**
         * Says what to do about a reading of the analysis that failed.
         *
         * @param {unknown} error
         * @returns {void}
         */
        onPollFailed(error) {
            if (error instanceof HttpError) {
                if (error.status === 404) {
                    // The analysis is gone (it is kept a day): the photos have to be sent again.
                    this.failPhotoEntry({ text: t('photoEntry.error.gone'), retry: true, resume: false });

                    return;
                }

                // The session ended or the right is gone: a silent reading shows nothing by itself, so the dialog says it and the page
                // does what it does for any other call (a reload, the login page), instead of staying up with a retry that cannot work.
                if (STALE_STATUSES.includes(error.status)) {
                    leaveStalePage(error);
                    this.failPhotoEntry({ text: error.message, retry: false, resume: false });

                    return;
                }

                failures += 1;

                if (failures >= MAX_POLL_FAILURES) {
                    this.failPhotoEntry({ text: t('photoEntry.error.lost'), retry: true, resume: true });
                } else {
                    this.planPhotoPoll();
                }
            } else if (/** @type {any} */ (error)?.name !== 'AbortError') {
                // Not a failure of the request (the helper lets a cancellation through as an AbortError): a bug, which must show.
                throw error;
            }
        },

        /**
         * Shows the end of the analysis: the review of what Claude proposes, or the reason it failed.
         *
         * @param {Analysis} analysis An analysis that has ended.
         * @returns {void}
         */
        settlePhotoAnalysis(analysis) {
            if (analysis.status === 'failed') {
                this.failPhotoEntry(failureOf(analysis));

                return;
            }

            this.photoRows = reviewRows(analysis.proposals, this.fields);
            this.photoChecked = {};
            this.photoBusy = '';
            this.goToPhotoStep('review');
            this.announcePhoto(tn('photoEntry.review.found', this.photoRows.length));
        },

        /**
         * Shows that something went wrong, and what the dialog offers about it.
         *
         * @param {Failure} failure
         * @returns {void}
         */
        failPhotoEntry(failure) {
            if (pollTimer !== null) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            this.photoBusy = '';
            this.photoFailure = failure;
            this.goToPhotoStep('error');
            this.announcePhoto(failure.text);
        },

        /**
         * Tries again after a failure: the analysis already started is followed once more when that is what failed, otherwise the
         * photos are sent again.
         *
         * @returns {void}
         */
        retryPhotoAnalysis() {
            if (this.photoWorking) {
                return;
            }

            if (this.photoFailure.resume && analysisId !== null) {
                failures = 0;
                startedAt = Date.now();
                this.photoFailure = { text: '', retry: false, resume: false };
                this.photoBusy = 'analysing';
                this.goToPhotoStep('working');
                this.announcePhoto(t('photoEntry.working.analysing'));
                this.readPhotoAnalysis();
            } else {
                this.analysePhotos();
            }
        },

        /**
         * Gives up the analysis (or leaves the review, or the failure) and goes back to the photos, which stay chosen.
         *
         * @returns {void}
         */
        backToPhotos() {
            cancelWork();
            analysisId = null;
            this.photoBusy = '';
            this.clearPhotoOutcome();
            this.photoMessage = '';
            this.goToPhotoStep('choose');
        },

        /**
         * Whether the person checked the proposal of a row.
         *
         * @param {{ key: string }} row
         * @returns {boolean}
         */
        isProposalChecked(row) {
            return this.photoChecked[row.key] === true;
        },

        /**
         * Takes the check of a row as the person made it.
         *
         * @param {{ key: string }} row
         * @param {Event} event The `change` of the checkbox of the row.
         * @returns {void}
         */
        toggleProposal(row, event) {
            this.photoChecked[row.key] = /** @type {HTMLInputElement} */ (event.target).checked;
        },

        /**
         * Ends the review: remembers what the person accepted, and closes the dialog. The form of the new item opens when the
         * dialog has been hidden ({@link onPhotoEntryHidden}).
         *
         * @returns {void}
         */
        continuePhotoEntry() {
            if (this.photoStep !== 'review') {
                return;
            }

            accepted = {
                entries: this.photoRows
                    .filter((row) => this.photoChecked[row.key] === true)
                    .map((row) => ({ fieldId: row.key, value: row.value })),
                photos: this.photoPhotos.map((photo) => ({
                    original: /** @type {File} */ (originals.get(photo.key)),
                    prepared: prepared.get(photo.key),
                })),
            };
            hideModal(this.$refs.photoModal);
        },

        /**
         * Opens the form of a new item with what the person accepted: the checked values, and the photos in the first photo field of
         * the inventory (as many as it takes: the person is told when some are left out).
         *
         * @param {Accepted} choice
         * @returns {Promise<void>}
         */
        async createFromPhotos(choice) {
            if (!this.canAdd) {
                return;
            }

            this.startCreate();

            for (const { fieldId, value } of choice.entries) {
                const field = this.fields.find((one) => one.id === fieldId);

                if (field !== undefined) {
                    this.set(field, toInput(field, value));
                }
            }

            const target = this.fields.find((field) => field.type === 'photo');

            if (target === undefined || choice.photos.length === 0) {
                return;
            }

            // The original, unless the photo field would refuse it (a HEIC photo): then the JPEG that was sent for the analysis.
            const files = choice.photos
                .map(({ original, prepared: sent }) =>
                    PHOTO_TYPES.includes(original.type) ? original : (sent ?? original),
                )
                .slice(0, this.roomIn(target));
            const left = choice.photos.length - files.length;

            if (files.length > 0) {
                await this.receive(files, target.id);
            }

            if (left > 0) {
                notify(
                    'warning',
                    tn('photoEntry.notice.left_out', left, { name: target.name, max: maxFilesOf(target) }),
                );
            }
        },

        /**
         * Shows a step and puts the cursor where that step starts: the file chooser for the choice, the heading of the others.
         *
         * @param {'choose' | 'working' | 'review' | 'error'} step
         * @returns {void}
         */
        goToPhotoStep(step) {
            const refs = this.$refs;

            this.photoStep = step;
            this.$nextTick(() => {
                const selector =
                    step === 'choose' ? '#photo-entry-pick' : `[data-photo-step="${step}"] [data-photo-heading]`;

                refs.photoModal?.querySelector?.(selector)?.focus();
            });
        },

        /**
         * Puts the cursor on an element of the dialog.
         *
         * @param {string} selector
         * @returns {void}
         */
        focusPhoto(selector) {
            const refs = this.$refs;

            this.$nextTick(() => {
                refs.photoModal?.querySelector?.(selector)?.focus();
            });
        },

        /**
         * Says something to the live region of the dialog.
         *
         * @param {string} text
         * @returns {void}
         */
        announcePhoto(text) {
            const ticket = (announced += 1);

            if (this.photoStatus !== text) {
                this.photoStatus = text;

                return;
            }

            // The same words again (a retry that fails the way it did): a live region only speaks when its text changes, so it is
            // emptied first and the words come back once the page has drawn the empty region.
            this.photoStatus = '';
            this.$nextTick(() => {
                if (ticket === announced) {
                    this.photoStatus = text;
                }
            });
        },
    };
}
