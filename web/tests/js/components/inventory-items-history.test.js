/**
 * The tabs and the history of the dialog of an item, at the level of the component: which tab is shown, when the history is
 * read, how the pages of revisions are added, and what a failure leaves.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import {
    FIELDS,
    INVENTORY,
    ITEMS,
    deferred,
    inventoryAnswer,
    item,
    mountComponent,
    page,
    ulid,
} from '../support/inventory.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const REVISIONS = `${ITEMS}/i1/revisions`;

/** A revision as the API describes it. */
function revision(id, extra = {}) {
    return {
        id: ulid('r' + id),
        action: 'updated',
        user: { name: 'Ada' },
        created_at: '2026-10-15T10:00:00Z',
        changes: [{ field: { id: 'name', name: 'Name', type: 'text' }, before: 'Hammer', after: 'Claw hammer' }],
        ...extra,
    };
}

/** What `GET .../revisions` answers for a page. */
function revisionPage(revisions, current = 1, last = 1) {
    return { data: revisions, meta: { current_page: current, last_page: last, total: revisions.length } };
}

describe('the history of an item', () => {
    let component;

    /** Opens the page with two items and the dialog on the first; `answers` says what each page of revisions holds. */
    async function open(answers = [revisionPage([revision(2), revision(1)])], options = {}) {
        component = mountComponent(inventoryItems, options);
        const queue = [...answers];

        http.get.mockImplementation(async (url, request) => {
            if (url === INVENTORY) {
                return inventoryAnswer(FIELDS, 2);
            }

            if (url === REVISIONS) {
                const next = queue.shift();

                if (next instanceof Error) {
                    throw next;
                }

                return next ?? revisionPage([]);
            }

            return page([item('i1', { name: 'Hammer' }), item('i2', { name: 'Saw' })], request);
        });
        await component.init();
        component.open({ id: 'i1' });
    }

    /** The reads of the revisions so far. */
    const reads = () => http.get.mock.calls.filter((call) => call[0] === REVISIONS);

    beforeEach(() => {
        http.get.mockReset();
    });

    describe('the tabs of the dialog', () => {
        it('go back to the details whenever the dialog opens', async () => {
            await open();
            component.selectTab('files');

            component.begin('edit', component.items[1]);

            expect(component.tab).toBe('details');
        });

        it('hide while the dialog asks to confirm the deletion of the item', async () => {
            await open();

            component.askDelete();

            expect(component.asking).toBe(true);
            expect(component.showTabs).toBe(false);
            expect(component.canDeleteItem).toBe(false);

            component.cancelDelete();

            expect(component.showTabs).toBe(true);
        });
    });

    describe('reading it', () => {
        it('reads nothing until the history is shown', async () => {
            await open();

            expect(reads()).toHaveLength(0);
        });

        it('reads the first page of the revisions of the item when the tab is shown', async () => {
            await open();

            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            expect(reads()).toEqual([[REVISIONS, { params: { page: 1 } }]]);
            expect(component.revisions.map((row) => row.id)).toEqual([ulid('r2'), ulid('r1')]);
            expect(component.revisions[0].lines.map((line) => line.text)).toEqual(['Name: Hammer → Claw hammer']);
            expect(component.noRevisions).toBe(false);
        });

        it('shows a spinner in place of the list for the first page only', async () => {
            const reading = deferred();
            await open([]);
            http.get.mockImplementation(async (url) => (url === REVISIONS ? reading.promise : page([])));

            component.selectTab('history');

            expect(component.revisionsLoading).toBe(true);
            expect(component.firstRevisionsLoading).toBe(true);
            expect(component.moreRevisionsLoading).toBe(false);

            reading.resolve(revisionPage([revision(1)], 1, 2));
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            expect(component.firstRevisionsLoading).toBe(false);
        });

        it('does not read it again when the tab is shown again', async () => {
            await open();

            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));
            component.selectTab('details');
            component.selectTab('history');
            await Promise.resolve();

            expect(reads()).toHaveLength(1);
        });

        it('reads it again, from the start, when the item changed since it was read', async () => {
            await open([revisionPage([revision(1)]), revisionPage([revision(2), revision(1)])]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            component.markHistoryStale();
            component.selectTab('details');
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisions).toHaveLength(2));

            expect(reads()).toHaveLength(2);
            expect(component.revisionsStale).toBe(false);
        });

        it('says when the item has no history', async () => {
            await open([revisionPage([])]);

            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            expect(component.noRevisions).toBe(true);
        });

        it('is forgotten when the dialog opens on another item', async () => {
            await open();
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            component.begin('edit', component.items[1]);

            expect(component.revisions).toEqual([]);
            expect(component.revisionsLoaded).toBe(false);
            expect(component.revisionsPage).toBe(0);
        });

        it('drops the answer that comes after the dialog moved on to another item', async () => {
            const reading = deferred();
            await open([]);
            http.get.mockImplementation(async (url) => (url === REVISIONS ? reading.promise : page([])));
            component.selectTab('history');

            component.begin('edit', component.items[1]);
            reading.resolve(revisionPage([revision(9)]));
            await Promise.resolve();
            await Promise.resolve();

            expect(component.revisions).toEqual([]);
            expect(component.revisionsLoaded).toBe(false);
        });
    });

    describe('loading more', () => {
        it('offers the next page while there is one', async () => {
            await open([revisionPage([revision(3), revision(2)], 1, 2)]);

            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            expect(component.hasMoreRevisions).toBe(true);
        });

        it('adds the next page after the ones shown', async () => {
            await open([revisionPage([revision(3), revision(2)], 1, 2), revisionPage([revision(1)], 2, 2)]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            await component.loadMoreRevisions();

            expect(reads()[1]).toEqual([REVISIONS, { params: { page: 2 } }]);
            expect(component.revisions.map((row) => row.id)).toEqual([ulid('r3'), ulid('r2'), ulid('r1')]);
            expect(component.hasMoreRevisions).toBe(false);
        });

        it('shows the button waiting while the page is read, and the list stays', async () => {
            await open([revisionPage([revision(3), revision(2)], 1, 2)]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));
            const reading = deferred();
            http.get.mockImplementation(async () => reading.promise);

            const loading = component.loadMoreRevisions();

            expect(component.moreRevisionsLoading).toBe(true);
            expect(component.firstRevisionsLoading).toBe(false);
            expect(component.revisions).toHaveLength(2);

            reading.resolve(revisionPage([revision(1)], 2, 2));
            await loading;

            expect(component.moreRevisionsLoading).toBe(false);
        });

        it('shows a revision once, even when a new one pushed it to the next page', async () => {
            await open([
                revisionPage([revision(4), revision(3)], 1, 2),
                revisionPage([revision(3), revision(2)], 2, 2),
            ]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            await component.loadMoreRevisions();

            expect(component.revisions.map((row) => row.id)).toEqual([ulid('r4'), ulid('r3'), ulid('r2')]);
        });

        it('does not read a page that does not exist', async () => {
            await open([revisionPage([revision(1)], 1, 1)]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            await component.loadMoreRevisions();

            expect(reads()).toHaveLength(1);
        });
    });

    describe('when it cannot be read', () => {
        it('says so and offers to try again, keeping what was shown', async () => {
            await open([revisionPage([revision(3)], 1, 2), new HttpError(500, 'The server hit an error.')]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            await component.loadMoreRevisions();

            expect(component.revisionsFailed).toBe(true);
            expect(component.revisions).toHaveLength(1);
            expect(component.revisionsLoading).toBe(false);
        });

        it('tries the same page again', async () => {
            await open([
                revisionPage([revision(3)], 1, 2),
                new HttpError(500, 'Oops.'),
                revisionPage([revision(2)], 2, 2),
            ]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));
            await component.loadMoreRevisions();

            await component.retryRevisions();

            expect(component.revisionsFailed).toBe(false);
            expect(component.revisions.map((row) => row.id)).toEqual([ulid('r3'), ulid('r2')]);
        });

        it('does not claim the item has no history when the first page failed', async () => {
            await open([new HttpError(500, 'Oops.'), revisionPage([revision(1)])]);

            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsFailed).toBe(true));

            expect(component.noRevisions).toBe(false);
            expect(component.revisionsLoaded).toBe(false);

            await component.retryRevisions();

            expect(component.revisions.map((row) => row.id)).toEqual([ulid('r1')]);
            expect(component.revisionsFailed).toBe(false);
        });

        it('starts again from the first page when it was the reading again of a changed history that failed, not the page after the last', async () => {
            await open([
                revisionPage([revision(3)], 1, 2),
                revisionPage([revision(2)], 2, 2),
                new HttpError(500, 'Oops.'),
                revisionPage([revision(5), revision(4)], 1, 1),
            ]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));
            await component.loadMoreRevisions();
            // The item changed since (a file was added): the next time the history is shown, it is read again... and that fails.
            component.markHistoryStale();
            component.selectTab('details');
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsFailed).toBe(true));

            await component.retryRevisions();

            expect(reads().map((call) => call[1].params.page)).toEqual([1, 2, 1, 1]);
            expect(component.revisions.map((row) => row.id)).toEqual([ulid('r5'), ulid('r4')]);
            expect(component.revisionsFailed).toBe(false);
        });

        it('reads it again the next time it is shown when the reading again of a changed history failed', async () => {
            await open([
                revisionPage([revision(3)]),
                new HttpError(500, 'Oops.'),
                revisionPage([revision(4), revision(3)]),
            ]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));
            component.markHistoryStale();
            component.selectTab('details');
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsFailed).toBe(true));

            component.selectTab('details');
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisions).toHaveLength(2));

            expect(component.revisionsFailed).toBe(false);
        });
    });

    describe('an item that changes while its history is being read', () => {
        it('is read again afterwards: the change that came while the reading was on its way is not in the answer', async () => {
            const reading = deferred();

            await open([reading.promise, revisionPage([revision(4), revision(3)])]);
            component.selectTab('history');
            // A file is added while the first reading is on its way.
            component.markHistoryStale();
            reading.resolve(revisionPage([revision(3)]));
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            expect(component.revisionsStale).toBe(true);

            component.selectTab('details');
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisions).toHaveLength(2));

            expect(component.revisionsStale).toBe(false);
        });

        it('keeps the flag cleared when nothing changed meanwhile', async () => {
            await open([revisionPage([revision(3)])]);
            component.selectTab('history');
            await vi.waitFor(() => expect(component.revisionsLoaded).toBe(true));

            expect(component.revisionsStale).toBe(false);
        });
    });
});
