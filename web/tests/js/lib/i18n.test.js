import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** Loads a fresh copy of the module, which caches the messages it parsed. */
async function loadI18n(messages) {
    vi.resetModules();
    document.body.innerHTML = messages
        ? `<script type="application/json" id="client-messages">${JSON.stringify(messages)}</script>`
        : '';

    return import('../../../resources/js/lib/i18n.js');
}

describe('t()', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('returns the message of a key', async () => {
        const { t } = await loadI18n({ 'error.generic': 'Something went wrong.' });

        expect(t('error.generic')).toBe('Something went wrong.');
    });

    it('returns the key itself when the server did not provide the message', async () => {
        const { t } = await loadI18n({});

        expect(t('unknown.key')).toBe('unknown.key');
    });

    it('survives a missing or malformed messages element', async () => {
        expect((await loadI18n(null)).t('a.key')).toBe('a.key');

        vi.resetModules();
        document.body.innerHTML = '<script type="application/json" id="client-messages">{broken</script>';

        expect((await import('../../../resources/js/lib/i18n.js')).t('a.key')).toBe('a.key');
    });

    it('fills the :placeholders of a message', async () => {
        const { t } = await loadI18n({ wait: 'Try again in :seconds seconds, :name.' });

        expect(t('wait', { seconds: 30, name: 'Ada' })).toBe('Try again in 30 seconds, Ada.');
    });

    it('fills a placeholder used several times, and leaves unknown ones alone', async () => {
        const { t } = await loadI18n({ twice: ':n and :n and :other' });

        expect(t('twice', { n: 2 })).toBe('2 and 2 and :other');
    });
});

describe('tn()', () => {
    const MESSAGES = {
        'items.one': ':count item',
        'items.other': ':count items',
        'delete.one': 'Delete :name and its :count item?',
        'delete.other': 'Delete :name and its :count items?',
        'only.other': ':count things',
    };

    afterEach(() => {
        document.documentElement.lang = 'en';
    });

    it('picks the form that fits the count in English', async () => {
        const { tn } = await loadI18n(MESSAGES);

        expect(tn('items', 1)).toBe('1 item');
        expect(tn('items', 0)).toBe('0 items');
        expect(tn('items', 2)).toBe('2 items');
    });

    it('follows the plural rules of the language of the page: zero is singular in French', async () => {
        document.documentElement.lang = 'fr';
        const { tn } = await loadI18n(MESSAGES);

        expect(tn('items', 0)).toBe('0 item');
        expect(tn('items', 1)).toBe('1 item');
        expect(tn('items', 2)).toBe('2 items');
    });

    it('fills the other placeholders too', async () => {
        const { tn } = await loadI18n(MESSAGES);

        expect(tn('delete', 1, { name: 'Tools' })).toBe('Delete Tools and its 1 item?');
        expect(tn('delete', 5, { name: 'Tools' })).toBe('Delete Tools and its 5 items?');
    });

    it('falls back to the "other" form when the language needs a form the messages do not have', async () => {
        const { tn } = await loadI18n(MESSAGES);

        expect(tn('only', 1)).toBe('1 things');
    });

    it('falls back to the "other" form for a language tag the browser rejects', async () => {
        document.documentElement.lang = 'not a language';
        const { tn } = await loadI18n(MESSAGES);

        expect(tn('items', 1)).toBe('1 items');
    });

    it('shows the key of a message the server did not provide', async () => {
        const { tn } = await loadI18n({});

        expect(tn('missing', 3)).toBe('missing.other');
    });
});
