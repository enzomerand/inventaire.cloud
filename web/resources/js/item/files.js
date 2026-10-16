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
import { HttpError, UPLOAD_TIMEOUT_MS, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { momentText } from '../grid/rows.js';

/**
 * @typedef {import('../fields/types.js').Field} Field
 * @typedef {import('../fields/cell.js').FileEntry} FileEntry
 * @typedef {import('../fields/files.js').Attachment} Attachment
 *
 * @typedef {object} Upload A file on its way up, or one that was refused.
 * @property {string} key
 * @property {string} name
 * @property {string | null} field The id of the field it is for, null for the item itself.
 * @property {'sending' | 'error'} state
 * @property {string} message Why it failed ("" while it is on its way).
 *
 * @typedef {object} Staged A file chosen while an item is added, kept until the item exists.
 * @property {string} key
 * @property {string | null} field
 * @property {File} file
 */

/** The names the API gives the errors of an upload: the file itself, and the field it is for. */
const UPLOAD_FIELDS = ['file', 'field'];

/**
 * Which icon stands for a file that is not on the server yet.
 *
 * @param {string} mime The type the browser gave the file ("" when it did not).
 * @returns {'image' | 'pdf' | 'file'}
 */
function iconOfMime(mime) {
    return PHOTO_TYPES.includes(mime) ? 'image' : mime === 'application/pdf' ? 'pdf' : 'file';
}

/**
 * A file the browser holds, shown like one the server holds (a name, a size, an icon; no thumbnail, no link).
 *
 * @param {Staged} staged
 * @returns {FileEntry}
 */
function stagedEntry(staged) {
    return {
        id: staged.key,
        name: staged.file.name,
        mime: staged.file.type,
        size: staged.file.size,
        sizeText: formatSize(staged.file.size),
        icon: iconOfMime(staged.file.type),
        download: '',
        thumb: null,
        href: null,
        target: null,
    };
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

        /** Whether a file is on its way up. */
        get uploading() {
            return this.uploads.some((upload) => upload.state === 'sending');
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

        /** Whether the item has no attachment and none is on its way. */
        get noAttachments() {
            return this.attachments.length === 0 && this.uploads.length === 0;
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
         * How many more files a field takes: what it can hold, less what it holds, has chosen and is receiving.
         *
         * @param {Field} field
         * @returns {number}
         */
        roomIn(field) {
            const taken =
                this.filesOf(field).length +
                this.stagedOf(field).length +
                this.uploadsOf(field).filter((upload) => upload.state === 'sending').length;

            return Math.max(0, maxFilesOf(field) - taken);
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
         * How full a field is, as a sentence ("2 of 5"): what it holds, has chosen and is receiving, out of what it can hold.
         *
         * @param {Field} field
         * @returns {string}
         */
        countText(field) {
            const max = maxFilesOf(field);

            return t('files.count', { count: max - this.roomIn(field), max });
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
            let room = field === undefined ? Infinity : this.roomIn(field);
            /** @type {Staged[]} */
            const accepted = [];

            for (const file of files) {
                const key = 'file-' + ++this.fileCounter;
                const refusal = this.refusalFor(file, field, room);

                if (refusal !== null) {
                    this.uploads.push({ key, name: file.name, field: fieldId, state: 'error', message: refusal });
                } else {
                    room -= 1;
                    accepted.push({ key, field: fieldId, file });
                }
            }

            if (this.mode === 'create') {
                this.staged.push(...accepted);
            } else if (this.itemId !== null) {
                await this.sendFiles(this.itemId, accepted);
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
         * @returns {Promise<number>} How many files could not be sent.
         */
        async sendStaged(itemId) {
            const files = this.staged;

            this.staged = [];

            return this.sendFiles(itemId, files);
        },

        /**
         * Sends files to an item, one after the other (the server counts the space of the workspace under a lock), each with
         * its own spinner and its own error.
         *
         * @param {string} itemId
         * @param {Staged[]} files
         * @returns {Promise<number>} How many files could not be sent.
         */
        async sendFiles(itemId, files) {
            let failed = 0;

            for (const one of files) {
                if (!(await this.sendFile(itemId, one))) {
                    failed += 1;
                }
            }

            this.restoreFocus(files[files.length - 1]?.field ?? null);

            return failed;
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
         * the dialog and the table hold), or with a refusal that stays on the row of the file.
         *
         * @param {string} itemId
         * @param {Staged} one
         * @returns {Promise<boolean>} Whether the file was added.
         */
        async sendFile(itemId, one) {
            const token = this.dialogToken;
            const name = one.file.name;

            this.uploads.push({ key: one.key, name, field: one.field, state: 'sending', message: '' });

            try {
                const body = new FormData();

                body.append('file', one.file);

                if (one.field !== null) {
                    body.append('field', one.field);
                }

                const answer = await http.post(fillUrl(this.itemUrl, itemId) + '/attachments', body, {
                    timeout: UPLOAD_TIMEOUT_MS,
                });

                this.dropUpload(one.key);
                this.applyItem(answer?.data, token);

                return true;
            } catch (error) {
                const reason = this.uploadFailure(error);

                if (token === this.dialogToken) {
                    this.setUpload(one.key, { state: 'error', message: reason });
                } else {
                    // The dialog moved on: the row that would have said it is gone.
                    notify('warning', t('files.notice.upload_failed', { name, reason }));
                }

                if (error instanceof HttpError && error.status === 404) {
                    this.totalsFor = null;
                    this.loadItems(this.page);
                }

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
                const limit = error.body?.limit ?? '';

                switch (error.code) {
                    case 'attachment_limit_reached':
                        return t('files.error.item_limit', { count: limit });
                    case 'field_file_limit_reached':
                        return t('files.error.field_limit', { count: limit });
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
         * Shows an item as the server holds it now: in the table and the gallery, and in the dialog when it is about that item
         * (an answer that comes after the dialog moved on to another item leaves the dialog alone). The history of the item
         * is read again the next time it is shown.
         *
         * @param {{ id: string, attachments?: Attachment[] } | null | undefined} item
         * @param {number} [token] The dialog the answer is for.
         * @returns {void}
         */
        applyItem(item, token = this.dialogToken) {
            if (item === null || item === undefined || typeof item.id !== 'string') {
                return;
            }

            const index = this.items.findIndex((one) => one.id === item.id);

            if (index !== -1) {
                this.items.splice(index, 1, item);
                this.rebuild();
            }

            if (token === this.dialogToken && this.itemId === item.id) {
                this.dialogItem = item;
                this.details = this.cellsOf(item, true);
                this.markHistoryStale();
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

                this.applyItem(answer?.data, token);
                this.removing = null;
                notify('success', t('files.notice.removed'));
                this.focusTab();
            } catch (error) {
                this.removing = null;

                if (error instanceof HttpError && error.status === 404) {
                    // The file (or the item) is already gone: what is shown is not what the server holds any more.
                    if (!error.reported) {
                        notify('warning', error.message);
                    }

                    this.totalsFor = null;
                    await this.loadItems(this.page);
                    this.syncDialogItem();
                } else {
                    this.fail(error);
                }
            } finally {
                this.busy = false;
            }
        },

        /**
         * Shows the item of the dialog as the list holds it now; the dialog closes when the item is not on the page any more.
         *
         * @returns {void}
         */
        syncDialogItem() {
            const fresh = this.items.find((one) => one.id === this.itemId);

            if (fresh !== undefined) {
                this.dialogItem = fresh;
                this.details = this.cellsOf(fresh, true);
                this.markHistoryStale();
            }
        },

        /**
         * Forgets the files, as the dialog opens or closes: those chosen and not sent, the rows of the ones that failed, the
         * question. A file still on its way finishes, but the dialog does not show it.
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
        },

        /**
         * The number of files of the dialog that failed, for the sentence that tells the person.
         *
         * @param {number} count
         * @returns {string}
         */
        partialNotice(count) {
            return tn('files.notice.partial', count);
        },
    };
}
