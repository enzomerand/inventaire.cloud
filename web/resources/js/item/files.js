/**
 * The files of an item: the photo and file fields of its form, and its attachments tab.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`). A file is never sent with the values of the item:
 * it goes up on its own (`POST .../items/{item}/attachments`, one request per file, with the field it is for) and the
 * answer is the item as it is now, which replaces the one the dialog and the table hold. So a file is on the item as soon
 * as it was sent, whether the form is saved afterwards or not, and is removed by its own request too (after a
 * confirmation, since the file is erased). While an item is added, the files chosen are kept in the browser; the form is
 * sent first, and the files go up right after, one by one, each with its own spinner and its own error: an item whose
 * files failed stays open to see what happened. The browser checks what it can before sending (the size, the kind of
 * image a photo field takes, the room left in the field); the server decides.
 *
 * Files go up in batches (the files chosen together, or the ones kept for an item that was added): every file of a batch
 * shows as waiting from the start, and a batch belongs to the dialog that was open when it began. A dialog that is closed
 * (or opened on something else) while a batch is on its way does not get what the batch has left: the files not yet sent
 * are given up, and the person is told. A refusal that will be the same for the files that follow (the item is gone, the
 * space is full, the uploads are throttled) ends the batch too. An upload that was cut (the connection broke, or the time
 * ran out) may have been stored all the same: the item is read again to see, before the file is declared failed. A file
 * that failed can be sent again; the browser keeps it for that.
 *
 * The endpoints come from the address of the item (`data-item-url` of the component root): `/attachments` to add a file,
 * `/attachments/{attachment}` to remove one. The largest file the server takes is `data-max-attachment-size`, in bytes.
 *
 * Failures the HTTP helper already reported (network, 5xx, expired session...) are told by the helper as well as by the
 * row of the file they are about, which is how a person knows which file it was.
 */
import {
    DEFAULT_MAX_FILE_SIZE,
    PHOTO_TYPES,
    acceptOf,
    attachmentsOfField,
    fileEntry,
    formatSize,
    maxFilesOf,
} from '../fields/files.js';
import { firstMessages, fillUrl } from '../lib/forms.js';
import { HttpError, http, uploadTimeoutFor } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { momentText } from '../grid/rows.js';

/**
 * @typedef {import('../fields/types.js').Field} Field
 * @typedef {import('../fields/cell.js').FileEntry} FileEntry
 * @typedef {import('../fields/files.js').Attachment} Attachment
 *
 * @typedef {object} Upload A file on its way up, waiting for its turn, or one that was refused.
 * @property {string} key
 * @property {string} name
 * @property {string | null} field The id of the field it is for, null for the item itself.
 * @property {File} [file] The file, which the browser keeps so that a failed upload can be sent again (not for a file the browser refused).
 * @property {'queued' | 'sending' | 'error'} state
 * @property {string} message Why it failed ("" while it is on its way).
 * @property {boolean} retryable Whether the person may send it again (a failure that can be different the next time).
 *
 * @typedef {object} Staged A file chosen while an item is added, kept until the item exists.
 * @property {string} key
 * @property {string | null} field
 * @property {File} file
 *
 * @typedef {object} UploadResult What came of one file.
 * @property {boolean} added
 * @property {{ reason: string, retryable: boolean } | null} endsBatch Set when the refusal will be the same for the files that follow.
 *
 * @typedef {object} Outcome What came of the files of a batch.
 * @property {number} failed How many could not be sent (the ones given up included).
 * @property {number} abandoned How many were given up without a try, because the dialog moved on.
 */

/** The names the API gives the errors of an upload: the file itself, and the field it is for. */
const UPLOAD_FIELDS = ['file', 'field'];

/**
 * Whether a failure says nothing of what became of the request: the connection broke or the time ran out, so the server may
 * have stored the file all the same.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
function isCutShort(error) {
    return error instanceof HttpError && (error.kind === 'timeout' || error.kind === 'network');
}

/**
 * Whether a refusal will be the same for the files that follow it in a batch: the item is gone, the space it takes
 * files from is full, or the server throttles the uploads (the allowance renews with the minute, not with the next file).
 *
 * @param {unknown} error
 * @returns {boolean}
 */
