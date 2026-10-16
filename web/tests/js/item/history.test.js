import { describe, expect, it, vi } from 'vitest';

import { momentText } from '../../../resources/js/grid/rows.js';
import { revisionLines, revisionRow } from '../../../resources/js/item/history.js';
import { FIELDS } from '../support/inventory.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const WHEN = '2026-10-15T10:00:00Z';

/** A revision as the API describes it. */
function revision(action, changes, extra = {}) {
    return { id: 7, action, user: { id: 1, name: 'Ada' }, created_at: WHEN, changes, ...extra };
}

/** The field of a change, as the revision names it: the id, the name and the type of a field of the inventory. */
function named(id) {
    const { name, type } = FIELDS.find((one) => one.id === id);

    return { id, name, type };
}

/** A change of value, as the API gives it. */
function change(id, before, after) {
    return { field: named(id), before, after };
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
        const row = revisionRow(revision('updated', [], { user: { id: 2, name: '<b>Eve</b>' } }), FIELDS);

        expect(row.title).toBe('<b>Eve</b> · ' + momentText(WHEN));
    });

    it('is identified by the id of the revision, as text', () => {
        expect(revisionRow(revision('updated', []), FIELDS).id).toBe('7');
    });
});

describe('the lines of a revision that changed values', () => {
    it('show each value before and after, formatted as the field shows it', () => {
        const lines = revisionLines(
            revision('updated', [change('price', '10.00', '12.50'), change('qty', '1.50', '2.00')]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Price: €10.00 → €12.50', 'Weight: 1.50 kg → 2.00 kg']);
    });

    it('say "Not set" for a value that was empty before or is empty after', () => {
        const lines = revisionLines(
            revision('updated', [change('qty', null, '2.00'), change('name', 'Hammer', null)]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Weight: Not set → 2.00 kg', 'Name: Hammer → Not set']);
    });

    it('show the label of a choice, and every choice of a multiple choice', () => {
        const lines = revisionLines(
            revision('updated', [
                change('state', 'aaaaaaaa', 'bbbbbbbb'),
                change('parts', ['cccccccc'], ['cccccccc', 'dddddddd']),
                change('fragile', false, true),
            ]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual([
            'State: New → Used',
            'Parts: Red → Red, Blue',
            'Fragile: No → Yes',
        ]);
    });

    it('name a field that no longer exists "Deleted field", and show none of its raw values', () => {
        const lines = revisionLines(
            revision('updated', [
                { field: null, before: 'abc', after: ['x', 'y'] },
                { field: null, before: null, after: 12 },
                change('name', 'Hammer', 'Claw hammer'),
            ]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual([
            'Deleted field',
            'Deleted field',
            'Name: Hammer → Claw hammer',
        ]);
    });

    it('prefer the current definition of a field to what the revision says of it', () => {
        const lines = revisionLines(
            revision('updated', [
                { field: { id: 'state', name: 'Old name', type: 'select' }, before: null, after: 'aaaaaaaa' },
            ]),
            FIELDS,
        );

        expect(lines[0].text).toBe('State: Not set → New');
    });

    it('fall back on the name and the type the revision gives for a field the inventory does not hold yet', () => {
        const lines = revisionLines(
            revision('updated', [
                { field: { id: 'serial', name: 'Serial', type: 'text' }, before: 'A1', after: 'B2' },
                { field: { id: 'weight', name: 'Net weight', type: 'number' }, before: null, after: '3' },
            ]),
            FIELDS,
        );

        expect(lines[0].text).toBe('Serial: A1 → B2');
        expect(lines[1].text).toContain('Net weight: Not set → ');
    });

    it('say the item was modified when the list of values is empty', () => {
        expect(revisionLines(revision('updated', []), FIELDS).map((line) => line.text)).toEqual(['Item modified']);
    });

    it('give each line a key of its own', () => {
        const keys = revisionLines(
            revision('updated', [change('name', 'a', 'b'), change('qty', '1', '2')]),
            FIELDS,
        ).map((line) => line.key);

        expect(new Set(keys).size).toBe(2);
    });
});

describe('the lines of the revision that created the item', () => {
    it('say it was created, then show the values it started with', () => {
        const lines = revisionLines(
            revision('created', [change('name', null, 'Hammer'), change('price', null, '19.90')]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Item created', 'Name: Hammer', 'Price: €19.90']);
    });

    it('say "Deleted field" for a value of a field that is gone, without its raw value', () => {
        const lines = revisionLines(
            revision('created', [change('name', null, 'Hammer'), { field: null, before: null, after: '12.50' }]),
            FIELDS,
        );

        expect(lines.map((line) => line.text)).toEqual(['Item created', 'Name: Hammer', 'Deleted field']);
    });

    it('say it was created even when it started empty', () => {
        expect(revisionLines(revision('created', []), FIELDS).map((line) => line.text)).toEqual(['Item created']);
    });
});

/** The one change of a revision about a file, as the API gives it: a list of one. */
function filed(name, field = null) {
    return [{ attachment: { id: '01j9z8x7c6v5b4n3m2k1h0g9f8', name }, field }];
}

describe('the lines of a revision about a file', () => {
    it('name the file that was added', () => {
        const lines = revisionLines(revision('attachment_added', filed('invoice.pdf')), FIELDS);

        expect(lines).toEqual([{ key: 'file', text: 'File added: invoice.pdf' }]);
    });

    it('name the file that was removed, whether or not it belonged to a field', () => {
        const lines = revisionLines(
            revision('attachment_removed', filed('invoice.pdf', { id: 'docs', name: 'Documents', type: 'file' })),
            FIELDS,
        );

        expect(lines[0].text).toBe('File removed: invoice.pdf');
    });

    it('keep the name as text, never as markup', () => {
        const lines = revisionLines(revision('attachment_added', filed('<img src=x onerror=alert(1)>.png')), FIELDS);

        expect(lines[0].text).toBe('File added: <img src=x onerror=alert(1)>.png');
    });

    it('do not break when the API sends no details', () => {
        expect(revisionLines(revision('attachment_added', []), FIELDS)[0].text).toBe('File added: ');
        expect(revisionLines(revision('attachment_added', filed(null)), FIELDS)[0].text).toBe('File added: ');
    });
});
