import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import accountProfile from '../../../resources/js/components/account-profile.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { patch: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key) => ({ 'account.notice.saved': 'Saved. Reloading.' })[key] ?? key,
}));

describe('profile card of the account page', () => {
    let component;

    beforeEach(() => {
        component = accountProfile();
        // Alpine injects $root: the element carrying x-data and the data-* attributes.
        component.$root = { dataset: { url: '/api/v1/me', name: 'Ada Lovelace', locale: 'fr' } };
        component.init();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('starts with the name and the language of the account', () => {
        expect(component.name).toBe('Ada Lovelace');
        expect(component.locale).toBe('fr');
        expect(component.busy).toBe(false);
    });

    it('copes with a root that carries no values', () => {
        const bare = accountProfile();
        bare.$root = { dataset: {} };

        bare.init();

        expect(bare.name).toBe('');
        expect(bare.locale).toBe('');
    });

    it('can be saved only with a name, and when nothing is in flight', () => {
        expect(component.canSave).toBe(true);

        component.name = '   ';
        expect(component.canSave).toBe(false);

        component.name = 'Ada';
        component.busy = true;
        expect(component.canSave).toBe(false);
    });

    it('saves the trimmed name and the language, says so, then reloads once the message was readable', async () => {
        vi.useFakeTimers();
        http.patch.mockResolvedValue({ data: {} });
        component.name = '  Ada Byron ';
        component.locale = 'en';

        await component.save();

        expect(http.patch).toHaveBeenCalledWith('/api/v1/me', { name: 'Ada Byron', locale: 'en' });
        expect(notify).toHaveBeenCalledWith('success', 'Saved. Reloading.');
        expect(reloadPage).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1500);

        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('ignores a second submit while one is in flight', async () => {
        vi.useFakeTimers();
        http.patch.mockResolvedValue({ data: {} });

        await Promise.all([component.save(), component.save()]);

        expect(http.patch).toHaveBeenCalledTimes(1);
    });

    it('shows the translated validation message of a refused name and lets the person try again', async () => {
        http.patch.mockRejectedValue(
            new HttpError(422, 'The given data was invalid.', { errors: { name: ['Le nom est trop long.'] } }),
        );

        await component.save();

        expect(notify).toHaveBeenCalledWith('danger', 'Le nom est trop long.');
        expect(reloadPage).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    it('shows the translated validation message of a refused language', async () => {
        http.patch.mockRejectedValue(
            new HttpError(422, 'The given data was invalid.', { errors: { locale: ['Langue invalide.'] } }),
        );

        await component.save();

        expect(notify).toHaveBeenCalledWith('danger', 'Langue invalide.');
    });

    it('does not repeat what the HTTP helper already told', async () => {
        const error = new HttpError(0, 'Network down', { kind: 'network' });
        error.reported = true;
        http.patch.mockRejectedValue(error);

        await component.save();

        expect(notify).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    it('stays silent when the request was cancelled', async () => {
        http.patch.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

        await component.save();

        expect(notify).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });
});