function endsBatch(error) {
    return (
        error instanceof HttpError &&
        (error.status === 404 ||
            error.status === 429 ||
            (error.status === 409 &&
                (error.code === 'storage_limit_reached' || error.code === 'attachment_limit_reached')))
    );
}

/**
 * Whether sending the same file again can end differently: the connection broke or the time ran out, the server throttles
 * the uploads (429; the allowance renews with the minute) or it failed on its own side (5xx). A refusal of the request itself
 * (a type it does not take, a file too large: 422; a limit: 409; an item that is gone: 404) will be the same every time.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
function isRetryable(error) {
    return error instanceof HttpError && (isCutShort(error) || error.status === 429 || error.status >= 500);
}

/**
 * A file the browser holds, shown like one the server holds (a name, a size, an icon; no thumbnail, no link).
 *
 * @param {Staged} staged
 * @returns {FileEntry}
 */
function stagedEntry(staged) {
    return fileEntry({
        id: staged.key,
        name: staged.file.name,
        mime: staged.file.type,
        size: staged.file.size,
        has_thumbnail: false,
        url: '',
    });
}

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function itemFiles() {
    return {
        /** The item the dialog is about, as the API describes it (null while it adds one): its attachments are shown from it. */
        dialogItem: null,

        /** The files on their way up and the ones that were refused, in the order they were chosen. */
        uploads: [],

        /** The files chosen while an item is added, which go up once it exists. */
        staged: [],

        /** The file the dialog asks to confirm the removal of: `{ id, name }`, null when it does not. */
        removing: null,

        /** The id of the field the file chooser was opened for, null for a file attached to the item itself. */
        pickedField: null,

        /** Counts the dialogs that were opened: an answer that comes after the dialog moved on to another item is not for it. */
        dialogToken: 0,

        /** Counts the files chosen, to tell them apart. */
        fileCounter: 0,

        /** Whether a file was added or removed since the list of items was last read: the list is read again when the dialog closes. */
        filesChanged: false,

        /**
         * What the live region of the dialog says of the uploads ("Sending front.jpg…"). The region is on the page from the
         * start, which is what makes it announce.
         */
        announcement: '',

        /** The size of the largest file the server takes, in bytes, from `data-max-attachment-size` on the component root. */
        get maxFileSize() {
            const size = Number(this.rootElement.dataset.maxAttachmentSize);

            return Number.isFinite(size) && size > 0 ? size : DEFAULT_MAX_FILE_SIZE;
        },

        /** Endpoint that adds a file to the item of the dialog (POST). */
        get attachmentsUrl() {
            return fillUrl(this.itemUrl, this.itemId) + '/attachments';
        },

        /** Whether the dialog asks for a confirmation: of the deletion of the item, or of the removal of a file. */
        get asking() {
            return this.confirming || this.removing !== null;
        },

        /** Whether the dialog asks to confirm the removal of a file. */
        get isRemoving() {
            return this.removing !== null;
        },

        /** The question asked before a file is removed. */
        get removeText() {
            return this.removing === null ? '' : t('files.confirm_remove', { name: this.removing.name });
        },

        /** Whether a file is on its way up, or waiting for its turn. */
        get uploading() {
            return this.uploads.some((upload) => upload.state === 'sending' || upload.state === 'queued');
        },

        /** The attachments of the item of the dialog. */
        get attachments() {
            return this.dialogItem?.attachments ?? [];
        },

        /** The label of the tab of the attachments, with how many there are. */
        get filesTabLabel() {
            return t('files.tab', { count: this.attachments.length });
        },

        /** What the person is told about when the files of a field are saved: an item that exists saves them at once, an item being added when it is. */
        get fileHint() {
            return t(this.mode === 'create' ? 'files.hint_create' : 'files.hint_edit');
        },

        /** Whether the item has no attachment and none is on its way (the files of the fields are shown in their fields). */
        get noAttachments() {
            return this.attachments.length === 0 && this.itemUploads.length === 0;
        },

        /** Whether the people who may edit are offered to add attachments to the item (it exists, and nothing is sent). */
        get canAttach() {
            return this.canEdit && this.mode === 'edit' && !this.busy && !this.uploading;
        },

        /** The files of the attachments tab: every attachment of the item, with what the list says of it. */
        get attachmentRows() {
            return this.attachments.map((attachment) => {
                const author = attachment.uploaded_by?.name ?? '';

                return {
                    ...fileEntry(attachment, true),
                    fieldName: this.fields.find((field) => field.id === attachment.field)?.name ?? '',
                    added: momentText(attachment.uploaded_at),
                    addedBy: author === '' ? '' : t('files.added_by', { name: author }),
                };
            });
        },

        /**
         * The files a photo or file field holds, in the order of its value.
         *
         * @param {Field} field
         * @returns {FileEntry[]}
         */
        filesOf(field) {
            return attachmentsOfField(field, this.dialogItem?.values?.[field.id], this.attachments).map((attachment) =>
                fileEntry(attachment, true),
            );
        },

        /**
         * The files chosen for a field of an item that is being added.
         *
         * @param {Field} field
         * @returns {FileEntry[]}
         */
        stagedOf(field) {
            return this.staged.filter((one) => one.field === field.id).map(stagedEntry);
        },

        /**
         * The files of a field on their way up, and the ones that were refused.
         *
         * @param {Field} field
         * @returns {Upload[]}
         */
        uploadsOf(field) {
            return this.uploads.filter((upload) => upload.field === field.id);
        },

        /** The files on their way up for the item itself (not for a field), and the ones that were refused. */
        get itemUploads() {
            return this.uploads.filter((upload) => upload.field === null);
        },

        /**
         * How many files a field has: the ones it holds, the ones chosen for it and the ones on their way up or waiting to be.
         * (More than it can hold when its limit was lowered after files were added.)
         *
         * @param {Field} field
         * @returns {number}
         */
        takenIn(field) {
            return (
                this.filesOf(field).length +
                this.stagedOf(field).length +
                this.uploadsOf(field).filter((upload) => upload.state === 'sending' || upload.state === 'queued').length
            );
        },

        /**
         * How many more files a field takes: what it can hold, less what it has.
         *
         * @param {Field} field
         * @returns {number}
         */
        roomIn(field) {
            return Math.max(0, maxFilesOf(field) - this.takenIn(field));
        },

        /**
         * Whether the form offers to add a file to a field: it can take one more, and nothing is being sent.
         *
         * @param {Field} field
         * @returns {boolean}
         */
        canAddTo(field) {
            return this.canEdit && !this.busy && !this.uploading && this.roomIn(field) > 0;
        },

        /**
         * Whether a field takes photos (rather than any file).
         *
         * @param {Field} field
         * @returns {boolean}
         */
        isPhotoField(field) {
            return field.type === 'photo';
        },

        /**
         * The label of the button that adds a file to a field.
         *
         * @param {Field} field
         * @returns {string}
         */
        addLabel(field) {
            return t(field.type === 'photo' ? 'files.add_photo' : 'files.add_file');
        },

        /**
         * How full a field is, as a sentence ("2 of 5"): what it has, out of what it can hold. A field whose limit was lowered
         * below what it holds says so ("3 of 2").
         *
         * @param {Field} field
         * @returns {string}
         */
        countText(field) {
            return t('files.count', { count: this.takenIn(field), max: maxFilesOf(field) });
        },

        /**
         * The accessible name of the button that dismisses the error of a file that was refused.
         *
         * @param {{ name: string }} upload
         * @returns {string}
         */
        dismissLabel(upload) {
            return t('files.dismiss_named', { name: upload.name });
        },

        /**
         * The accessible name of the button that sends a file again.
         *
         * @param {{ name: string }} upload
         * @returns {string}
         */
        retryLabel(upload) {
            return t('files.retry_named', { name: upload.name });
        },

        /**
         * The accessible name of the button that removes a file (or takes back one that was chosen).
         *
         * @param {{ name: string }} file
         * @returns {string}
         */
        removeFileLabel(file) {
            return t('files.remove_named', { name: file.name });
        },

        /**
         * The accessible name of the link that downloads a file.
         *
         * @param {{ name: string }} file
         * @returns {string}
         */
        downloadLabel(file) {
            return t('files.download_named', { name: file.name });
        },

        /**
         * Opens the file chooser of the browser, for a field or for the item itself.
         *
         * @param {string | null} fieldId The field the files are for, null for the item itself.
         * @returns {void}
         */
        pickFiles(fieldId) {
            const picker = this.$refs.picker;
            const field = this.fields.find((one) => one.id === fieldId);

            if (!(picker instanceof HTMLInputElement)) {
                return;
            }

            this.pickedField = fieldId;
            picker.accept = field === undefined ? '' : acceptOf(field);
            picker.multiple = field === undefined || this.roomIn(field) > 1;
            picker.value = '';
            picker.click();
        },

        /**
         * Opens the file chooser for a file attached to the item itself.
         *
         * @returns {void}
         */
        pickAttachments() {
            this.pickFiles(null);
        },

        /**
         * Takes the files the person chose.
         *
         * @param {Event} event The `change` of the file chooser.
         * @returns {Promise<void>}
         */
        async onPicked(event) {
            const picker = /** @type {HTMLInputElement} */ (event.target);
            const files = [...(picker.files ?? [])];
            const fieldId = this.pickedField;

            picker.value = '';
            this.pickedField = null;

            if (files.length > 0) {
                await this.receive(files, fieldId);
            }
        },

        /**
         * Checks the files that were chosen, keeps or sends the ones that pass, and shows the ones that do not as errors. An
         * item that is being added keeps them until it exists; an item that exists gets them at once.
         *
         * @param {File[]} files
         * @param {string | null} fieldId The field they are for, null for the item itself.
         * @returns {Promise<void>}
         */
        async receive(files, fieldId) {
            const field = this.fields.find((one) => one.id === fieldId);
            const token = this.dialogToken;
            let room = field === undefined ? Infinity : this.roomIn(field);
            /** @type {Staged[]} */
            const accepted = [];

            for (const file of files) {
                const key = 'file-' + ++this.fileCounter;
                const refusal = this.refusalFor(file, field, room);

                if (refusal !== null) {
                    this.uploads.push({
                        key,
                        name: file.name,
                        field: fieldId,
                        state: 'error',
                        message: refusal,
                        retryable: false,
                    });
                } else {
                    room -= 1;
                    accepted.push({ key, field: fieldId, file });
                }
            }

            if (this.mode === 'create') {
                this.staged.push(...accepted);
            } else if (this.itemId !== null) {
                this.tellAbandoned(await this.sendFiles(this.itemId, accepted, token));
                await this.refreshList();
            }
        },

        /**
         * Why a file cannot be added to a field, as far as the browser can tell.
         *
         * @param {File} file
         * @param {Field | undefined} field Undefined for a file attached to the item itself.
         * @param {number} room How many more files the field takes.
         * @returns {string | null} Null when nothing is wrong.
         */
        refusalFor(file, field, room) {
            if (room <= 0) {
                return t('files.error.field_full');
            }

            if (file.size > this.maxFileSize) {
                return t('files.error.too_large', { max: formatSize(this.maxFileSize) });
            }

            if (field?.type === 'photo' && file.type !== '' && !PHOTO_TYPES.includes(file.type)) {
                return t('files.error.not_photo');
            }

            return null;
        },

        /**
         * Takes back a file that was chosen for an item that does not exist yet.
         *
         * @param {{ id: string }} file The entry of the file (its id is the key it was chosen with).
         * @returns {void}
         */
        unstage(file) {
            const field = this.staged.find((one) => one.key === file.id)?.field ?? null;

            this.staged = this.staged.filter((one) => one.key !== file.id);
            this.restoreFocus(field);
        },

        /**
         * Sends the files chosen while the item was added, now that it exists, one after the other.
         *
         * @param {string} itemId
         * @param {number} token The dialog they were chosen in.
         * @returns {Promise<Outcome>}
         */
        async sendStaged(itemId, token) {
            const files = this.staged;

            this.staged = [];

            return this.sendFiles(itemId, files, token);
        },

        /**
         * Sends files to an item, one after the other (the server counts the space of the workspace under a lock), each with
         * its own spinner and its own error. They all show as waiting from the start.
         *
         * The batch belongs to the dialog it began in (`token`): when that dialog is closed, or opened on something else, the
         * files not sent yet are given up. A refusal that will be the same for the files that follow ends the batch too: they are
         * given the reason, rather than sent to be refused one by one.
         *
         * @param {string} itemId
         * @param {Staged[]} files
         * @param {number} token The dialog the batch belongs to, taken once for the whole batch.
         * @returns {Promise<Outcome>}
         */
        async sendFiles(itemId, files, token) {
            let failed = 0;
            let next = 0;
            /** @type {{ reason: string, retryable: boolean } | null} */
            let ended = null;

            this.queueUploads(files, token);

            while (next < files.length && token === this.dialogToken) {
                const outcome = await this.sendFile(itemId, files[next], token);

                next += 1;

                if (!outcome.added) {
                    failed += 1;
                }

                if (outcome.endsBatch !== null) {
                    ended = outcome.endsBatch;
                    break;
                }
            }

            const unsent = files.slice(next);
            const moved = token !== this.dialogToken;

            if (unsent.length > 0 && !moved) {
                this.failUploads(unsent, ended?.reason ?? t('error.generic'), ended?.retryable ?? false);
            }

            if (!moved) {
                this.restoreFocus(files[files.length - 1]?.field ?? null);
            }

            return { failed: failed + unsent.length, abandoned: moved ? unsent.length : 0 };
        },

        /**
         * Tells the person that files were given up because the dialog they were being sent in was closed.
         *
         * @param {Outcome} outcome
         * @returns {void}
         */
        tellAbandoned(outcome) {
            if (outcome.abandoned > 0) {
                notify('warning', tn('files.notice.abandoned', outcome.abandoned));
            }
        },

        /**
         * Shows files as waiting for their turn: a row for each, or the row it already has (a file sent again).
         *
         * @param {Staged[]} files
         * @param {number} token The dialog they are for: another one has nothing to show.
         * @returns {void}
         */
        queueUploads(files, token) {
            if (token !== this.dialogToken) {
                return;
            }

            for (const one of files) {
                if (this.uploads.some((upload) => upload.key === one.key)) {
                    this.setUpload(one.key, { state: 'queued', message: '', retryable: false });
                } else {
                    this.uploads.push({
                        key: one.key,
                        name: one.file.name,
                        field: one.field,
                        file: one.file,
                        state: 'queued',
                        message: '',
                        retryable: false,
                    });
                }
            }
        },

        /**
         * Marks files that will not be sent as failed, with the reason (they keep their place in the list).
         *
         * @param {Staged[]} files
         * @param {string} reason
         * @param {boolean} retryable Whether they can be sent again (not when the same refusal would come back: item gone, limit reached).
         * @returns {void}
         */
        failUploads(files, reason, retryable) {
            for (const one of files) {
                this.setUpload(one.key, { state: 'error', message: reason, retryable });
            }
        },

        /**
         * Sends a file again, from the row that says it failed.
         *
         * @param {Upload} upload
         * @returns {Promise<void>}
         */
        async retryUpload(upload) {
            if (upload.file === undefined || this.itemId === null || this.busy || this.uploading) {
                return;
            }

            const field = this.fields.find((one) => one.id === upload.field);
            const refusal = this.refusalFor(upload.file, field, field === undefined ? Infinity : this.roomIn(field));

            if (refusal !== null) {
                this.setUpload(upload.key, { message: refusal });

                return;
            }

            const token = this.dialogToken;

            this.tellAbandoned(
                await this.sendFiles(this.itemId, [{ key: upload.key, field: upload.field, file: upload.file }], token),
            );
            await this.refreshList();
        },

        /**
         * Gives the cursor back when what had it is gone from the page (the button that adds a file hides once its field is
         * full, the button that took a chosen file back goes with the file): to the button that adds a file to the field, if
         * it is still there, else to the tab that is shown. A cursor that is still in the dialog is left where it is.
         *
         * @param {string | null} fieldId The field the files were for.
         * @returns {void}
         */
        restoreFocus(fieldId) {
            this.$nextTick(() => {
                const modal = this.$refs.modal;

                if (modal instanceof HTMLElement && modal.contains(document.activeElement)) {
                    return;
                }

                const add = fieldId === null ? null : document.getElementById('item-field-' + fieldId);

                if (add !== null && add.style.display !== 'none') {
                    add.focus();
                } else {
                    this.focusTab();
                }
            });
        },

        /**
         * Sends one file. It shows as on its way until the server answers: with the item as it is now (which replaces the one
         * the dialog and the table hold), or with a refusal that stays on the row of the file. The row is only touched while the
         * dialog the file was chosen in is the one that is open; a failure after that is told by a toast.
         *
         * @param {string} itemId
         * @param {Staged} one
         * @param {number} token The dialog the file belongs to.
         * @returns {Promise<UploadResult>} Whether the file was added, and why the batch ends when the refusal ends it.
         */
        async sendFile(itemId, one, token) {
            const known = this.knownAttachmentIds(itemId);

            if (token === this.dialogToken) {
                this.setUpload(one.key, { state: 'sending', message: '' });
                this.announcement = t('files.status.sending', { name: one.file.name });
            }

            try {
                const body = new FormData();

                body.append('file', one.file);

                if (one.field !== null) {
                    body.append('field', one.field);
                }

                const answer = await http.post(fillUrl(this.itemUrl, itemId) + '/attachments', body, {
                    timeout: uploadTimeoutFor(one.file.size),
                });

                this.uploadDone(one, token);
                await this.applyItem(answer?.data);

                return { added: true, endsBatch: null };
            } catch (error) {
                return this.uploadFailed(itemId, one, error, token, known);
            }
        },

        /**
         * The ids of the attachments an item is known to have, to tell the one an upload added from the ones it had.
         *
         * @param {string} itemId
         * @returns {Set<string>}
         */
        knownAttachmentIds(itemId) {
            const holders = [
                this.items.find((one) => one.id === itemId),
                this.dialogItem?.id === itemId ? this.dialogItem : null,
            ];

            return new Set(holders.flatMap((holder) => (holder?.attachments ?? []).map((one) => one.id)));
        },

        /**
         * A file arrived: its row goes, and the live region says so.
         *
         * @param {Staged} one
         * @param {number} token
         * @returns {void}
         */
        uploadDone(one, token) {
            if (token === this.dialogToken) {
                this.dropUpload(one.key);
                this.announcement = t('files.status.sent', { name: one.file.name });
            }
        },

        /**
         * Deals with an upload that failed: looks whether the server stored the file after all when the request was cut
         * short, and else tells the person on the row of the file (or by a toast when the dialog moved on). An item that is gone
         * is read from the list again, and the dialog closes when it is not there any more.
         *
         * @param {string} itemId
         * @param {Staged} one
         * @param {unknown} error
         * @param {number} token The dialog the file belongs to.
         * @param {Set<string>} known The attachments the item had before the file was sent.
         * @returns {Promise<UploadResult>}
         */
        async uploadFailed(itemId, one, error, token, known) {
            if (isCutShort(error) && (await this.wasStored(itemId, one, known))) {
                this.uploadDone(one, token);

                return { added: true, endsBatch: null };
            }

            const reason = this.uploadFailure(error);
            const gone = error instanceof HttpError && error.status === 404;
            const retryable = isRetryable(error);

            if (token === this.dialogToken) {
                this.setUpload(one.key, { state: 'error', message: reason, retryable });
                this.announcement = '';
            } else {
                // The dialog moved on: the row that would have said it is gone.
                notify('warning', t('files.notice.upload_failed', { name: one.file.name, reason }));
            }

            if (gone) {
                await this.followGone(token);
            }

            return { added: false, endsBatch: endsBatch(error) ? { reason, retryable } : null };
        },

        /**
         * Reads the list again after a refusal that said the item is gone, and settles what the dialog shows. A failure of
         * that is left aside: the row of the file already says what became of it, and the batch it belongs to must go on
         * with the rows it has left (none may stay waiting).
         *
         * @param {number} token The dialog the file belongs to.
         * @returns {Promise<void>}
         */
        async followGone(token) {
            try {
                this.totalsFor = null;
                await this.loadItems(this.page);

                if (token === this.dialogToken) {
                    await this.syncDialogItem();
                }
            } catch {
                // The row of the file says what became of it: the dialog is left as it is.
            }
        },

        /**
         * Reads the item again after an upload that was cut short and shows it: the server may have stored the file all the
         * same, and the answer was lost. The file is in the item when it holds an attachment it did not have, of the same size,
         * for the same field.
         *
         * @param {string} itemId
         * @param {Staged} one
         * @param {Set<string>} known
         * @returns {Promise<boolean>} Whether the file is on the item.
         */
        async wasStored(itemId, one, known) {
            try {
                const fresh = (await http.get(fillUrl(this.itemUrl, itemId), { silent: true }))?.data;

                if (fresh === null || fresh === undefined || typeof fresh.id !== 'string') {
                    return false;
                }

                await this.applyItem(fresh);

                return (fresh.attachments ?? []).some(
                    (attachment) =>
                        !known.has(attachment.id) &&
                        attachment.field === one.field &&
                        attachment.size === one.file.size,
                );
            } catch {
                return false;
            }
        },

        /**
         * Why a file was not added, in words: what the server found wrong with it (a 422), the limit it ran into (a 409), or
         * its general message.
         *
         * @param {unknown} error
         * @returns {string}
         */
        uploadFailure(error) {
            if (!(error instanceof HttpError)) {
                return t('error.generic');
            }

            if (error.status === 422) {
                const messages = firstMessages(error.errors);
                const own = UPLOAD_FIELDS.map((name) => messages[name]).find((message) => message !== undefined);

                return own ?? Object.values(messages)[0] ?? error.message;
            }

            if (error.status === 409) {
                const limit = Number(error.body?.limit);

                switch (error.code) {
                    case 'attachment_limit_reached':
                        return tn('files.error.item_limit', limit);
                    case 'field_file_limit_reached':
                        return tn('files.error.field_limit', limit);
                    case 'storage_limit_reached':
                        return t('files.error.storage_limit');
                    default:
                }
            }

            return error.message;
        },

        /**
         * Changes what is known of a file on its way up.
         *
         * @param {string} key
         * @param {Partial<Upload>} changes
         * @returns {void}
         */
        setUpload(key, changes) {
            this.uploads = this.uploads.map((upload) => (upload.key === key ? { ...upload, ...changes } : upload));
        },

        /**
         * Takes a file off the list of the ones on their way up (it arrived, or the person dismissed its error).
         *
         * @param {string | { key: string }} upload The upload or its key.
         * @returns {void}
         */
        dropUpload(upload) {
            const key = typeof upload === 'string' ? upload : upload.key;

            this.uploads = this.uploads.filter((one) => one.key !== key);
        },

        /**
         * Shows an item as the server holds it now: in the table and the gallery, and in the dialog when it is open on that
         * item (whichever opening of the dialog the answer is for: it is the same item). The history of the item is read again
         * the next time it is shown.
         *
         * A reading of the list that began before the change can answer after it, with the item as it was: it is read again,
         * as `saveInline` does.
         *
         * @param {{ id: string, attachments?: Attachment[] } | null | undefined} item
         * @returns {Promise<void>}
         */
        async applyItem(item) {
            if (item === null || item === undefined || typeof item.id !== 'string') {
                return;
            }

            const index = this.items.findIndex((one) => one.id === item.id);

            this.filesChanged = true;

            if (index !== -1) {
                this.items.splice(index, 1, item);
                this.rebuild();
            }

            if (this.dialogItem !== null && this.itemId === item.id) {
                this.dialogItem = item;
                this.details = this.cellsOf(item, true);
                this.markHistoryStale();
            }

            if (this.loadingItems) {
                this.totalsFor = null;
                await this.loadItems(this.wantedPage);
            }
        },

        /**
         * Reads the list again when files were added or removed and the search or the filters are in force: they can match
         * the item differently now (a filter on the files of a field says "empty"), which moves items in or out of the list and
         * changes how many there are.
         *
         * @returns {Promise<void>}
         */
        async refreshList() {
            if (this.filesChanged && this.hasCriteria) {
                this.totalsFor = null;
                await this.loadItems(this.page);
            }
        },

        /**
         * Asks to confirm the removal of a file: the dialog shows the question in place of the item, with the cursor on the
         * button that confirms.
         *
         * @param {{ id: string, name: string }} file
         * @returns {void}
         */
        askRemove(file) {
            this.removing = { id: file.id, name: file.name };
            this.$nextTick(() => this.$refs.confirmRemove?.focus());
        },

        /**
         * Gives up removing the file, and puts the cursor back on the tab that is shown.
         *
         * @returns {void}
         */
        cancelRemove() {
            this.removing = null;
            this.focusTab();
        },

        /**
         * Removes the file, once confirmed. The answer is the item without it.
         *
         * @returns {Promise<void>}
         */
        async confirmRemove() {
            if (this.busy || this.removing === null || this.itemId === null) {
                return;
            }

            const { id } = this.removing;
            const token = this.dialogToken;

            this.busy = true;

            try {
                const answer = await http.delete(this.attachmentsUrl + '/' + encodeURIComponent(id));

                await this.applyItem(answer?.data);

                if (token === this.dialogToken) {
                    this.removing = null;
                    this.focusTab();
                }

                notify('success', t('files.notice.removed'));
                await this.refreshList();
            } catch (error) {
                if (token === this.dialogToken) {
                    this.removing = null;
                }

                if (error instanceof HttpError && error.status === 404) {
                    // The file (or the item) is already gone: what is shown is not what the server holds any more.
                    if (!error.reported) {
                        notify('warning', error.message);
                    }

                    this.totalsFor = null;
                    await this.loadItems(this.page);

                    if (token === this.dialogToken) {
                        await this.syncDialogItem();
                    }
                } else {
                    this.fail(error);
                }
            } finally {
                this.busy = false;
            }
        },

        /**
         * Shows the item of the dialog as the server holds it now, once a refusal said that something is gone (the item or a
         * file of it). The dialog closes only when the server answers that the item itself is gone: the list says nothing of
         * it when it is merely on another page, or no longer matches the search. When the server cannot say (the connection
         * broke), the dialog shows the item as the list holds it, or is left as it is.
         *
         * @returns {Promise<void>}
         */
        async syncDialogItem() {
            const itemId = this.itemId;
            const token = this.dialogToken;
            let fresh = this.items.find((one) => one.id === itemId);

            try {
                const answered = (await http.get(fillUrl(this.itemUrl, itemId), { silent: true }))?.data;

                if (answered?.id === itemId) {
                    fresh = answered;
                }
            } catch (error) {
                if (token === this.dialogToken && error instanceof HttpError && error.status === 404) {
                    this.closeDialog();

                    return;
                }
            }

            // The dialog moved on while the server was asked.
            if (token !== this.dialogToken || fresh === undefined) {
                return;
            }

            this.dialogItem = fresh;
            this.details = this.cellsOf(fresh, true);
            this.markHistoryStale();
        },

        /**
         * Forgets the files, as the dialog opens or closes: those chosen and not sent, the rows of the ones that failed, the
         * question. A file still on its way finishes, but the dialog does not show it, and the batch it belongs to gives up the
         * files it had not sent yet.
         *
         * @returns {void}
         */
        resetFiles() {
            this.dialogToken += 1;
            this.dialogItem = null;
            this.uploads = [];
            this.staged = [];
            this.removing = null;
            this.pickedField = null;
            this.announcement = '';
        },
    };
}
