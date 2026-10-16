import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { currentSearch, replaceSearch } from '../../../resources/js/lib/location.js';

describe('the query string of the address of the page', () => {
    beforeEach(() => {
        window.history.replaceState(null, '', '/app/w/shop?old=1#top');
    });

    afterEach(() => {
        window.history.replaceState(null, '', '/');
    });

    it('is read with its leading question mark', () => {
        expect(currentSearch()).toBe('?old=1');
    });

    it('is replaced in place: the path and the fragment stay, and no entry is added to the history', () => {
        const entries = window.history.length;

        replaceSearch('q=hammer&page=2');

        expect(window.location.pathname).toBe('/app/w/shop');
        expect(window.location.search).toBe('?q=hammer&page=2');
        expect(window.location.hash).toBe('#top');
        expect(window.history.length).toBe(entries);
    });

    it('takes a query string that already has its question mark', () => {
        replaceSearch('?q=x');

        expect(window.location.search).toBe('?q=x');
    });

    it('is removed by an empty one, without a lone question mark left behind', () => {
        replaceSearch('');

        expect(window.location.href).toMatch(/\/app\/w\/shop#top$/);
    });

    it('leaves the state of the history alone', () => {
        window.history.replaceState({ scroll: 12 }, '', '/app/w/shop?old=1');

        replaceSearch('q=x');

        expect(window.history.state).toEqual({ scroll: 12 });
    });

    it('does not break the page when the browser refuses to change the address', () => {
        const spy = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {
            throw new DOMException('Denied', 'SecurityError');
        });

        expect(() => replaceSearch('q=x')).not.toThrow();

        spy.mockRestore();
    });
});
