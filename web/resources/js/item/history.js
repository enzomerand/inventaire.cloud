/**
 * The history of an item: who changed what, and when.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`). The API gives the revisions of an item newest
 * first, 25 to a page (`GET .../items/{item}/revisions`); the dialog reads the first page when the history is first shown,
 * adds the next page on "Load more", and reads again from the start when the item changed since (a file added, say). Each
 * revision is a title ("Ada · Oct 15, 2026, 10:00 AM") and the lines that say what happened ("Price: 10 → 12", "File added:
 * invoice.pdf"). The values arrive presented like those of an item (through the current definition of their field), so they
 * are shown with the field types' own formatting. A change whose field no longer exists comes with raw stored values the
 * server cannot present: it is told as "Deleted field", and the values are not shown. Authors that no longer exist are named
 * as such. Everything is data for `x-text`, never markup.
 */
import { describeValue } from '../fields/index.js';
import { fillUrl } from '../lib/forms.js';
import { http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { cellValueText, momentText } from '../grid/rows.js';

/** What stands between who made a change and when, in the title of a revision. */
const TITLE_SEPARATOR = ' · ';

/**
 * @typedef {import('../fields/types.js').Field} Field
 *
 * @typedef {object} RevisionField The field a change is about, as the history names it.
 * @property {string} id
 * @property {string} name
 * @property {string} type
 *
 * @typedef {object} ValueChange One value that changed (`created` and `updated`).
 * @property {RevisionField | null} field Null when the field was deleted since: the values are then the stored ones.
 * @property {any} before The value before (null when there was none, and for the creation of the item).
 * @property {any} after The value after.
 *
 * @typedef {object} AttachmentChange The file that was added or removed (`attachment_added` and `attachment_removed`).
 * @property {{ id: string | null, name: string | null }} attachment
 * @property {RevisionField | null} field The file or photo field it belonged to; null for a plain attachment, or when the field was deleted since.
 *
 * @typedef {object} Revision A revision, as the API describes it.
 * @property {string} id A ULID: it orders the revisions of an item, and tells one from another.
 * @property {'created' | 'updated' | 'attachment_added' | 'attachment_removed' | string} action
 * @property {{ name: string } | null} user Who made the change (only their name is given); null once their account is gone.
 * @property {string} created_at
 * @property {(ValueChange | AttachmentChange)[]} changes A list in both cases: the values that changed, or the one file.
 *
 * @typedef {object} RevisionRow A revision as the dialog shows it.
 * @property {string} id
 * @property {string} title Who and when.
 * @property {{ key: string, text: string }[]} lines What happened.
 */

/**
 * The line that says how one value changed.
 *
 * The field is the current definition from the inventory when it is there (it knows the choices, the currency...), else what
 * the revision says of it. A field that was deleted since has no definition to show its values with, and the values the server
 * gives for it are raw: only its absence is told.
 *
 * @param {ValueChange} change
 * @param {Field[]} fields The fields of the inventory.
 * @param {boolean} created Whether the item was created with this value (there is no "before" then).
 * @returns {string}
 */
function changeText(change, fields, created) {
    if (change.field === null || change.field === undefined) {
        return t('history.deleted_field');
    }

    const described = change.field;
    const field = fields.find((one) => one.id === described.id) ?? {
        ...described,
        options: {},
        required: false,
        position: 0,
    };

    return created
        ? t('history.value', { field: field.name, value: cellValueText(describeValue(field, change.after)) })
        : t('history.change', {
              field: field.name,
              before: cellValueText(describeValue(field, change.before)),
              after: cellValueText(describeValue(field, change.after)),
          });
}

/**
 * What a revision says happened, one line each.
 *
 * @param {Revision} revision
 * @param {Field[]} fields The fields of the inventory.
 * @returns {{ key: string, text: string }[]}
 */
export function revisionLines(revision, fields) {
    if (revision.action === 'attachment_added' || revision.action === 'attachment_removed') {
        const change = /** @type {AttachmentChange | undefined} */ (revision.changes?.[0]);
        const name = change?.attachment?.name ?? '';

        return [
            {
                key: 'file',
                text: t(revision.action === 'attachment_added' ? 'history.file_added' : 'history.file_removed', {
                    name,
                }),
            },
        ];
    }

    const created = revision.action === 'created';
    const changes = /** @type {ValueChange[]} */ (Array.isArray(revision.changes) ? revision.changes : []);
    const lines = changes.map((change, index) => ({
        key: 'change-' + index,
        text: changeText(change, fields, created),
    }));

    if (created) {
        lines.unshift({ key: 'created', text: t('history.created') });
    } else if (lines.length === 0) {
        lines.push({ key: 'updated', text: t('history.updated') });
    }

    return lines;
}

/**
 * A revision as the dialog shows it.
 *
 * @param {Revision} revision
 * @param {Field[]} fields The fields of the inventory.
 * @returns {RevisionRow}
 */
export function revisionRow(revision, fields) {
    return {
        id: revision.id,
        title: (revision.user?.name ?? t('history.deleted_user')) + TITLE_SEPARATOR + momentText(revision.created_at),
        lines: revisionLines(revision, fields),
    };
}

/**
 * @returns {Record<string, any>} The state, getters and methods to merge into the component.
 */
export default function itemHistory() {
    return {
        /** The revisions read so far, newest first. */
        revisions: [],

        /** The last page of revisions that was read (0 before the first). */
        revisionsPage: 0,

        /** The number of the last page there is. */
        revisionsLast: 0,

        /** True while a page of revisions is being read. */
        revisionsLoading: false,

        /** True when the last reading failed: the tab offers to try again. */
        revisionsFailed: false,

        /** Whether the reading that failed was for the page after the last one (rather than for the list from the start): a retry asks for the same. */
        revisionsFailedMore: false,

        /** True once the first page was read for the item of the dialog. */
        revisionsLoaded: false,

        /** True when the item changed since the history was read: it is read again when shown. */
        revisionsStale: false,

        /** Ticket of the last reading: the answer to an older one (another item, a newer reading) is dropped. */
        revisionsTicket: 0,

        /** Endpoint of the revisions of the item of the dialog: the address of the item, and `/revisions`. */
        get revisionsUrl() {
            return fillUrl(this.itemUrl, this.itemId) + '/revisions';
        },

        /** Whether there is a page of revisions after the last one that was read. */
        get hasMoreRevisions() {
            return this.revisionsLoaded && this.revisionsPage < this.revisionsLast;
        },

        /** Whether the history is known to be empty. */
        get noRevisions() {
            return this.revisionsLoaded && !this.revisionsFailed && this.revisions.length === 0;
        },

        /** Whether the first page is being read (a spinner replaces the list). */
        get firstRevisionsLoading() {
            return this.revisionsLoading && this.revisions.length === 0;
        },

        /** Whether the page after the last one is being read (the button waits). */
        get moreRevisionsLoading() {
            return this.revisionsLoading && this.revisions.length > 0;
        },

        /**
         * Reads the first page when the history is shown, unless it is already known and still right.
         *
         * @returns {Promise<void>}
         */
        async showHistory() {
            if (this.itemId !== null && (!this.revisionsLoaded || this.revisionsStale) && !this.revisionsLoading) {
                await this.loadRevisions(false);
            }
        },

        /**
         * Reads the next page of revisions and adds it to the list.
         *
         * @returns {Promise<void>}
         */
        async loadMoreRevisions() {
            if (this.hasMoreRevisions && !this.revisionsLoading) {
                await this.loadRevisions(true);
            }
        },

        /**
         * Reads a page of revisions: the first (the list starts again) or the one after the last read. A failure is remembered
         * until the next reading; the HTTP helper has told the person what went wrong when it was not the server's refusal.
         *
         * @param {boolean} more Whether to add the next page to the list rather than start again.
         * @returns {Promise<void>}
         */
        async loadRevisions(more) {
            if (this.itemId === null) {
                return;
            }

            const ticket = ++this.revisionsTicket;
            const page = more ? this.revisionsPage + 1 : 1;

            this.revisionsLoading = true;
            this.revisionsFailed = false;

            // A reading from the start answers for the item as it is now: a change that comes while it is on its way is not in
            // it, and must still be told by the flag afterwards (clearing the flag on the answer would lose it).
            if (!more) {
                this.revisionsStale = false;
            }

            try {
                const answer = await http.get(this.revisionsUrl, { params: { page } });

                if (ticket !== this.revisionsTicket) {
                    return;
                }

                const rows = (answer.data ?? []).map((revision) => revisionRow(revision, this.fields));
                // A revision added meanwhile pushes the older ones to the next page: they are not shown twice.
                const known = new Set(more ? this.revisions.map((row) => row.id) : []);

                this.revisions = [...(more ? this.revisions : []), ...rows.filter((row) => !known.has(row.id))];
                this.revisionsPage = answer.meta?.current_page ?? page;
                this.revisionsLast = answer.meta?.last_page ?? this.revisionsPage;
                this.revisionsLoaded = true;
            } catch {
                if (ticket === this.revisionsTicket) {
                    this.revisionsFailed = true;
                    this.revisionsFailedMore = more;

                    // What is shown is still the old history: the next time the tab is shown reads it again.
                    if (!more) {
                        this.revisionsStale = true;
                    }
                }
            } finally {
                if (ticket === this.revisionsTicket) {
                    this.revisionsLoading = false;
                }
            }
        },

        /**
         * Reads again what could not be read (the button that follows a failure): the page after the last one if that is what
         * failed, the list from the start otherwise (the first page, or a reading again of a history that had changed).
         *
         * @returns {Promise<void>}
         */
        async retryRevisions() {
            await this.loadRevisions(this.revisionsFailedMore);
        },

        /**
         * Notes that the item changed: the history is read again the next time it is shown.
         *
         * @returns {void}
         */
        markHistoryStale() {
            this.revisionsStale = true;
        },

        /**
         * Forgets the history, as the dialog opens on another item. An answer still on its way is dropped.
         *
         * @returns {void}
         */
        resetHistory() {
            this.revisionsTicket += 1;
            this.revisions = [];
            this.revisionsPage = 0;
            this.revisionsLast = 0;
            this.revisionsLoading = false;
            this.revisionsFailed = false;
            this.revisionsFailedMore = false;
            this.revisionsLoaded = false;
            this.revisionsStale = false;
        },
    };
}
