import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import accountDeletion from '../../../resources/js/components/account-deletion.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { post: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key, replacements = {}) => {
        const messages = {
            'account.notice.code_sent': 'Code sent',
            'login.notice.code_resent': 'New code sent',
            'account.error.last_administrator': 'You are the last administrator',
            'account.error.sole_owner': 'Hand your workspaces over',
            'login.error.throttled': 'Wait :seconds seconds',
            'login.error.throttled_minutes': 'Wait :minutes minutes',
            'error.too_many_requests': 'Slow down',
        };

        return Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(':' + name, value),
            messages[key] ?? key,
        );
    },
}));

describe('danger zone of the account page', () => {
    let component;
    let focused;

    beforeEach(() => {
        focused = [];
        component = accountDeletion();
        // Alpine injects these: the root element with the data-* attributes, $refs, $nextTick.
        component.$root = { dataset: { codeUrl: '/api/v1/me/deletion-code', url: '/api/v1/me', homeUrl: '/' } };
        component.$refs = { code: { focus: () => focused.push('code') } };
        component.$nextTick = (callback) => callback();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('asking for the code', () => {
        it('starts idle', () => {
            expect(component.phase).toBe('idle');
            expect(component.error).toBe('');
            expect(component.blocking).toEqual([]);
        });

        it('asks for the code silently, shows the confirmation and says the code was sent', async () => {
            http.post.mockResolvedValue({ data: { expires_in: 600 } });

            await component.start();

            expect(http.post).toHaveBeenCalledWith('/api/v1/me/deletion-code', undefined, { silent: true });
            expect(component.phase).toBe('code');
            expect(focused).toEqual(['code']);
            expect(notify).toHaveBeenCalledWith('success', 'Code sent');
            expect(component.busy).toBe(false);
        });

        it('says a new code was sent when asked for another one, and starts the code box again', async () => {
            http.post.mockResolvedValue({ data: {} });
            component.phase = 'code';
            component.code = '123456';

            await component.resend();

            expect(notify).toHaveBeenCalledWith('success', 'New code sent');
            expect(component.code).toBe('');
            expect(component.phase).toBe('code');
        });

        it('ignores a second click while the request is in flight', async () => {
            http.post.mockResolvedValue({ data: {} });

            await Promise.all([component.start(), component.start()]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('says that the last administrator cannot delete the account, and asks for no code', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'last_administrator' }));

            await component.start();

            expect(component.phase).toBe('idle');
            expect(component.error).toBe('You are the last administrator');
        });

        it('lists the workspaces to hand over before the account can be deleted', async () => {
            const workspaces = [
                { id: 'a', name: 'Shared' },
                { id: 'b', name: 'Team' },
            ];
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'sole_owner_of_shared_workspaces', body: { workspaces } }),
            );

            await component.start();

            expect(component.phase).toBe('idle');
            expect(component.error).toBe('Hand your workspaces over');
            expect(component.blocking).toEqual(workspaces);
        });

        it('tells how long to wait when throttled, in seconds then in minutes', async () => {
            http.post.mockRejectedValueOnce(new HttpError(429, 'Too many', { retryAfter: 45 }));
            await component.start();
            expect(component.error).toBe('Wait 45 seconds');

            http.post.mockRejectedValueOnce(new HttpError(429, 'Too many', { retryAfter: 600 }));
            await component.start();
            expect(component.error).toBe('Wait 10 minutes');

            http.post.mockRejectedValueOnce(new HttpError(429, 'Too many'));
            await component.start();
            expect(component.error).toBe('Slow down');
        });

        it('shows the message of any other failure', async () => {
            http.post.mockRejectedValue(new HttpError(500, 'The server hit an error.'));

            await component.start();

            expect(component.error).toBe('The server hit an error.');
            expect(component.phase).toBe('idle');
        });

        it('stays silent when the request was cancelled', async () => {
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.start();

            expect(component.error).toBe('');
            expect(component.busy).toBe(false);
        });
    });

    describe('confirming', () => {
        beforeEach(() => {
            component.phase = 'code';
        });

        it('needs a whole code and the box ticked', () => {
            expect(component.ready).toBe(false);

            component.code = '123 456';
            expect(component.ready).toBe(false);

            component.understood = true;
            expect(component.ready).toBe(true);

            component.code = '12345';
            expect(component.ready).toBe(false);
        });

        it('sends the code without its spaces, silently, then leaves for the home page', async () => {
            http.delete.mockResolvedValue(null);
            component.code = '123 456';
            component.understood = true;

            await component.confirm();

            expect(http.delete).toHaveBeenCalledWith('/api/v1/me', { silent: true, data: { code: '123456' } });
            expect(redirectTo).toHaveBeenCalledWith('/');
        });

        it('keeps the card locked once the account is gone, so nothing can be sent twice', async () => {
            http.delete.mockResolvedValue(null);
            component.code = '123456';
            component.understood = true;

            await component.confirm();

            expect(component.busy).toBe(true);
        });

        it('sends nothing before the person is ready, and not twice', async () => {
            await component.confirm();
            expect(http.delete).not.toHaveBeenCalled();

            http.delete.mockResolvedValue(null);
            component.code = '123456';
            component.understood = true;
            await Promise.all([component.confirm(), component.confirm()]);

            expect(http.delete).toHaveBeenCalledTimes(1);
        });

        it('shows the server message of a wrong code and empties the box for the next try', async () => {
            http.delete.mockRejectedValue(
                new HttpError(422, 'Incorrect code. 4 attempts left.', {
                    code: 'deletion_code_wrong',
                    errors: { code: ['Code incorrect. 4 essais restants.'] },
                }),
            );
            component.code = '123456';
            component.understood = true;

            await component.confirm();

            expect(component.error).toBe('Code incorrect. 4 essais restants.');
            expect(component.code).toBe('');
            expect(focused).toEqual(['code']);
            expect(redirectTo).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('keeps the code box for an expired code, whose message says to ask for another', async () => {
            http.delete.mockRejectedValue(
                new HttpError(422, 'Expired', {
                    code: 'deletion_code_expired',
                    errors: { code: ['Ce code a expiré.'] },
                }),
            );
            component.code = '123456';
            component.understood = true;

            await component.confirm();

            expect(component.error).toBe('Ce code a expiré.');
            expect(component.phase).toBe('code');
            expect(component.code).toBe('123456');
        });

        it('goes back to the start, saying why, when the deletion is refused after all', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'last_administrator' }));
            component.code = '123456';
            component.understood = true;

            await component.confirm();

            expect(component.phase).toBe('idle');
            expect(component.error).toBe('You are the last administrator');
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('says the page is about to reload when it is stale (419)', async () => {
            vi.useFakeTimers();
            http.delete.mockRejectedValue(new HttpError(419, 'Your session has expired. The page is about to reload.'));
            component.code = '123456';
            component.understood = true;

            await component.confirm();

            expect(component.error).toBe('Your session has expired. The page is about to reload.');
            expect(reloadPage).not.toHaveBeenCalled();

            vi.advanceTimersByTime(1500);

            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('gives up: back to idle with nothing left in the card', () => {
            component.code = '123456';
            component.understood = true;
            component.error = 'Something';
            component.blocking = [{ id: 'a', name: 'Shared' }];

            component.cancel();

            expect(component.phase).toBe('idle');
            expect(component.code).toBe('');
            expect(component.understood).toBe(false);
            expect(component.error).toBe('');
            expect(component.blocking).toEqual([]);
        });
    });
});
