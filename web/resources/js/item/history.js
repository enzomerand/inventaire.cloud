/**
 * The history of an item: who changed what, and when.
 *
 * It is a piece of the `inventoryItems` component (see `lib/compose.js`). The API gives the revisions of an item newest
 * first, 25 to a page (`GET .../items/{item}/revisions`); the dialog reads the first page when the history is first shown,
 * adds the next page on "Load more", and reads again from the start when the item changed since (a file added, say). Each
 * revision is a title ("Ada · Oct 15, 2026, 10:00 AM") and the lines that say what happened ("Price: 10 → 12", "File added:
 * invoice.pdf"). The values arrive presented like those of an item, so they are shown with the field types' own formatting.
 * Authors and fields that no longer exist are named as such. Everything is data for `x-text`, never markup.
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
 * @typedef {object} Revision A revision, as the API describes it.
 * @property {number | string} id
 * @property {'created' | 'updated' | 'attachment_added' | 'attachment_removed' | string} action
 * @property {{ id: string, name: string } | null} user Who made the change; null once their account is gone.
 * @property {string} created_at
 * @property {{ field: string | null, before: any, after: any }[] | { attachment: string, name: string, field: string | null }} changes
 *   The values that changed (null for `field` when the field no longer exists), or the file that came or went.
 *
 * @typedef {object} RevisionRow A revision as the dialog shows it.
 * @property {string} id
 * @property {string} title Who and when.
 * @property {{ key: string, text: string }[]} lines What happened.
 */

/**
 * A stored value as a short text, whatever the field it belonged to: the field's own formatting when the field still
 * exists, else the value as it was stored.
 *
 * @param {Field | undefined} field
 * @param {any} value
 * @returns {string}
 */
function valueText(field, value) {
    if (field !== undefined) {
        return cellValueText(describeValue(field, value));
    }

    if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
        return t('inventories.item.none');
    }

    return Array.isArray(value) ? value.join(', ') : typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/**
 * The line that says how one value changed.
 *
 * @param {{ field: string | null, before: any, after: any }} change
 * @param {Field[]} fields The fields of the inventory.
 * @param {boolean} created Whether the item was created with this value (there is no "before" then).
 * @returns {string}
 */
function changeText(change, fields, created) {
    const field = change.field === null ? undefined : fields.find((one) => one.id === change.field);
    const name = field?.name ?? t('history.deleted_field');

    return created
        ? t('history.value', { field: name, value: valueText(field, change.after) })
        : t('history.change', {
              field: name,
              before: valueText(field, change.before),
              after: valueText(field, change.after),
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
        const name = Array.isArray(revision.changes) ? '' : (revision.changes?.name ?? '');

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
    const changes = Array.isArray(revision.changes) ? revision.changes : [];
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
        id: String(revision.id),
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
                this.revisionsStale = false;
            } catch {
                if (ticket === this.revisionsTicket) {
                    this.revisionsFailed = true;
                }
            } finally {
                if (ticket === this.revisionsTicket) {
                    this.revisionsLoading = false;
                }
            }
        },

        /**
         * Reads the history again from the start (the button that follows a failure).
         *
         * @returns {Promise<void>}
         */
        async retryRevisions() {
            await this.loadRevisions(this.revisionsLoaded);
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
            this.revisionsLoaded = false;
            this.revisionsStale = false;
        },
    };
}
