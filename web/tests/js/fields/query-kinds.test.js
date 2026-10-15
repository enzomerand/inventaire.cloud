/**
 * The registry of field types of the interface mirrors two things the server decides for each type: how the list sorts and
 * filters it (`queryKind()`, the `QueryKind` enum) and whether the totals add it up (`summable()`). The page builds its
 * search, sort and filter controls from the mirror, so a drift would offer a filter the server refuses. This test reads the
 * PHP types and compares.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { isSortable, isSummable, queryKindOf } from '../../../resources/js/fields/index.js';
import { FIELD_TYPES } from '../../../resources/js/fields/types.js';

const TYPES = join(import.meta.dirname, '../../../app/Inventories/Fields');

/**
 * Reads the types of the server: for each class its parent, and the key, the query kind and the summable flag it declares
 * itself. A type that declares none inherits the one of its parent.
 *
 * @returns {Record<string, { key?: string, kind?: string, summable?: boolean, parent: string | null }>}
 */
function phpClasses() {
    const classes = {};

    for (const file of readdirSync(TYPES).filter((name) => name.endsWith('.php'))) {
        const source = readFileSync(join(TYPES, file), 'utf8');
        const declaration = source.match(/^(?:abstract |final )?class (\w+)(?: extends (\w+))?/m);

        if (declaration === null) {
            continue;
        }

        const key = source.match(/function key\(\): string\s*\{\s*return '(\w+)';/);
        const kind = source.match(/function queryKind\(\): QueryKind\s*\{\s*return QueryKind::(\w+);/);
        const summable = source.match(/function summable\(\): bool\s*\{\s*return (true|false);/);

        classes[declaration[1]] = {
            parent: declaration[2] ?? null,
            key: key?.[1],
            kind: kind?.[1].toLowerCase(),
            summable: summable === null ? undefined : summable[1] === 'true',
        };
    }

    return classes;
}

/** What a class declares or inherits for a property. */
function inherited(classes, name, property) {
    for (let current = classes[name]; current !== undefined; current = classes[current.parent]) {
        if (current[property] !== undefined) {
            return current[property];
        }
    }

    return undefined;
}

describe('the field types of the interface mirror the ones of the server', () => {
    const classes = phpClasses();
    const concrete = Object.entries(classes).filter(([, declared]) => declared.key !== undefined);

    it('finds the types it is supposed to compare', () => {
        expect(concrete.length).toBeGreaterThan(10);
    });

    it('knows every type the server has, and only those', () => {
        expect(concrete.map(([, declared]) => declared.key).sort()).toEqual(Object.keys(FIELD_TYPES).sort());
    });

    it.each(concrete)('%s is filtered, sorted and added up as the server does', (name, declared) => {
        const field = { id: 'f', name: 'Field', type: declared.key, options: {}, required: false, position: 0 };

        expect(queryKindOf(field), `queryKind of ${declared.key}`).toBe(inherited(classes, name, 'kind'));
        expect(isSummable(field), `summable of ${declared.key}`).toBe(inherited(classes, name, 'summable') ?? false);
    });

    it('sorts all but the kinds that hold several values', () => {
        const sortable = (type) => isSortable({ id: 'f', name: 'F', type, options: {}, required: false, position: 0 });

        expect(sortable('text')).toBe(true);
        expect(sortable('select')).toBe(true);
        expect(sortable('multiselect')).toBe(false);
        expect(sortable('tags')).toBe(false);
    });

    it('falls back to a text for a type it does not know', () => {
        expect(queryKindOf({ id: 'f', name: 'F', type: 'hologram', options: {}, required: false, position: 0 })).toBe(
            'text',
        );
    });
});
