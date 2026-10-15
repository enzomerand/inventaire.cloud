import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import accountData from '../../../resources/js/components/account-data.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { saveFile } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ saveFile: vi.fn() }));

describe('data card of the account page', () => {
    let component;

    beforeEach(() => {
        component = accountData();
        component.$root = { dataset: { url: '/api/v1/me/export' } };
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('fetches the export as a file and hands it to the browser, named after the day', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-15T09:30:00Z'));
        const file = new Blob(['{}'], { type: 'application/json' });
        http.get.mockResolvedValue(file);

        await component.download();

        expect(http.get).toHaveBeenCalledWith('/api/v1/me/export', { responseType: 'blob' });
        expect(saveFile).toHaveBeenCalledWith(file, 'inventaire-export-2026-10-15.json');
        expect(component.busy).toBe(false);
    });

    it('ignores a second click while the file is being fetched', async () => {
        http.get.mockResolvedValue(new Blob(['{}']));

        await Promise.all([component.download(), component.download()]);

        expect(http.get).toHaveBeenCalledTimes(1);
        expect(saveFile).toHaveBeenCalledTimes(1);
    });

    it('says why nothing was downloaded', async () => {
        http.get.mockRejectedValue(new HttpError(409, 'Une erreur est survenue.'));

        await component.download();

        expect(notify).toHaveBeenCalledWith('danger', 'Une erreur est survenue.');
        expect(saveFile).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    it('does not repeat what the HTTP helper already told (too many requests...)', async () => {
        const error = new HttpError(429, 'Too many');
        error.reported = true;
        http.get.mockRejectedValue(error);

        await component.download();

        expect(notify).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    it('stays silent when the request was cancelled', async () => {
        http.get.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

        await component.download();

        expect(notify).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });
});
