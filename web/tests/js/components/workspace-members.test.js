import { beforeEach, describe, expect, it, vi } from 'vitest';

import workspaceMembers from '../../../resources/js/components/workspace-members.js';
import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return {
        ...original,
        http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
        scheduleNavigation: vi.fn(),
    };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));
vi.mock('../../../resources/js/grid/rows.js', () => ({ momentText: (value) => (value ? `<${value}>` : '') }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const URL = '/api/v1/workspaces/shop/members';
const TRANSFER_URL = '/api/v1/workspaces/shop/transfer';

/** A member as the API describes them. */
function member(id, overrides = {}) {
    return {
        id,
        name: `Member ${id}`,
        email: null,
        role: 'editor',
        joined_at: '2026-10-01T10:00:00+00:00',
        is_self: false,
        ...overrides,
    };
}

/** The members of a workspace, the owner first. */
function team() {
    return [
        member('m-owner', { name: 'Olga', role: 'owner', email: 'olga@example.test' }),
        member('m-ed'),
        member('m-view', { role: 'viewer' }),
    ];
}

/** A `change` event of a select that now shows a role. */
function chosen(role) {
    return { target: { value: role } };
}

/** An element that remembers it was focused. */
function focusable(log, name) {
    return { focus: () => log.push(name) };
}

describe('the members page of a workspace', () => {
    let component;
    let log;
    let rowButtons;

    /** Mounts the component for a person who is, or is not, the owner. */
    function mount({ owner = true } = {}) {
        log = [];
        rowButtons = [];
        component = workspaceMembers();
        // Alpine injects these: the root element (with the data-* attributes and the elements the cursor goes to), $nextTick.
        component.$root = {
            dataset: {
                url: URL,
                transferUrl: TRANSFER_URL,
                homeUrl: '/app',
                workspaceName: 'Shop',
                isOwner: owner ? '1' : '0',
            },
            querySelector: (selector) => {
                const [, name] = selector.match(/data-focus="(\w+)"/);

                return focusable(log, name);
            },
            querySelectorAll: (selector) => {
                const [, action] = selector.match(/data-member-action="(\w+)"/);

                return rowButtons.filter((button) => button.dataset.memberAction === action);
            },
        };
        component.$nextTick = (callback) => callback();
        component.rootElement = component.$root;
    }

    beforeEach(() => {
        vi.resetAllMocks();
        mount();
    });

    describe('the list', () => {
        it('is read when the page opens, in the order the API gives it', async () => {
            http.get.mockResolvedValue({ data: team() });
            expect(component.loading).toBe(true);

            await component.init();

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.members.map((one) => one.id)).toEqual(['m-owner', 'm-ed', 'm-view']);
            expect(component.loading).toBe(false);
            expect(component.loadFailed).toBe(false);
        });

        it('says it could not be read, and reads it again on demand', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server error'));
            await component.init();

            expect(component.loadFailed).toBe(true);
            expect(component.loading).toBe(false);

            http.get.mockResolvedValue({ data: team() });
            await component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.members).toHaveLength(3);
        });

        it('stays quiet when the request was cancelled', async () => {
            http.get.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.init();

            expect(component.loadFailed).toBe(false);
            expect(component.loading).toBe(false);
        });

        it('says everything about a member in words', () => {
            const [owner, editor] = team();

            expect(component.roleLabel(owner)).toBe('Owner');
            expect(component.roleLabel(editor)).toBe('Editor');
            expect(component.roleLabel(member('x', { role: 'viewer' }))).toBe('Viewer');
            expect(new Set(['owner', 'editor', 'viewer'].map((role) => component.roleClass({ role }))).size).toBe(3);
            expect(component.roleClass({ role: 'something else' })).toBe('bg-secondary-lt');
            expect(component.joinedText(editor)).toBe('Joined <2026-10-01T10:00:00+00:00>');
            expect(component.joinedText(member('y', { joined_at: null }))).toBe('');
            expect(component.roleSelectLabel(editor)).toBe('Role of Member m-ed');
            expect(component.transferLabel(editor)).toBe('Make owner: Member m-ed');
            expect(component.removeLabel(editor)).toBe('Remove Member m-ed from the workspace');
            expect(component.roleSelectId(editor)).toBe('member-role-m-ed');
        });
    });

    describe('what the person is', () => {
        it('lets the owner act on every member but the owner', () => {
            const [owner, editor, viewer] = team();

            expect([owner, editor, viewer].map((one) => component.canManage(one))).toEqual([false, true, true]);
            expect(component.isOwner).toBe(true);
        });

        it('lets another member act on nobody', () => {
            mount({ owner: false });
            const [owner, editor] = team();

            expect(component.isOwner).toBe(false);
            expect(component.canManage(owner)).toBe(false);
            expect(component.canManage(editor)).toBe(false);
        });

        it('knows whether somebody else belongs to the workspace, for the owner who cannot leave it', async () => {
            http.get.mockResolvedValue({ data: [team()[0]] });
            await component.init();
            expect(component.hasOthers).toBe(false);

            http.get.mockResolvedValue({ data: team() });
            await component.load();
            expect(component.hasOthers).toBe(true);
        });

        it('lets a member who is not the owner leave once the list has told which one they are, and not while something is in flight', async () => {
            mount({ owner: false });
            expect(component.canLeave).toBe(false);

            http.get.mockResolvedValue({ data: [team()[0], member('m-ed', { is_self: true })] });
            await component.init();
            expect(component.canLeave).toBe(true);

            component.busy = true;
            expect(component.canLeave).toBe(false);
        });

        it('never lets the owner leave', async () => {
            http.get.mockResolvedValue({ data: [member('m-owner', { role: 'owner', is_self: true })] });
            await component.init();

            expect(component.canLeave).toBe(false);
        });
    });

    describe('changing a role', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: team() });
            await component.init();
        });

        it('sends the role that was chosen, shows the member as the API answers and tells the owner', async () => {
            http.patch.mockResolvedValue({ data: member('m-ed', { role: 'viewer' }) });
            const select = chosen('viewer');

            await component.changeRole(component.members[1], select);

            expect(http.patch).toHaveBeenCalledWith(`${URL}/m-ed`, { role: 'viewer' });
            expect(component.members[1].role).toBe('viewer');
            expect(component.members.map((one) => one.id)).toEqual(['m-owner', 'm-ed', 'm-view']);
            expect(notify).toHaveBeenCalledWith('success', 'The role of Member m-ed was updated.');
            expect(component.busy).toBe(false);
        });

        it('encodes the identifier of the member in the address', async () => {
            http.patch.mockResolvedValue({ data: member('a/b', { role: 'viewer' }) });

            await component.changeRole(member('a/b'), chosen('viewer'));

            expect(http.patch.mock.calls[0][0]).toBe(`${URL}/a%2Fb`);
        });

        it('sends nothing when the role is the one the member has, and puts the select back', async () => {
            const select = chosen('editor');

            await component.changeRole(component.members[1], select);

            expect(http.patch).not.toHaveBeenCalled();
            expect(select.target.value).toBe('editor');
        });

        it('sends nothing while another change is in flight, and puts the select back', async () => {
            component.busy = true;
            const select = chosen('viewer');

            await component.changeRole(component.members[1], select);

            expect(http.patch).not.toHaveBeenCalled();
            expect(select.target.value).toBe('editor');
        });

        it('puts the select back and says why when the rules refuse (the member is the owner now)', async () => {
            http.patch.mockRejectedValue(
                new HttpError(409, 'Something went wrong.', { code: 'cannot_change_owner_role', body: {} }),
            );
            http.get.mockClear();
            const select = chosen('viewer');

            await component.changeRole(component.members[1], select);

            expect(select.target.value).toBe('editor');
            expect(component.members[1].role).toBe('editor');
            expect(notify).toHaveBeenCalledWith(
                'danger',
                'The role of the owner cannot be changed: hand the ownership over to another member instead.',
            );
            // The list was out of date: it is read again.
            expect(http.get).toHaveBeenCalledTimes(1);
            expect(component.busy).toBe(false);
        });

        it('does not repeat a failure the HTTP helper already reported', async () => {
            const failure = new HttpError(0, 'Network error', { kind: 'network' });
            failure.reported = true;
            http.patch.mockRejectedValue(failure);
            const select = chosen('viewer');

            await component.changeRole(component.members[1], select);

            expect(select.target.value).toBe('editor');
            expect(notify).not.toHaveBeenCalled();
        });

        it('reloads the page when the person is not the owner any more', async () => {
            http.patch.mockRejectedValue(new HttpError(403, 'Forbidden', { code: 'not_owner', body: {} }));

            await component.changeRole(component.members[1], chosen('viewer'));

            expect(notify).toHaveBeenCalledWith('danger', 'Only an owner of this workspace can manage it.');
            expect(scheduleNavigation).toHaveBeenCalledWith(reloadPage);
        });

        it('stays quiet when the request was cancelled', async () => {
            http.patch.mockRejectedValue(new DOMException('Aborted', 'AbortError'));
            const select = chosen('viewer');

            await component.changeRole(component.members[1], select);

            expect(select.target.value).toBe('editor');
            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });
    });

    describe('removing a member', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: team() });
            await component.init();
        });

        it('asks first, in the row, and puts the cursor on the button that confirms', () => {
            const editor = component.members[1];
            rowButtons.push({
                dataset: { memberAction: 'confirm', memberId: 'm-ed' },
                focus: () => log.push('confirm:m-ed'),
            });

            component.askRemove(editor);

            expect(component.isConfirming(editor)).toBe(true);
            expect(component.isConfirming(component.members[2])).toBe(false);
            expect(component.confirmText(editor)).toBe(
                'Remove Member m-ed from the workspace? They lose access at once.',
            );
            expect(component.confirmLabel).toBe('Remove');
            expect(component.confirmClass).toBe('btn-danger');
            expect(log).toEqual(['confirm:m-ed']);
        });

        it('can be given up, and the cursor goes back to the button that asked', () => {
            rowButtons.push({
                dataset: { memberAction: 'remove', memberId: 'm-ed' },
                focus: () => log.push('remove:m-ed'),
            });
            component.askRemove(component.members[1]);
            log.length = 0;

            component.cancelConfirm();

            expect(component.isConfirming(component.members[1])).toBe(false);
            expect(log).toEqual(['remove:m-ed']);
        });

        it('removes the member, tells the owner and puts the cursor on the heading', async () => {
            http.delete.mockResolvedValue(null);
            component.askRemove(component.members[1]);
            log.length = 0;

            await component.confirm(component.members[1]);

            expect(http.delete).toHaveBeenCalledWith(`${URL}/m-ed`);
            expect(component.members.map((one) => one.id)).toEqual(['m-owner', 'm-view']);
            expect(component.confirmingId).toBeNull();
            expect(notify).toHaveBeenCalledWith('success', 'Member m-ed was removed from the workspace.');
            expect(log).toEqual(['heading']);
            expect(component.busy).toBe(false);
        });

        it('takes a member who is gone already off the list without a word of its own', async () => {
            const gone = new HttpError(404, 'This item no longer exists.');
            gone.reported = true;
            http.delete.mockRejectedValue(gone);

            await component.remove(component.members[1]);

            expect(component.members.map((one) => one.id)).toEqual(['m-owner', 'm-view']);
            expect(notify).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('says why when the rules refuse, and reads the list again', async () => {
            http.delete.mockRejectedValue(
                new HttpError(409, 'Something went wrong.', { code: 'cannot_remove_owner', body: {} }),
            );
            http.get.mockClear();
            component.askRemove(component.members[1]);

            await component.remove(component.members[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'The owner cannot be removed from the workspace: hand the ownership over to another member first.',
            );
            expect(component.members).toHaveLength(3);
            expect(component.confirmingId).toBeNull();
            expect(http.get).toHaveBeenCalledTimes(1);
        });

        it('sends nothing twice', async () => {
            component.busy = true;

            await component.remove(component.members[1]);

            expect(http.delete).not.toHaveBeenCalled();
        });
    });

    describe('handing the ownership over', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: team() });
            await component.init();
        });

        it('asks first, with a warning rather than a danger', () => {
            component.askTransfer(component.members[1]);

            expect(component.isConfirming(component.members[1])).toBe(true);
            expect(component.confirmText(component.members[1])).toBe(
                'Make Member m-ed the owner? You become an editor, and only they can give the ownership back.',
            );
            expect(component.confirmLabel).toBe('Make owner');
            expect(component.confirmClass).toBe('btn-warning');
        });

        it('sends the identifier of the member, tells the owner and reloads the page once the notice has been read', async () => {
            http.post.mockResolvedValue({ data: { id: 'shop', role: 'editor' } });
            component.askTransfer(component.members[1]);

            await component.confirm(component.members[1]);

            expect(http.post).toHaveBeenCalledWith(TRANSFER_URL, { user_id: 'm-ed' });
            expect(notify).toHaveBeenCalledWith(
                'success',
                'Member m-ed is now the owner of the workspace. The page is about to reload.',
            );
            expect(scheduleNavigation).toHaveBeenCalledTimes(1);
            expect(reloadPage).not.toHaveBeenCalled();

            scheduleNavigation.mock.calls[0][0]();
            expect(reloadPage).toHaveBeenCalledTimes(1);
            // The controls stay disabled until the page is reloaded.
            expect(component.busy).toBe(true);
        });

        it('says why when the rules refuse, and frees the controls', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'Something went wrong.', { code: 'already_owner', body: {} }),
            );
            http.get.mockClear();
            component.askTransfer(component.members[1]);

            await component.transfer(component.members[1]);

            expect(notify).toHaveBeenCalledWith('danger', 'This member is already the owner of the workspace.');
            expect(scheduleNavigation).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
            expect(component.confirmingId).toBeNull();
            expect(http.get).toHaveBeenCalledTimes(1);
        });

        it('sends nothing twice', async () => {
            component.busy = true;

            await component.transfer(component.members[1]);

            expect(http.post).not.toHaveBeenCalled();
        });
    });

    describe('leaving', () => {
        beforeEach(async () => {
            mount({ owner: false });
            http.get.mockResolvedValue({
                data: [team()[0], member('m-ed', { is_self: true, email: 'me@example.test' })],
            });
            await component.init();
        });

        it('asks first, and moves the cursor between the two buttons', () => {
            component.askLeave();

            expect(component.confirmingLeave).toBe(true);
            expect(component.leaveConfirmText).toBe(
                'Leave Shop? You lose access to its inventories until somebody invites you again.',
            );
            expect(log).toEqual(['confirmLeave']);

            component.cancelLeave();

            expect(component.confirmingLeave).toBe(false);
            expect(log).toEqual(['confirmLeave', 'leave']);
        });

        it('removes the person from the workspace they are in, tells them and goes back to their space', async () => {
            http.delete.mockResolvedValue(null);

            await component.leave();

            expect(http.delete).toHaveBeenCalledWith(`${URL}/m-ed`);
            expect(notify).toHaveBeenCalledWith('success', 'You left Shop.');
            expect(redirectTo).not.toHaveBeenCalled();

            scheduleNavigation.mock.calls[0][0]();
            expect(redirectTo).toHaveBeenCalledWith('/app');
            expect(component.busy).toBe(true);
        });

        it('sends nothing before the list has told which member the person is', async () => {
            mount({ owner: false });

            await component.leave();

            expect(http.delete).not.toHaveBeenCalled();
        });

        it('says why when the rules refuse (the person became the owner in the meantime), and frees the controls', async () => {
            http.delete.mockRejectedValue(
                new HttpError(409, 'Something went wrong.', { code: 'owner_cannot_leave', body: {} }),
            );
            component.askLeave();

            await component.leave();

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'The owner cannot leave the workspace: hand the ownership over to another member first.',
            );
            expect(component.busy).toBe(false);
            expect(component.confirmingLeave).toBe(false);
            expect(scheduleNavigation).not.toHaveBeenCalled();
        });

        it('goes back to the space of the person when they are not a member any more', async () => {
            http.delete.mockRejectedValue(new HttpError(404, 'This item no longer exists.'));

            await component.leave();

            scheduleNavigation.mock.calls[0][0]();
            expect(redirectTo).toHaveBeenCalledWith('/app');
        });
    });
});
