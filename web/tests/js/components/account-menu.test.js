import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import accountMenu from '../../../resources/js/components/account-menu.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { post: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));

describe('account menu', () => {
    let component;

    beforeEach(() => {
        component = accountMenu();
        component.$root = { dataset: { logoutUrl: '/api/v1/auth/logout', homeUrl: '/' } };
    });

    it('signs out through the API, silently, then goes to the home page', async () => {
        http.post.mockResolvedValue(null);

        await component.signOut();

        expect(http.post).toHaveBeenCalledWith('/api/v1/auth/logout', undefined, { silent: true });
        expect(redirectTo).toHaveBeenCalledWith('/');
    });

    it('ignores a second click while signing out', async () => {
        http.post.mockResolvedValue(null);

        await Promise.all([component.signOut(), component.signOut()]);

        expect(http.post).toHaveBeenCalledTimes(1);
    });

    it('leaves all the same when the session was already over (401)', async () => {
        http.post.mockRejectedValue(new HttpError(401, 'You are signed out.'));

        await component.signOut();

        expect(redirectTo).toHaveBeenCalledWith('/');
        expect(notify).not.toHaveBeenCalled();
    });

    it('stays on the page and says why when signing out failed', async () => {
        http.post.mockRejectedValue(new HttpError(0, 'Network down', { kind: 'network' }));

        await component.signOut();

        expect(notify).toHaveBeenCalledWith('danger', 'Network down');
        expect(redirectTo).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });

    describe('when the page is stale (419)', () => {
        beforeEach(() => {
            vi.useFakeTimers();
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it('says the page is about to reload, and reloads it', async () => {
            http.post.mockRejectedValue(new HttpError(419, 'Your session has expired. The page is about to reload.'));

            await component.signOut();

            expect(notify).toHaveBeenCalledWith('warning', 'Your session has expired. The page is about to reload.');
            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
            expect(reloadPage).not.toHaveBeenCalled();

            vi.advanceTimersByTime(1500);

            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('does not reload for the other failures', async () => {
            http.post.mockRejectedValue(new HttpError(0, 'Network down', { kind: 'network' }));

            await component.signOut();
            vi.advanceTimersByTime(5000);

            expect(reloadPage).not.toHaveBeenCalled();
        });
    });

    it('stays silent when the request was cancelled', async () => {
        http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

        await component.signOut();

        expect(notify).not.toHaveBeenCalled();
        expect(redirectTo).not.toHaveBeenCalled();
        expect(component.busy).toBe(false);
    });
});
