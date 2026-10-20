import { beforeEach, describe, expect, it, vi } from 'vitest';

import workspaceInvitations from '../../../resources/js/components/workspace-invitations.js';
import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return {
        ...original,
        http: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
        scheduleNavigation: vi.fn(),
    };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));
vi.mock('../../../resources/js/grid/rows.js', () => ({ momentText: (value) => (value ? `<${value}>` : '') }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const URL = '/api/v1/workspaces/shop/invitations';

/** An invitation as the API describes it to the owner. */
function invitation(id, overrides = {}) {
    return {
        id,
        email: `${id}@example.test`,
        role: 'editor',
        invited_by: { id: 'm-owner', name: 'Olga' },
        expires_at: '2026-11-01T10:00:00+00:00',
        created_at: '2026-10-18T10:00:00+00:00',
        ...overrides,
    };
}

/** An element that remembers it was focused. */
function focusable(log, name) {
    return { focus: () => log.push(name) };
}

/** A refusal of the rules, as the API words it. */
function refusal(status, code, extra = {}) {
    return new HttpError(status, 'Something went wrong.', {
        code,
        body: { code, ...extra },
        ...(extra.retryAfter ? { retryAfter: extra.retryAfter } : {}),
    });
}

describe('the invitations of the members page', () => {
    let component;
    let log;
    let rowButtons;

    beforeEach(() => {
        vi.resetAllMocks();
        log = [];
        rowButtons = [];
        component = workspaceInvitations();
        // Alpine injects these: the root element (with the data-* attributes and the elements the cursor goes to), $nextTick.
        component.$root = {
            dataset: { url: URL },
            querySelector: (selector) => {
                const [, name] = selector.match(/data-focus="(\w+)"/);

                return focusable(log, name);
            },
            querySelectorAll: (selector) => {
                const [, action] = selector.match(/data-invitation-action="(\w+)"/);

                return rowButtons.filter((button) => button.dataset.invitationAction === action);
            },
        };
        component.$nextTick = (callback) => callback();
        component.rootElement = component.$root;
    });

    describe('the list', () => {
        it('is read when the page opens, the newest first as the API gives it', async () => {
            http.get.mockResolvedValue({ data: [invitation('b'), invitation('a')] });
            expect(component.loading).toBe(true);
            expect(component.empty).toBe(false);

            await component.init();

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.invitations.map((one) => one.id)).toEqual(['b', 'a']);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);
        });

        it('says it is empty when no invitation waits', async () => {
            http.get.mockResolvedValue({ data: [] });

            await component.init();

            expect(component.empty).toBe(true);
        });

        it('says it could not be read, without claiming that it is empty, and reads it again on demand', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server error'));
            await component.init();

            expect(component.loadFailed).toBe(true);
            expect(component.empty).toBe(false);

            http.get.mockResolvedValue({ data: [invitation('a')] });
            await component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.invitations).toHaveLength(1);
        });

        it('stays quiet when the request was cancelled', async () => {
            http.get.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.init();

            expect(component.loadFailed).toBe(false);
            expect(component.loading).toBe(false);
        });

        it('says everything about an invitation in words', () => {
            const sent = invitation('a', { role: 'viewer' });

            expect(component.roleLabel(sent)).toBe('Viewer');
            expect(component.roleLabel(invitation('b'))).toBe('Editor');
            expect(component.roleClass(sent)).not.toBe(component.roleClass(invitation('b')));
            expect(component.metaText(sent)).toBe('Invited by Olga · Expires <2026-11-01T10:00:00+00:00>');
            expect(component.metaText(invitation('c', { invited_by: null }))).toBe(
                'Expires <2026-11-01T10:00:00+00:00>',
            );
            expect(component.confirmText(sent)).toBe('Withdraw the invitation sent to a@example.test?');
            expect(component.resendLabel(sent)).toBe('Send again: a@example.test');
            expect(component.withdrawLabel(sent)).toBe('Withdraw the invitation sent to a@example.test');
        });
    });

    describe('the form', () => {
        it('needs an address, and nothing in flight', () => {
            expect(component.canInvite).toBe(false);

            component.form.email = '   ';
            expect(component.canInvite).toBe(false);

            component.form.email = 'new@example.test';
            expect(component.canInvite).toBe(true);

            component.busy = true;
            expect(component.canInvite).toBe(false);
        });

        it('offers an editor first', () => {
            expect(component.form).toEqual({ email: '', role: 'editor' });
        });

        it('sends nothing when it cannot be submitted', async () => {
            await component.invite();

            expect(http.post).not.toHaveBeenCalled();
        });

        it('invites the address, puts the invitation first, forgets the address, keeps the role and puts the cursor on the address', async () => {
            http.get.mockResolvedValue({ data: [invitation('a')] });
            await component.init();
            http.post.mockResolvedValue({ data: invitation('b', { email: 'new@example.test', role: 'viewer' }) });
            component.form.email = '  new@example.test ';
            component.form.role = 'viewer';

            await component.invite();

            expect(http.post).toHaveBeenCalledWith(
                URL,
                { email: 'new@example.test', role: 'viewer' },
                { silent: true },
            );
            expect(component.invitations.map((one) => one.id)).toEqual(['b', 'a']);
            expect(component.form).toEqual({ email: '', role: 'viewer' });
            expect(notify).toHaveBeenCalledWith('success', 'Invitation sent to new@example.test.');
            expect(log).toEqual(['email']);
            expect(component.busy).toBe(false);
            expect(component.formError).toBe('');
        });

        it('shows the messages of the server under the fields that were refused, and puts the cursor on the address', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'The email field must be a valid email address.', {
                    errors: {
                        email: ['The email field must be a valid email address.'],
                        role: ['The selected role is invalid.'],
                    },
                }),
            );
            component.form.email = 'nonsense';

            await component.invite();

            expect(component.errors).toEqual({
                email: 'The email field must be a valid email address.',
                role: 'The selected role is invalid.',
            });
            expect(component.isInvalid('email')).toBe(true);
            expect(component.isInvalid('role')).toBe(true);
            expect(component.formError).toBe('');
            expect(log).toEqual(['email']);
            expect(component.form.email).toBe('nonsense');
            expect(component.busy).toBe(false);
        });

        it('shows a validation message that is about no field in the alert of the form', async () => {
            http.post.mockRejectedValue(new HttpError(422, 'The given data was invalid.', { errors: {} }));
            component.form.email = 'a@example.test';

            await component.invite();

            expect(component.formError).toBe('The given data was invalid.');
        });

        it.each([
            ['already_member', 409, 'This person already belongs to the workspace.'],
            ['already_invited', 409, 'An invitation to this address is already waiting for an answer.'],
            ['not_owner', 403, 'Only an owner of this workspace can manage it.'],
        ])('tells the refusal "%s" in the alert of the form, in words of its own', async (code, status, message) => {
            http.post.mockRejectedValue(refusal(status, code));
            component.form.email = 'a@example.test';

            await component.invite();

            expect(component.formError).toBe(message);
            expect(component.errors).toEqual({});
            expect(component.form.email).toBe('a@example.test');
        });

        it('says how many places a workspace has when it is full', async () => {
            http.post.mockRejectedValue(refusal(409, 'member_limit_reached', { limit: 50 }));
            component.form.email = 'a@example.test';

            await component.invite();

            expect(component.formError).toBe(
                'Member limit reached (50 per workspace, invitations waiting for an answer included).',
            );
        });

        it('tells the pace of invitations, in seconds or in minutes', async () => {
            component.form.email = 'a@example.test';
            http.post.mockRejectedValue(new HttpError(429, 'Too many', { retryAfter: 30 }));

            await component.invite();
            expect(component.formError).toBe('Too many attempts. Try again in 30 seconds.');

            http.post.mockRejectedValue(new HttpError(429, 'Too many', { retryAfter: 600 }));
            await component.invite();
            expect(component.formError).toBe('Too many attempts. Try again in 10 minutes.');

            http.post.mockRejectedValue(new HttpError(429, 'Too many', { retryAfter: null }));
            await component.invite();
            expect(component.formError).toBe('Too many attempts. Please wait a moment before trying again.');
        });

        it('reloads the page when it is stale (an expired token, a session that ended, a person who is not the owner any more)', async () => {
            component.form.email = 'a@example.test';

            for (const failure of [
                new HttpError(419, 'Your session has expired. The page is about to reload.'),
                new HttpError(401, 'You are signed out. Please sign in again.'),
                refusal(403, 'not_owner'),
            ]) {
                scheduleNavigation.mockClear();
                http.post.mockRejectedValue(failure);

                await component.invite();

                expect(component.formError).not.toBe('');
                expect(scheduleNavigation).toHaveBeenCalledWith(reloadPage);
            }
        });

        it('forgets the previous refusal when it is tried again', async () => {
            component.form.email = 'a@example.test';
            http.post.mockRejectedValueOnce(refusal(409, 'already_member'));
            await component.invite();
            expect(component.formError).not.toBe('');

            http.post.mockResolvedValue({ data: invitation('a') });
            await component.invite();

            expect(component.formError).toBe('');
            expect(component.errors).toEqual({});
        });

        it('stays quiet when the request was cancelled', async () => {
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));
            component.form.email = 'a@example.test';

            await component.invite();

            expect(component.formError).toBe('');
            expect(component.busy).toBe(false);
        });
    });

    describe('sending again', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: [invitation('b'), invitation('a')] });
            await component.init();
        });

        it('asks for the same address once more, shows the new term and tells the owner', async () => {
            http.post.mockResolvedValue({ data: invitation('a', { expires_at: '2026-11-20T10:00:00+00:00' }) });

            await component.resend(component.invitations[1]);

            expect(http.post).toHaveBeenCalledWith(`${URL}/a/resend`, undefined, { silent: true });
            expect(component.invitations.map((one) => one.id)).toEqual(['b', 'a']);
            expect(component.invitations[1].expires_at).toBe('2026-11-20T10:00:00+00:00');
            expect(notify).toHaveBeenCalledWith('success', 'Invitation sent to a@example.test again.');
            expect(component.workingId).toBeNull();
        });

        it('says how long to wait when it comes too soon', async () => {
            http.post.mockRejectedValue(refusal(429, 'resend_too_soon', { retry_after: 540, retryAfter: 540 }));

            await component.resend(component.invitations[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'This invitation was sent a moment ago: wait 9 minutes before sending it again.',
            );
            expect(component.workingId).toBeNull();
        });

        it('rounds the wait up to a whole minute, and says "1 minute" in the singular', async () => {
            http.post.mockRejectedValue(refusal(429, 'resend_too_soon', { retryAfter: 20 }));

            await component.resend(component.invitations[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'This invitation was sent a moment ago: wait 1 minute before sending it again.',
            );
        });

        it('reads the wait in the body when the header did not come through', async () => {
            http.post.mockRejectedValue(refusal(429, 'resend_too_soon', { retry_after: 125 }));

            await component.resend(component.invitations[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'This invitation was sent a moment ago: wait 3 minutes before sending it again.',
            );
        });

        it('says it is too soon without a figure when the answer gives none', async () => {
            http.post.mockRejectedValue(refusal(429, 'resend_too_soon'));

            await component.resend(component.invitations[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'This invitation was sent a moment ago: wait a few minutes before sending it again.',
            );
        });

        it('tells the pace of invitations when it is the limiter that refuses', async () => {
            http.post.mockRejectedValue(new HttpError(429, 'Too many', { retryAfter: 90 }));

            await component.resend(component.invitations[1]);

            expect(notify).toHaveBeenCalledWith('danger', 'Too many attempts. Try again in 90 seconds.');
        });

        it('takes an invitation that is gone off the list', async () => {
            http.post.mockRejectedValue(new HttpError(404, 'This item no longer exists.'));

            await component.resend(component.invitations[1]);

            expect(component.invitations.map((one) => one.id)).toEqual(['b']);
            expect(notify).toHaveBeenCalledWith('danger', 'This item no longer exists.');
        });

        it('sends one request at a time', async () => {
            component.workingId = 'b';

            await component.resend(component.invitations[1]);

            expect(http.post).not.toHaveBeenCalled();
        });
    });

    describe('withdrawing', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: [invitation('b'), invitation('a')] });
            await component.init();
        });

        it('asks first, in the row, and puts the cursor on the button that confirms', () => {
            rowButtons.push({
                dataset: { invitationAction: 'confirm', invitationId: 'a' },
                focus: () => log.push('confirm:a'),
            });

            component.askWithdraw(component.invitations[1]);

            expect(component.isConfirming(component.invitations[1])).toBe(true);
            expect(component.isConfirming(component.invitations[0])).toBe(false);
            expect(log).toEqual(['confirm:a']);
        });

        it('can be given up, and the cursor goes back to the button that asked', () => {
            rowButtons.push({
                dataset: { invitationAction: 'withdraw', invitationId: 'a' },
                focus: () => log.push('withdraw:a'),
            });
            component.askWithdraw(component.invitations[1]);
            log.length = 0;

            component.cancelWithdraw();

            expect(component.isConfirming(component.invitations[1])).toBe(false);
            expect(log).toEqual(['withdraw:a']);
        });

        it('withdraws the invitation, tells the owner and puts the cursor on the heading', async () => {
            http.delete.mockResolvedValue(null);
            component.askWithdraw(component.invitations[1]);
            log.length = 0;

            await component.withdraw(component.invitations[1]);

            expect(http.delete).toHaveBeenCalledWith(`${URL}/a`, { silent: true });
            expect(component.invitations.map((one) => one.id)).toEqual(['b']);
            expect(component.confirmingId).toBeNull();
            expect(notify).toHaveBeenCalledWith('success', 'Invitation to a@example.test withdrawn.');
            expect(log).toEqual(['heading']);
            expect(component.workingId).toBeNull();
        });

        it('takes an invitation that is gone already off the list without a word of its own', async () => {
            http.delete.mockRejectedValue(new HttpError(404, 'This item no longer exists.'));

            await component.withdraw(component.invitations[1]);

            expect(component.invitations.map((one) => one.id)).toEqual(['b']);
            expect(notify).not.toHaveBeenCalled();
            expect(component.workingId).toBeNull();
        });

        it('says why when it fails otherwise', async () => {
            http.delete.mockRejectedValue(new HttpError(500, 'The server hit an error. Please try again in a moment.'));

            await component.withdraw(component.invitations[1]);

            expect(component.invitations).toHaveLength(2);
            expect(notify).toHaveBeenCalledWith('danger', 'The server hit an error. Please try again in a moment.');
        });

        it('stays quiet when the request was cancelled', async () => {
            http.delete.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.withdraw(component.invitations[1]);

            expect(notify).not.toHaveBeenCalled();
            expect(component.workingId).toBeNull();
        });
    });
});
