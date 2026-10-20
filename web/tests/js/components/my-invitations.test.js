import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import myInvitations from '../../../resources/js/components/my-invitations.js';
import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), delete: vi.fn() }, scheduleNavigation: vi.fn() };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn() }));
vi.mock('../../../resources/js/grid/rows.js', () => ({ momentText: (value) => (value ? `<${value}>` : '') }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const URL = '/api/v1/me/invitations';

/** An invitation as the API describes it to the person it is addressed to. */
function invitation(id, overrides = {}) {
    return {
        id,
        workspace: { id: `ws-${id}`, name: `Workspace ${id}` },
        role: 'editor',
        inviter: { name: 'Olga' },
        expires_at: '2026-11-01T10:00:00+00:00',
        ...overrides,
    };
}

/** A button of a card that remembers it was focused. */
function button(log, action, id) {
    return { dataset: { invitationAction: action, invitationId: id }, focus: () => log.push(`${action}:${id}`) };
}

/** A refusal of the rules, as the API words it. */
function refusal(status, code, extra = {}) {
    return new HttpError(status, 'Something went wrong.', { code, body: { code, ...extra } });
}

describe('the page of the invitations addressed to the person', () => {
    let component;
    let log;
    let buttons;
    let heard;
    let listener;

    beforeEach(() => {
        vi.resetAllMocks();
        log = [];
        buttons = [];
        heard = [];
        listener = (event) => heard.push(event.detail.count);
        window.addEventListener('invitations-changed', listener);
        component = myInvitations();
        // Alpine injects these: the root element (with the data-* attributes and the elements the cursor goes to), $nextTick.
        component.$root = {
            dataset: { url: URL, workspaceUrl: '/app/w/__id__' },
            querySelector: (selector) => {
                if (selector.includes('data-focus')) {
                    return { focus: () => log.push(selector.match(/data-focus="(\w+)"/)[1]) };
                }

                const [, action] = selector.match(/data-invitation-action="(\w+)"/);

                return buttons.find((button) => button.dataset.invitationAction === action) ?? null;
            },
            querySelectorAll: (selector) => {
                const [, action] = selector.match(/data-invitation-action="(\w+)"/);

                return buttons.filter((button) => button.dataset.invitationAction === action);
            },
        };
        component.$nextTick = (callback) => callback();
        component.rootElement = component.$root;
    });

    afterEach(() => {
        window.removeEventListener('invitations-changed', listener);
    });

    describe('the list', () => {
        it('is read when the page opens, and the header is told how many invitations wait', async () => {
            http.get.mockResolvedValue({ data: [invitation('b'), invitation('a')] });
            expect(component.loading).toBe(true);
            expect(component.empty).toBe(false);

            await component.init();

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.invitations.map((one) => one.id)).toEqual(['b', 'a']);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);
            expect(heard).toEqual([2]);
        });

        it('says it is empty when no invitation waits', async () => {
            http.get.mockResolvedValue({ data: [] });

            await component.init();

            expect(component.empty).toBe(true);
            expect(heard).toEqual([0]);
        });

        it('says it could not be read, without claiming that it is empty, and reads it again on demand', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server error'));
            await component.init();

            expect(component.loadFailed).toBe(true);
            expect(component.empty).toBe(false);
            expect(heard).toEqual([]);

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
            const received = invitation('a', { role: 'viewer' });

            expect(component.roleLabel(received)).toBe('Viewer');
            expect(component.roleClass(received)).not.toBe(component.roleClass(invitation('b')));
            expect(component.inviterText(received)).toBe('Invited by Olga');
            expect(component.inviterText(invitation('c', { inviter: null }))).toBe('');
            expect(component.expiresText(received)).toBe('Expires <2026-11-01T10:00:00+00:00>');
            expect(component.acceptLabel(received)).toBe('Accept the invitation to Workspace a');
            expect(component.declineLabel(received)).toBe('Decline the invitation to Workspace a');
            expect(component.declineText(received)).toBe('Decline the invitation to Workspace a?');
            expect(component.declineTextId(received)).toBe(`invitation-decline-text-${received.id}`);
        });
    });

    describe('accepting', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: [invitation('b'), invitation('a')] });
            await component.init();
            heard.length = 0;
        });

        it('accepts the invitation, tells the person and takes them to the workspace once the notice has been read', async () => {
            http.post.mockResolvedValue({ data: { id: 'ws-a', name: 'Workspace a', role: 'editor' } });

            await component.accept(component.invitations[1]);

            expect(http.post).toHaveBeenCalledWith(`${URL}/a/accept`);
            expect(notify).toHaveBeenCalledWith('success', 'You joined Workspace a.');
            expect(redirectTo).not.toHaveBeenCalled();

            scheduleNavigation.mock.calls[0][0]();
            expect(redirectTo).toHaveBeenCalledWith('/app/w/ws-a');
            // Everything stays disabled until the page is left.
            expect(component.busy).toBe(true);
            expect(component.isWorking(component.invitations[1])).toBe(true);
            expect(component.isWorking(component.invitations[0])).toBe(false);
        });

        it('encodes the identifier of the invitation in the address', async () => {
            http.post.mockResolvedValue({ data: { id: 'ws' } });

            await component.accept(invitation('a/b'));

            expect(http.post.mock.calls[0][0]).toBe(`${URL}/a%2Fb/accept`);
        });

        it('sends nothing twice', async () => {
            component.busy = true;

            await component.accept(component.invitations[1]);

            expect(http.post).not.toHaveBeenCalled();
        });

        it('says that the workspace is full, in words for the person who accepts, and frees the controls', async () => {
            http.post.mockRejectedValue(refusal(409, 'member_limit_reached', { limit: 50 }));

            await component.accept(component.invitations[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'This workspace cannot take more members for now: ask its owner to make room.',
            );
            expect(component.busy).toBe(false);
            expect(component.acceptingId).toBeNull();
            expect(component.invitations).toHaveLength(2);
            expect(scheduleNavigation).not.toHaveBeenCalled();
        });

        it('says how many workspaces an account may belong to when the person is in as many as that', async () => {
            http.post.mockRejectedValue(refusal(409, 'membership_limit_reached', { limit: 100 }));

            await component.accept(component.invitations[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'You already belong to as many workspaces as an account can (100): leave one, then accept.',
            );
        });

        it('takes an invitation that is gone (answered, withdrawn or expired) off the list and tells the header', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.post.mockRejectedValue(gone);

            await component.accept(component.invitations[1]);

            expect(component.invitations.map((one) => one.id)).toEqual(['b']);
            expect(heard).toEqual([1]);
            // The HTTP helper already told the person.
            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('stays quiet when the request was cancelled', async () => {
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.accept(component.invitations[1]);

            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });
    });

    describe('declining', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: [invitation('b'), invitation('a')] });
            await component.init();
            heard.length = 0;
        });

        it('asks first, in the card, and puts the cursor on the button that confirms', () => {
            buttons.push(button(log, 'confirm', 'a'));

            component.askDecline(component.invitations[1]);

            expect(component.isConfirming(component.invitations[1])).toBe(true);
            expect(component.isConfirming(component.invitations[0])).toBe(false);
            expect(log).toEqual(['confirm:a']);
        });

        it('can be given up, and the cursor goes back to the button that asked', () => {
            buttons.push(button(log, 'decline', 'a'));
            component.askDecline(component.invitations[1]);
            log.length = 0;

            component.cancelDecline();

            expect(component.isConfirming(component.invitations[1])).toBe(false);
            expect(log).toEqual(['decline:a']);
        });

        it('declines the invitation, tells the person and the header, and puts the cursor on the next invitation', async () => {
            http.delete.mockResolvedValue(null);
            buttons.push(button(log, 'accept', 'b'));
            component.askDecline(component.invitations[1]);
            log.length = 0;

            await component.decline(component.invitations[1]);

            expect(http.delete).toHaveBeenCalledWith(`${URL}/a`);
            expect(component.invitations.map((one) => one.id)).toEqual(['b']);
            expect(component.confirmingId).toBeNull();
            expect(notify).toHaveBeenCalledWith('success', 'Invitation to Workspace a declined.');
            expect(heard).toEqual([1]);
            expect(log).toEqual(['accept:b']);
            expect(component.busy).toBe(false);
        });

        it('puts the cursor on the message that says none is left, and tells the header it is zero', async () => {
            http.get.mockResolvedValue({ data: [invitation('a')] });
            await component.load();
            heard.length = 0;
            http.delete.mockResolvedValue(null);

            await component.decline(component.invitations[0]);

            expect(component.empty).toBe(true);
            expect(heard).toEqual([0]);
            expect(log).toEqual(['heading']);
        });

        it('says why when it fails, and keeps the invitation', async () => {
            http.delete.mockRejectedValue(new HttpError(500, 'The server hit an error. Please try again in a moment.'));

            await component.decline(component.invitations[1]);

            expect(component.invitations).toHaveLength(2);
            expect(notify).toHaveBeenCalledWith('danger', 'The server hit an error. Please try again in a moment.');
            expect(component.busy).toBe(false);
        });

        it('sends nothing twice', async () => {
            component.busy = true;

            await component.decline(component.invitations[1]);

            expect(http.delete).not.toHaveBeenCalled();
        });
    });
});
