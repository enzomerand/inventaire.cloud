import { beforeEach, describe, expect, it, vi } from 'vitest';

import accountSecurity from '../../../resources/js/components/account-security.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { post: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key) => ({ 'account.notice.signed_out_everywhere': 'Signed out elsewhere' })[key] ?? key,
}));

describe('security card of the account page', () => {
    let component;

    beforeEach(() => {
        component = accountSecurity();
        component.$root = { dataset: { url: '/api/v1/me/sign-out-everywhere' } };
    });

    it('asks for a confirmation before doing anything, and can be given up', () => {
        expect(component.confirming).toBe(false);

        component.ask();
        expect(component.confirming).toBe(true);
        expect(http.post).not.toHaveBeenCalled();

        component.cancel();
        expect(component.confirming).toBe(false);
    });

    it('signs the other devices out, says so and closes the confirmation', async () => {
        http.post.mockResolvedValue(null);
        component.ask();

        await component.signOutEverywhere();

        expect(http.post).toHaveBeenCalledWith('/api/v1/me/sign-out-everywhere');
        expect(notify).toHaveBeenCalledWith('success', 'Signed out elsewhere');
        expect(component.confirming).toBe(false);
        expect(component.busy).toBe(false);
    });

    it('ignores a second click while the request is in flight', async () => {
        http.post.mockResolvedValue(null);

        await Promise.all([component.signOutEverywhere(), component.signOutEverywhere()]);

        expect(http.post).toHaveBeenCalledTimes(1);
    });

    it('keeps the confirmation and says why when it failed', async () => {
        http.post.mockRejectedValue(new HttpError(403, 'Not available from this address'));
        component.ask();

        await component.signOutEverywhere();

        expect(notify).toHaveBeenCalledWith('danger', 'Not available from this address');
        expect(notify).not.toHaveBeenCalledWith('success', expect.anything());
        expect(component.confirming).toBe(true);
        expect(component.busy).toBe(false);
    });

    it('does not repeat what the HTTP helper already told', async () => {
        const error = new HttpError(429, 'Too many');
        error.reported = true;
        http.post.mockRejectedValue(error);

        await component.signOutEverywhere();

        expect(notify).not.toHaveBeenCalled();
    });

    it('stays silent when the request was cancelled', async () => {
        http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

        await component.signOutEverywhere();

        expect(notify).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });
});
