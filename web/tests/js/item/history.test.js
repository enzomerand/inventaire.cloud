import { describe, expect, it, vi } from 'vitest';

import { momentText } from '../../../resources/js/grid/rows.js';
import { revisionLines, revisionRow } from '../../../resources/js/item/history.js';
import { FIELDS } from '../support/inventory.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const WHEN = '2026-10-15T10:00:00Z';

/** A revision as the API describes it. */
function revision(action, changes, extra = {}) {
    return { id: 7, action, user: { id: 'u1', name: 'Ada' }, created_at: WHEN, changes, ...extra };
}

describe('the title of a revision', () => {
    it('says who made the change and when', () => {
        expect(revisionRow(revision('updated', []), FIELDS).title).toBe('Ada · ' + momentText(WHEN));
    });

    it('says "Deleted user" when the account is gone', () => {
        expect(revisionRow(revision('updated', [], { user: null }), FIELDS).title).toBe(
            'Deleted user · ' + momentText(WHEN),
        );
    });

    it('keeps what a name holds as text', () => {
        const row = revisionRow(revision('updated', [], { user: { id: 'u2', name: '<b>Eve</b>' } }), FIELDS);

        expect(row.title).toBe('<b>Eve</b> · ' + momentText(WHEN));
    });

    it('is identified by the id of the revision, as text', () => {
        expect(revisionRow(revision('updated', []), FIELDS).id).toBe('7');
    });
});

describe('the lines of a revision that changed values', () => {
    it('show each value before and after, formatted as the field shows it', () => {
        const lines = revisionLines(
            revision('updated', [
                { field: 'price', before: '10.00', after: '12.50' },
                { field: 'qty', before: '1.50', after: '2.00' },
            ]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Price: €10.00 → €12.50', 'Weight: 1.50 kg → 2.00 kg']);
    });

    it('say "Not set" for a value that was empty before or is empty after', () => {
        const lines = revisionLines(
            revision('updated', [
                { field: 'qty', before: null, after: '2.00' },
                { field: 'name', before: 'Hammer', after: null },
            ]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Weight: Not set → 2.00 kg', 'Name: Hammer → Not set']);
    });

    it('show the label of a choice, and every choice of a multiple choice', () => {
        const lines = revisionLines(
            revision('updated', [
                { field: 'state', before: 'aaaaaaaa', after: 'bbbbbbbb' },
                { field: 'parts', before: ['cccccccc'], after: ['cccccccc', 'dddddddd'] },
                { field: 'fragile', before: false, after: true },
            ]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual([
            'State: New → Used',
            'Parts: Red → Red, Blue',
            'Fragile: No → Yes',
        ]);
    });

    it('name a field that no longer exists "Deleted field", and show its values as they were stored', () => {
        const lines = revisionLines(
            revision('updated', [
                { field: null, before: 'abc', after: ['x', 'y'] },
                { field: 'gone', before: null, after: 12 },
            ]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Deleted field: abc → x, y', 'Deleted field: Not set → 12']);
    });

    it('say the item was modified when the list of values is empty', () => {
        expect(revisionLines(revision('updated', []), FIELDS).map((line) => line.text)).toEqual(['Item modified']);
    });

    it('give each line a key of its own', () => {
        const keys = revisionLines(
            revision('updated', [
                { field: 'name', before: 'a', after: 'b' },
                { field: 'qty', before: '1', after: '2' },
            ]),
            FIELDS,
        ).map((line) => line.key);

        expect(new Set(keys).size).toBe(2);
    });
});

describe('the lines of the revision that created the item', () => {
    it('say it was created, then show the values it started with', () => {
        const lines = revisionLines(
            revision('created', [
                { field: 'name', before: null, after: 'Hammer' },
                { field: 'price', before: null, after: '19.90' },
            ]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Item created', 'Name: Hammer', 'Price: €19.90']);
    });

    it('say it was created even when it started empty', () => {
        expect(revisionLines(revision('created', []), FIELDS).map((line) => line.text)).toEqual(['Item created']);
    });
});

describe('the lines of a revision about a file', () => {
    it('name the file that was added', () => {
        const lines = revisionLines(
            revision('attachment_added', { attachment: 'a1', name: 'invoice.pdf', field: null }),
            FIELDS,
        );

        expect(lines).toEqual([{ key: 'file', text: 'File added: invoice.pdf' }]);
    });

    it('name the file that was removed', () => {
        const lines = revisionLines(
            revision('attachment_removed', { attachment: 'a1', name: 'invoice.pdf', field: 'docs' }),
            FIELDS,
        );

        expect(lines[0].text).toBe('File removed: invoice.pdf');
    });

    it('keep the name as text, never as markup', () => {
        const lines = revisionLines(
            revision('attachment_added', { attachment: 'a1', name: '<img src=x onerror=alert(1)>.png', field: null }),
            FIELDS,
        );

        expect(lines[0].text).toBe('File added: <img src=x onerror=alert(1)>.png');
    });

    it('do not break when the API sends no details', () => {
        expect(revisionLines(revision('attachment_added', []), FIELDS)[0].text).toBe('File added: ');
    });
});
