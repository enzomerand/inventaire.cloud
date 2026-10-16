import { describe, expect, it } from 'vitest';

import {
    SEARCH_MAX_LENGTH,
    emptyQuery,
    fromSearch,
    hasCriteria,
    toParams,
    toSearch,
    totalsKeyOf,
    withoutRefused,
} from '../../../resources/js/grid/query.js';
import { ulid } from '../support/inventory.js';

const PRICE = ulid('price');
const STATE = ulid('state');
const FRAGILE = ulid('fragile');
const PHOTO = ulid('photo');
const NOTES = ulid('notes');
const SEEN = ulid('seen');
const PARTS = ulid('parts');

/** A query that uses every kind of condition. */
function fullQuery() {
    return {
        q: 'claw hammer',
        sort: 'price',
        direction: 'desc',
        filters: {
            [PRICE]: { min: '10', max: '50.50' },
            [STATE]: { in: ['aaaaaaaa', 'bbbbbbbb'] },
            [FRAGILE]: { is: false },
            [PHOTO]: { empty: true },
            [NOTES]: { empty: false },
            [SEEN]: { min: '2026-10-15T08:00:00Z' },
        },
    };
}

describe('the query of the list of items', () => {
    it('starts as the newest first, with nothing narrowing it down', () => {
        const query = emptyQuery();

        expect(query).toEqual({ q: '', sort: '', direction: 'asc', filters: {} });
        expect(hasCriteria(query)).toBe(false);
    });

    it('is narrowed down by a search or a filter, and not by a sort', () => {
        expect(hasCriteria({ ...emptyQuery(), q: 'x' })).toBe(true);
        expect(hasCriteria({ ...emptyQuery(), filters: { f: { empty: true } } })).toBe(true);
        expect(hasCriteria({ ...emptyQuery(), sort: 'price' })).toBe(false);
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
                [`filter[${PRICE}][min]`]: '10',
                [`filter[${PRICE}][max]`]: '50.50',
                [`filter[${STATE}][in][0]`]: 'aaaaaaaa',
                [`filter[${STATE}][in][1]`]: 'bbbbbbbb',
                [`filter[${FRAGILE}][is]`]: 'false',
                [`filter[${PHOTO}][empty]`]: 'true',
                [`filter[${NOTES}][empty]`]: 'false',
                [`filter[${SEEN}][min]`]: '2026-10-15T08:00:00Z',
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
                `filter[${STATE}][in][]=aaaaaaaa&filter[${STATE}][in][]=bbbbbbbb&filter[${PARTS}][in][1]=cccccccc`,
            );

            expect(query.filters).toEqual({ [STATE]: { in: ['aaaaaaaa', 'bbbbbbbb'] }, [PARTS]: { in: ['cccccccc'] } });
        });

        it('reads the field of a filter in lowercase, as the values are keyed', () => {
            expect(fromSearch(`filter[${PRICE.toUpperCase()}][min]=5`).query.filters).toEqual({
                [PRICE]: { min: '5' },
            });
        });

        it('leaves out what makes no sense, so that an address edited by hand never breaks the page', () => {
            const { query, page } = fromSearch(
                `direction=sideways&sort=price&page=abc&unknown=1&filter[${ulid('a')}][bogus]=1&filter[${ulid('b')}][empty]=maybe&filter[${ulid('c')}][is]=1&filter[${ulid('d')}][min]=&filter[${ulid('e')}][max]=9`,
            );

            expect(page).toBe(1);
            expect(query).toEqual({
                q: '',
                sort: 'price',
                direction: 'asc',
                filters: { [ulid('e')]: { max: '9' } },
            });
        });

        it('takes only the id of a field (a ULID) as the name of a filter, whatever it is wrapped in', () => {
            const { query } = fromSearch(
                [
                    'filter[price][min]=5',
                    'filter[][min]=5',
                    `filter[${PRICE}x][min]=5`,
                    `filter[${PRICE.slice(1)}][min]=5`,
                    `filter[${PRICE}%5D%5Bmin][min]=5`,
                    `filter[${STATE}][min]=7`,
                ].join('&'),
            );

            expect(query.filters).toEqual({ [STATE]: { min: '7' } });
        });

        it.each(['__proto__', 'constructor', 'prototype', 'hasOwnProperty', 'toString', '__defineGetter__'])(
            'cannot be used to write on an object it does not own, with %s as the name of a filter',
            (name) => {
                const { query } = fromSearch(
                    `filter[${name}][min]=1&filter[${name}][max]=2&filter[${name}][empty]=true&filter[${name}][is]=true&filter[${name}][in][]=aaaaaaaa`,
                );

                expect(query.filters).toEqual({});
                expect(Object.keys(query.filters)).toEqual([]);
                expect(Object.getPrototypeOf(query.filters)).toBe(Object.prototype);
                // Nothing leaked on the prototype of the objects, nor on the constructors.
                expect({}.min).toBeUndefined();
                expect({}.max).toBeUndefined();
                expect({}.empty).toBeUndefined();
                expect({}.is).toBeUndefined();
                expect({}.in).toBeUndefined();
                expect(Object.min).toBeUndefined();
                expect(Object.prototype).not.toHaveProperty('min');
                expect(Object.keys(Object.prototype)).toEqual([]);
            },
        );

        it('keeps the filters of a ULID that carries a name of the prototype in the middle of the address', () => {
            const { query } = fromSearch(
                `filter[__proto__][min]=1&filter[${STATE}][in][]=aaaaaaaa&filter[constructor][is]=true`,
            );

            expect(query.filters).toEqual({ [STATE]: { in: ['aaaaaaaa'] } });
            expect({}.min).toBeUndefined();
        });

        it('drops a bound that is written in no form the server takes, and only that condition', () => {
            const { query } = fromSearch(
                [
                    'q=saw',
                    'sort=price',
                    `filter[${PRICE}][min]=abc`,
                    `filter[${PRICE}][max]=50.5`,
                    `filter[${NOTES}][min]=1e5`,
                    `filter[${NOTES}][max]=1.1234567`,
                    `filter[${SEEN}][min]=tomorrow`,
                    `filter[${SEEN}][max]=2026-10-15T08:00:00Z`,
                    `filter[${FRAGILE}][min]=1000000000000000`,
                    `filter[${FRAGILE}][is]=true`,
                    `filter[${PHOTO}][min]=2026-10-15`,
                    `filter[${PARTS}][max]=09:30`,
                    `filter[${ulid('closed')}][max]=%20`,
                ].join('&'),
            );

            expect(query).toEqual({
                q: 'saw',
                sort: 'price',
                direction: 'asc',
                filters: {
                    [PRICE]: { max: '50.5' },
                    [SEEN]: { max: '2026-10-15T08:00:00Z' },
                    [FRAGILE]: { is: true },
                    [PHOTO]: { min: '2026-10-15' },
                    [PARTS]: { max: '09:30' },
                },
            });
        });

        it('takes the bounds the panel writes, negative numbers and decimals included', () => {
            const { query } = fromSearch(`filter[${PRICE}][min]=-5&filter[${PRICE}][max]=999999999999999.999999`);

            expect(query.filters).toEqual({ [PRICE]: { min: '-5', max: '999999999999999.999999' } });
        });

        it('drops a choice that is not written like the id of a choice (8 lowercase letters and digits), and only that one', () => {
            const { query } = fromSearch(
                [
                    `filter[${STATE}][in][]=aaaaaaaa`,
                    `filter[${STATE}][in][]=AAAAAAAA`,
                    `filter[${STATE}][in][]=short`,
                    `filter[${STATE}][in][]=waytoolong1`,
                    `filter[${STATE}][in][]=%3Cb%3Ebad%3E`,
                    `filter[${STATE}][in][]=bbbbbbbb`,
                    `filter[${STATE}][in][]=aaaaaaaa`,
                    `filter[${PARTS}][in][]=nope`,
                ].join('&'),
            );

            expect(query.filters).toEqual({ [STATE]: { in: ['aaaaaaaa', 'bbbbbbbb'] } });
        });

        it('ignores a direction when nothing is sorted', () => {
            expect(fromSearch('direction=desc').query.direction).toBe('asc');
        });

        it('reads the direction in any case, as the API does', () => {
            expect(fromSearch('sort=price&direction=DESC').query.direction).toBe('desc');
            expect(fromSearch('sort=price&direction=Desc').query.direction).toBe('desc');
            expect(fromSearch('sort=price&direction=ASC').query.direction).toBe('asc');
            expect(fromSearch('sort=price&direction=sideways').query.direction).toBe('asc');
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

        it('cuts a search by characters, never through a character made of two units', () => {
            const emoji = '\u{1F600}';
            const long = fromSearch('q=' + encodeURIComponent(emoji.repeat(SEARCH_MAX_LENGTH + 50))).query.q;
            const straddling = fromSearch('q=' + encodeURIComponent('x'.repeat(SEARCH_MAX_LENGTH - 1) + emoji + 'yyy'))
                .query.q;

            expect([...long]).toHaveLength(SEARCH_MAX_LENGTH);
            expect(long).toBe(emoji.repeat(SEARCH_MAX_LENGTH));
            expect(straddling).toBe('x'.repeat(SEARCH_MAX_LENGTH - 1) + emoji);
            expect(long).not.toMatch(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])/);
            expect(straddling).not.toMatch(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])/);
        });

        it('cuts a sort by characters too', () => {
            const sort = fromSearch('sort=' + encodeURIComponent('x'.repeat(63) + '\u{1F600}' + 'z')).query.sort;

            expect(sort).toBe('x'.repeat(63) + '\u{1F600}');
        });
    });

    describe('what the totals depend on', () => {
        it('is the search and the filters, not the sort nor the direction', () => {
            const query = fullQuery();

            expect(totalsKeyOf({ ...query, sort: '', direction: 'asc' })).toBe(totalsKeyOf(query));
            expect(totalsKeyOf({ ...query, sort: 'created_at', direction: 'desc' })).toBe(totalsKeyOf(query));
        });

        it('changes with the search, and with any condition of any filter', () => {
            const query = fullQuery();
            const keys = new Set([
                totalsKeyOf(query),
                totalsKeyOf({ ...query, q: 'saw' }),
                totalsKeyOf({ ...query, filters: { ...query.filters, [PRICE]: { min: '10' } } }),
                totalsKeyOf({ ...query, filters: { ...query.filters, [FRAGILE]: { is: true } } }),
                totalsKeyOf({ ...query, filters: {} }),
                totalsKeyOf(emptyQuery()),
            ]);

            expect(keys.size).toBe(6);
        });

        it('does not depend on the order the filters and their conditions were written in', () => {
            const first = {
                ...emptyQuery(),
                filters: { [PRICE]: { min: '1', max: '2' }, [STATE]: { in: ['aaaaaaaa'] } },
            };
            const second = {
                ...emptyQuery(),
                filters: { [STATE]: { in: ['aaaaaaaa'] }, [PRICE]: { max: '2', min: '1' } },
            };

            expect(totalsKeyOf(first)).toBe(totalsKeyOf(second));
        });
    });

    describe('taking out what the server refused', () => {
        it('takes out the search, the sort with its direction, or the direction alone, by the key of the error', () => {
            const query = fullQuery();

            expect(withoutRefused(query, { q: ['x'] }).query).toEqual({ ...query, q: '' });
            expect(withoutRefused(query, { sort: ['x'] }).query).toEqual({ ...query, sort: '', direction: 'asc' });
            expect(withoutRefused(query, { direction: ['x'] }).query).toEqual({ ...query, direction: 'asc' });
        });

        it('takes out one condition of a filter, and the filter itself when it was the last one', () => {
            const query = fullQuery();

            expect(withoutRefused(query, { [`filter.${PRICE}.max`]: ['x'] }).query.filters[PRICE]).toEqual({
                min: '10',
            });
            expect(withoutRefused(query, { [`filter.${FRAGILE}.is`]: ['x'] }).query.filters).not.toHaveProperty(
                FRAGILE,
            );
            expect(withoutRefused(query, { [`filter.${FRAGILE}.is`]: ['x'] }).query.filters).toHaveProperty(PRICE);
        });

        it('takes out the whole filter of a field, in whatever case the error names it', () => {
            const query = fullQuery();

            expect(withoutRefused(query, { [`filter.${PRICE}`]: ['x'] }).query.filters).not.toHaveProperty(PRICE);
            expect(
                withoutRefused(query, { [`filter.${PRICE.toUpperCase()}`]: ['x'] }).query.filters,
            ).not.toHaveProperty(PRICE);
            expect(Object.keys(withoutRefused(query, { [`filter.${PRICE}`]: ['x'] }).query.filters)).toHaveLength(5);
        });

        it('says whether something was taken out, and returns the very query when nothing was', () => {
            const query = fullQuery();
            const nothing = withoutRefused(query, {
                [`filter.${ulid('gone')}.min`]: ['x'],
                page: ['x'],
                unknown: ['x'],
            });

            expect(nothing.dropped).toBe(false);
            expect(nothing.query).toBe(query);
            expect(withoutRefused(emptyQuery(), { sort: ['x'] }).dropped).toBe(false);
            expect(withoutRefused(query, { q: ['x'] }).dropped).toBe(true);
        });

        it('says the fields are out of date when the server refuses to total one, and takes nothing out for it', () => {
            const query = fullQuery();
            const refusal = withoutRefused(query, { 'totals.0': ['x'], 'totals.3': ['x'] });

            expect(refusal.staleFields).toBe(true);
            expect(refusal.dropped).toBe(false);
            expect(refusal.query).toBe(query);
            expect(withoutRefused(query, { q: ['x'] }).staleFields).toBe(false);
        });

        it('does not change the query it is given', () => {
            const query = fullQuery();
            const before = JSON.parse(JSON.stringify(query));

            withoutRefused(query, {
                q: ['x'],
                [`filter.${PRICE}.min`]: ['x'],
                [`filter.${STATE}`]: ['x'],
                sort: ['x'],
            });

            expect(query).toEqual(before);
        });

        it('copes with an error that carries no keys', () => {
            const query = fullQuery();

            expect(withoutRefused(query, {}).dropped).toBe(false);
            expect(withoutRefused(query, undefined).dropped).toBe(false);
        });
    });
});
