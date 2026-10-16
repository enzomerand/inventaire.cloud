/**
 * How the list sorts, filters and adds up a field is the server's to say, and it says it for each field (`query_kind` and
 * `summable`, see `InventoryFieldResource`): the interface reads it and keeps no table of its own, so it cannot drift from
 * the server. What the interface does keep is how to show and type each type of value (`fields/types.js`), which must cover
 * every type the server has: this test reads the PHP types for their keys.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { isFilterable, isSortable, isSummable, queryKindOf } from '../../../resources/js/fields/index.js';
import { FIELD_TYPES } from '../../../resources/js/fields/types.js';
import { field, queryFacts } from '../support/inventory.js';

const TYPES = join(import.meta.dirname, '../../../app/Inventories/Fields');

/**
 * The keys of the types of the server: the ones its classes declare (`function key(): string { return 'text'; }`).
 *
 * @returns {string[]}
 */
function serverTypeKeys() {
    return readdirSync(TYPES)
        .filter((name) => name.endsWith('.php'))
        .map(
            (name) =>
                readFileSync(join(TYPES, name), 'utf8').match(/function key\(\): string\s*\{\s*return '(\w+)';/)?.[1],
        )
        .filter((key) => key !== undefined);
}

describe('the types the interface knows how to show and type', () => {
    it('finds the types it is supposed to compare', () => {
        expect(serverTypeKeys().length).toBeGreaterThan(10);
    });

    it('are the types the server has, and only those', () => {
        expect(serverTypeKeys().sort()).toEqual(Object.keys(FIELD_TYPES).sort());
    });

    it('say nothing of how the list sorts, filters or adds them up: the API does', () => {
        for (const type of Object.values(FIELD_TYPES)) {
            expect(type).not.toHaveProperty('queryKind');
            expect(type).not.toHaveProperty('summable');
        }
    });
});

describe('the way the list treats a field', () => {
    it('is what the API says of it', () => {
        expect(queryKindOf(field('f', 'text'))).toBe('text');
        expect(queryKindOf({ ...field('f', 'text'), query_kind: 'number' })).toBe('number');
        expect(queryKindOf({ ...field('f', 'number'), query_kind: 'temporal' })).toBe('temporal');
        expect(isSummable({ ...field('f', 'text'), summable: true })).toBe(true);
        expect(isSummable({ ...field('f', 'number'), summable: false })).toBe(false);
    });

    it('is nothing, added up by nothing, for a field the API gives no word about (a type the server does not know)', () => {
        const unknown = { id: 'f', name: 'F', type: 'hologram', options: {}, required: false, position: 0 };

        expect(queryKindOf(unknown)).toBeNull();
        expect(queryKindOf({ ...unknown, query_kind: null })).toBeNull();
        expect(isSummable(unknown)).toBe(false);
        expect(isSummable({ ...unknown, summable: null })).toBe(false);
    });

    it('is neither sorted nor filtered for a field the server cannot query, not even as a text', () => {
        const unknown = { id: 'f', name: 'F', type: 'hologram', options: {}, required: false, position: 0 };

        for (const one of [unknown, { ...unknown, query_kind: null }, field('f', 'hologram')]) {
            expect(isSortable(one)).toBe(false);
            expect(isFilterable(one)).toBe(false);
        }

        // A text is both, so the refusal comes from the missing word of the API and not from the shape of the field.
        expect(isSortable(field('f', 'text'))).toBe(true);
        expect(isFilterable(field('f', 'text'))).toBe(true);
    });

    it('lets the list filter every kind the server can query, the ones it cannot sort included', () => {
        for (const type of Object.keys(FIELD_TYPES)) {
            expect(isFilterable(field('f', type)), type).toBe(true);
        }

        expect(isSortable(field('f', 'tags'))).toBe(false);
        expect(isFilterable(field('f', 'tags'))).toBe(true);
    });

    it('lets the list sort all but the kinds that hold several values', () => {
        const sortable = (type) => isSortable(field('f', type));

        expect(sortable('text')).toBe(true);
        expect(sortable('number')).toBe(true);
        expect(sortable('date')).toBe(true);
        expect(sortable('select')).toBe(true);
        expect(sortable('multiselect')).toBe(false);
        expect(sortable('tags')).toBe(false);
    });

    it('is decided by the kind the API gives, not by the name of the type', () => {
        // A rating and an amount are both numbers to sort and filter; only the amount adds up.
        expect(queryKindOf(field('f', 'rating'))).toBe('number');
        expect(isSummable(field('f', 'rating'))).toBe(false);
        expect(queryKindOf(field('f', 'money'))).toBe('number');
        expect(isSummable(field('f', 'money'))).toBe(true);
    });

    it('is described for every type the interface knows, by the table the fixtures of the tests use', () => {
        for (const type of Object.keys(FIELD_TYPES)) {
            const facts = queryFacts(type);

            expect(facts.query_kind, type).toEqual(expect.any(String));
            expect(typeof facts.summable, type).toBe('boolean');
        }
    });
});
