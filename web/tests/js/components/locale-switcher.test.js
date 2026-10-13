import { beforeEach, describe, expect, it, vi } from 'vitest';

import localeSwitcher from '../../../resources/js/components/locale-switcher.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { put: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));

describe('locale switcher', () => {
    let component;

    beforeEach(() => {
        component = localeSwitcher('/locale');
    });

    it('stores the locale then reloads the page', async () => {
        http.put.mockResolvedValue(null);

        await component.select('en');

        expect(http.put).toHaveBeenCalledWith('/locale', { locale: 'en' });
        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('ignores clicks while a change is in flight', async () => {
        http.put.mockResolvedValue(null);

        await Promise.all([component.select('en'), component.select('fr')]);

        expect(http.put).toHaveBeenCalledTimes(1);
    });

    it('shows the translated validation message of a rejected locale and stays on the page', async () => {
        http.put.mockRejectedValue(
            new HttpError(422, 'The given data was invalid.', { errors: { locale: ['Langue invalide.'] } }),
        );

        await component.select('xx');

        expect(notify).toHaveBeenCalledWith('danger', 'Langue invalide.');
        expect(reloadPage).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    it('falls back to the helper message for other unreported client errors', async () => {
        http.put.mockRejectedValue(new HttpError(409, 'Une erreur est survenue.'));

        await component.select('en');

        expect(notify).toHaveBeenCalledWith('danger', 'Une erreur est survenue.');
    });

    it('does not repeat a failure the HTTP helper already reported', async () => {
        const error = new HttpError(500, 'Boom');
        error.reported = true;
        http.put.mockRejectedValue(error);

        await component.select('en');

        expect(notify).not.toHaveBeenCalled();
        expect(reloadPage).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    it('stays silent when the request was cancelled', async () => {
        http.put.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

        await component.select('en');

        expect(notify).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });
});
