import { describe, expect, it } from 'vitest';

import {
    SEARCH_MAX_LENGTH,
    emptyQuery,
    fromSearch,
    hasCriteria,
    isEmptyQuery,
    toParams,
    toSearch,
} from '../../../resources/js/grid/query.js';

/** A query that uses every kind of condition. */
function fullQuery() {
    return {
        q: 'claw hammer',
        sort: 'price',
        direction: 'desc',
        filters: {
            price: { min: '10', max: '50.50' },
            state: { in: ['aaaaaaaa', 'bbbbbbbb'] },
            fragile: { is: false },
            photo: { empty: true },
            notes: { empty: false },
            seen: { min: '2026-10-15T08:00:00Z' },
        },
    };
}

describe('the query of the list of items', () => {
    it('starts as the newest first, with nothing narrowing it down', () => {
        const query = emptyQuery();

        expect(query).toEqual({ q: '', sort: '', direction: 'asc', filters: {} });
        expect(isEmptyQuery(query)).toBe(true);
        expect(hasCriteria(query)).toBe(false);
    });

    it('is narrowed down by a search or a filter, and not by a sort', () => {
        expect(hasCriteria({ ...emptyQuery(), q: 'x' })).toBe(true);
        expect(hasCriteria({ ...emptyQuery(), filters: { f: { empty: true } } })).toBe(true);
        expect(hasCriteria({ ...emptyQuery(), sort: 'price' })).toBe(false);
        expect(isEmptyQuery({ ...emptyQuery(), sort: 'price' })).toBe(false);
    });

    describe('as the parameters of the API', () => {
        it('asks for the page alone when nothing else is asked', () => {
            expect(toParams(emptyQuery(), { page: 1 })).toEqual({ page: 1 });
            expect(toParams(emptyQuery())).toEqual({});
        });

        it('writes the search, the sort and every condition of the filters with the brackets the API reads', () => {
            expect(toParams(fullQuery(), { page: 3 })).toEqual({
                page: 3,
                q: 'claw hammer',
                sort: 'price',
                direction: 'desc',
                'filter[price][min]': '10',
                'filter[price][max]': '50.50',
                'filter[state][in][0]': 'aaaaaaaa',
                'filter[state][in][1]': 'bbbbbbbb',
                'filter[fragile][is]': 'false',
                'filter[photo][empty]': 'true',
                'filter[notes][empty]': 'false',
                'filter[seen][min]': '2026-10-15T08:00:00Z',
            });
        });

        it('leaves out the direction when it is ascending (what the API does without one) or when nothing is sorted', () => {
            expect(toParams({ ...emptyQuery(), sort: 'price', direction: 'asc' })).toEqual({ sort: 'price' });
            expect(toParams({ ...emptyQuery(), direction: 'desc' })).toEqual({});
        });
    });

    describe('in the address of the page', () => {
        it('is empty for the default view, and carries the page only after the first one', () => {
            expect(toSearch(emptyQuery())).toBe('');
            expect(toSearch(emptyQuery(), { page: 1 })).toBe('');
            expect(toSearch(emptyQuery(), { page: 2 })).toBe('page=2');
        });

        it('comes back as it was written, nested filters and page included', () => {
            const query = fullQuery();

            expect(fromSearch('?' + toSearch(query, { page: 4 }))).toEqual({ query, page: 4, view: null });
            expect(fromSearch(toSearch(query))).toEqual({ query, page: 1, view: null });
        });

        it('keeps what is typed in the search whole, whatever the characters', () => {
            const query = { ...emptyQuery(), q: 'a&b=c [d] é "f" +g' };

            expect(fromSearch(toSearch(query)).query.q).toBe('a&b=c [d] é "f" +g');
        });

        it('reads the choices of a filter written with empty brackets as well as numbered ones', () => {
            const { query } = fromSearch(
                'filter[state][in][]=aaaaaaaa&filter[state][in][]=bbbbbbbb&filter[parts][in][1]=cccccccc',
            );

            expect(query.filters).toEqual({ state: { in: ['aaaaaaaa', 'bbbbbbbb'] }, parts: { in: ['cccccccc'] } });
        });

        it('reads the field of a filter in lowercase, as the values are keyed', () => {
            expect(fromSearch('filter[ABC][min]=5').query.filters).toEqual({ abc: { min: '5' } });
        });

        it('leaves out what makes no sense, so that an address edited by hand never breaks the page', () => {
            const { query, page } = fromSearch(
                'direction=sideways&sort=price&page=abc&unknown=1&filter[a][bogus]=1&filter[b][empty]=maybe&filter[c][is]=1&filter[d][min]=&filter[e][max]=9',
            );

            expect(page).toBe(1);
            expect(query).toEqual({ q: '', sort: 'price', direction: 'asc', filters: { e: { max: '9' } } });
        });

        it('ignores a direction when nothing is sorted', () => {
            expect(fromSearch('direction=desc').query.direction).toBe('asc');
        });

        it('carries the way the items are laid out when the person chose one, and nothing when not', () => {
            expect(toSearch(emptyQuery(), { view: 'gallery' })).toBe('view=gallery');
            expect(toSearch({ ...emptyQuery(), q: 'saw' }, { page: 2, view: 'table' })).toBe('page=2&q=saw&view=table');
            expect(toSearch(emptyQuery(), { view: null })).toBe('');
            expect(fromSearch('view=gallery').view).toBe('gallery');
            expect(fromSearch('q=saw&view=table').view).toBe('table');
            expect(fromSearch('').view).toBeNull();
        });

        it('leaves the way the items are laid out out of what is asked of the API', () => {
            expect(toParams(emptyQuery(), { page: 1 })).toEqual({ page: 1 });
            expect(fromSearch('view=gallery').query).toEqual(emptyQuery());
        });

        it('ignores a view nobody knows', () => {
            expect(fromSearch('view=carousel').view).toBeNull();
            expect(fromSearch('view=').view).toBeNull();
        });

        it('takes the page as a positive whole number, and no more than the API takes', () => {
            expect(fromSearch('page=0').page).toBe(1);
            expect(fromSearch('page=-3').page).toBe(1);
            expect(fromSearch('page=2.7').page).toBe(2);
            expect(fromSearch('page=99999999').page).toBe(1_000_000);
        });

        it('cuts a search that is longer than the API takes, and trims it', () => {
            expect(fromSearch('q=' + 'x'.repeat(SEARCH_MAX_LENGTH + 50)).query.q).toHaveLength(SEARCH_MAX_LENGTH);
            expect(fromSearch('q=%20%20hammer%20').query.q).toBe('hammer');
        });
    });
});
