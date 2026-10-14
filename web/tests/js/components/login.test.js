import { beforeEach, describe, expect, it, vi } from 'vitest';

import login from '../../../resources/js/components/login.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { post: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key, replacements = {}) => {
        const messages = {
            'login.error.not_stateful': 'Not available from this address',
            'login.error.throttled': 'Wait :seconds seconds',
            'login.notice.code_resent': 'A new code was sent',
            'error.too_many_requests': 'Slow down',
        };

        return Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(':' + name, value),
            messages[key] ?? key,
        );
    },
}));

/** Builds a validation failure like the API's 422 answers. */
function refusal(code, errors, message = 'The given data was invalid.') {
    return new HttpError(422, message, { errors, code });
}

describe('sign-in page', () => {
    let component;
    let focused;

    beforeEach(() => {
        focused = [];
        component = login();
        // Alpine injects these: the root element with the data-* attributes, $refs, $nextTick.
        component.$root = {
            dataset: { codeUrl: '/api/v1/auth/code', verifyUrl: '/api/v1/auth/verify', appUrl: '/app' },
        };
        component.$refs = {
            email: { focus: () => focused.push('email') },
            code: { focus: () => focused.push('code') },
            name: { focus: () => focused.push('name') },
        };
        component.$nextTick = (callback) => callback();
    });

    describe('asking for a code', () => {
        it('starts on the address phase', () => {
            expect(component.phase).toBe('email');
            expect(component.error).toBe('');
        });

        it('sends the trimmed address silently and moves to the code phase', async () => {
            http.post.mockResolvedValue({ data: { email: 'ada@example.com', expires_in: 600 } });
            component.email = '  Ada@Example.com ';

            await component.requestCode();

            expect(http.post).toHaveBeenCalledWith('/api/v1/auth/code', { email: 'Ada@Example.com' }, { silent: true });
            expect(component.phase).toBe('code');
            // The page shows the address the code really went to.
            expect(component.email).toBe('ada@example.com');
            expect(focused).toEqual(['code']);
            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('ignores a second submit while one is in flight', async () => {
            http.post.mockResolvedValue({ data: { email: 'ada@example.com' } });
            component.email = 'ada@example.com';

            await Promise.all([component.requestCode(), component.requestCode()]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('tells the person a new code was sent when they asked again', async () => {
            http.post.mockResolvedValue({ data: { email: 'ada@example.com' } });
            component.email = 'ada@example.com';
            component.phase = 'code';
            component.code = '123';

            await component.resend();

            expect(notify).toHaveBeenCalledWith('success', 'A new code was sent');
            expect(component.code).toBe('');
        });

        it('shows the validation message of a bad address and stays on the address phase', async () => {
            http.post.mockRejectedValue(refusal(null, { email: ['The email field must be a valid email address.'] }));
            component.email = 'nope';

            await component.requestCode();

            expect(component.phase).toBe('email');
            expect(component.error).toBe('The email field must be a valid email address.');
            expect(component.busy).toBe(false);
        });

        it('tells how long to wait when sending is throttled', async () => {
            http.post.mockRejectedValue(new HttpError(429, 'Too many attempts.', { retryAfter: 120 }));

            await component.requestCode();

            expect(component.error).toBe('Wait 120 seconds');
            expect(component.phase).toBe('email');
        });

        it('says so without a duration when the server gave none', async () => {
            http.post.mockRejectedValue(new HttpError(429, 'Too many attempts.'));

            await component.requestCode();

            expect(component.error).toBe('Slow down');
        });

        it('explains a refusal that comes from the host the page is served from', async () => {
            http.post.mockRejectedValue(new HttpError(403, 'Forbidden', { code: 'not_stateful' }));

            await component.requestCode();

            expect(component.error).toBe('Not available from this address');
        });

        it('shows the helper message of network and server failures', async () => {
            http.post.mockRejectedValue(new HttpError(0, 'Network down', { kind: 'network' }));

            await component.requestCode();

            expect(component.error).toBe('Network down');
            expect(component.busy).toBe(false);
        });

        it('says nothing about a cancelled request', async () => {
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.requestCode();

            expect(component.error).toBe('');
            expect(component.busy).toBe(false);
        });
    });

    describe('typing the code back', () => {
        beforeEach(() => {
            component.phase = 'code';
            component.email = 'ada@example.com';
            component.code = '123 456';
        });

        it('sends the address and the code without spaces, then goes to the signed-in space', async () => {
            http.post.mockResolvedValue({ data: { id: 1 } });

            await component.verify();

            expect(http.post).toHaveBeenCalledWith(
                '/api/v1/auth/verify',
                { email: 'ada@example.com', code: '123456' },
                { silent: true },
            );
            expect(redirectTo).toHaveBeenCalledWith('/app');
        });

        it('stays locked while the page leaves, so nothing is submitted twice', async () => {
            http.post.mockResolvedValue({ data: { id: 1 } });

            await component.verify();
            await component.verify();

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.busy).toBe(true);
        });

        it('asks for a name when the code is right but the address has no account', async () => {
            http.post.mockRejectedValue(
                refusal('name_required', { name: ['Enter your name to create your account.'] }),
            );

            await component.verify();

            expect(component.phase).toBe('name');
            expect(component.error).toBe('');
            expect(focused).toEqual(['name']);
            expect(component.busy).toBe(false);
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('sends the name with the same code to create the account', async () => {
            http.post.mockRejectedValueOnce(refusal('name_required', { name: ['x'] }));
            await component.verify();

            http.post.mockResolvedValue({ data: { id: 2 } });
            component.name = '  Grace Hopper ';

            await component.verify();

            expect(http.post).toHaveBeenLastCalledWith(
                '/api/v1/auth/verify',
                { email: 'ada@example.com', code: '123456', name: 'Grace Hopper' },
                { silent: true },
            );
            expect(redirectTo).toHaveBeenCalledWith('/app');
        });

        it('shows the reminder again when the name is still missing', async () => {
            component.phase = 'name';
            http.post.mockRejectedValue(
                refusal('name_required', { name: ['Enter your name to create your account.'] }, 'Enter your name.'),
            );

            await component.verify();

            expect(component.phase).toBe('name');
            expect(component.error).toBe('Enter your name to create your account.');
        });

        it('shows the attempts left for a wrong code and clears the box', async () => {
            http.post.mockRejectedValue(refusal('login_code_wrong', { code: ['Incorrect code. 4 attempts left.'] }));

            await component.verify();

            expect(component.error).toBe('Incorrect code. 4 attempts left.');
            expect(component.code).toBe('');
            expect(focused).toEqual(['code']);
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it.each(['login_code_expired', 'login_code_exhausted'])(
            'goes back to the code phase, where a new code is requested, when it is %s',
            async (code) => {
                component.phase = 'name';
                http.post.mockRejectedValue(refusal(code, { code: ['This code has expired: request a new one.'] }));

                await component.verify();

                expect(component.phase).toBe('code');
                expect(component.error).toBe('This code has expired: request a new one.');
            },
        );

        it('shows a throttled answer with the time to wait', async () => {
            http.post.mockRejectedValue(new HttpError(429, 'Too many attempts.', { retryAfter: 30 }));

            await component.verify();

            expect(component.error).toBe('Wait 30 seconds');
            expect(component.busy).toBe(false);
        });
    });

    describe('changing the address', () => {
        it('goes back to the address phase and forgets the code and the name', () => {
            component.phase = 'name';
            component.code = '123456';
            component.name = 'Grace';
            component.error = 'Something';

            component.back();

            expect(component.phase).toBe('email');
            expect(component.code).toBe('');
            expect(component.name).toBe('');
            expect(component.error).toBe('');
            expect(focused).toEqual(['email']);
        });
    });
});
