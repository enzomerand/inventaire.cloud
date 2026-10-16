import { describe, expect, it } from 'vitest';

import { emptyQuery } from '../../../resources/js/grid/query.js';
import { CREATED, UPDATED, cycleSort, directionBy } from '../../../resources/js/grid/sort.js';

describe('the sort of the list of items', () => {
    it('goes ascending, then descending, then back to the newest first, as a header is clicked', () => {
        let query = emptyQuery();

        query = { ...query, ...cycleSort(query, 'price') };
        expect(query).toMatchObject({ sort: 'price', direction: 'asc' });

        query = { ...query, ...cycleSort(query, 'price') };
        expect(query).toMatchObject({ sort: 'price', direction: 'desc' });

        query = { ...query, ...cycleSort(query, 'price') };
        expect(query).toMatchObject({ sort: '', direction: 'asc' });
    });

    it('starts again ascending when another header is clicked, whatever direction the last one had', () => {
        const query = { ...emptyQuery(), sort: 'price', direction: 'desc' };

        expect(cycleSort(query, 'name')).toEqual({ sort: 'name', direction: 'asc' });
        expect(cycleSort(query, CREATED)).toEqual({ sort: CREATED, direction: 'asc' });
    });

    it('tells the direction by a key, and nothing for the keys it does not sort by', () => {
        const query = { ...emptyQuery(), sort: UPDATED, direction: 'desc' };

        expect(directionBy(query, UPDATED)).toBe('desc');
        expect(directionBy(query, CREATED)).toBeNull();
        expect(directionBy(emptyQuery(), 'price')).toBeNull();
    });
});
