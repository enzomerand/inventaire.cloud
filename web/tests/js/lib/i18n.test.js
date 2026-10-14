import { beforeEach, describe, expect, it, vi } from 'vitest';

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
