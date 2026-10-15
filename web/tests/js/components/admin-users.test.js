import { beforeEach, describe, expect, it, vi } from 'vitest';

import adminUsers from '../../../resources/js/components/admin-users.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key, replacements = {}) => {
        const messages = {
            'admin.users.range': ':first–:last of :total',
            'admin.users.workspace_count': 'Workspaces: :count',
            'admin.users.created_on': 'Created on :date',
            'admin.users.promote': 'Make administrator',
            'admin.users.demote': 'Remove administrator',
            'admin.users.promote_named': 'Make :name an administrator',
            'admin.users.demote_named': 'Remove :name from the administrators',
            'admin.users.delete_named': 'Delete the account of :name',
            'admin.users.confirm_delete': 'Delete :name for good?',
            'admin.users.notice.created': 'Created',
            'admin.users.notice.promoted': 'Promoted',
            'admin.users.notice.demoted': 'Demoted',
            'admin.users.notice.deleted': 'Deleted',
            'admin.users.error.account_exists': 'Already there',
            'admin.users.error.last_administrator': 'Keep one administrator',
            'admin.users.error.self_deletion': 'Not yourself',
        };

        return Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(':' + name, value),
            messages[key] ?? key,
        );
    },
}));

const URL = '/api/v1/admin/users';

/** An account as `AdminUserResource` describes it. */
function account(id, overrides = {}) {
    return {
        id,
        name: 'Person ' + id,
        email: `person${id}@example.com`,
        locale: 'fr',
        is_admin: false,
        is_self: false,
        workspaces_count: 1,
        created_at: '2026-10-15T10:00:00+00:00',
        ...overrides,
    };
}

/** What `GET /api/v1/admin/users` answers for a page. */
function page(
    items,
    { current = 1, last = 1, total = items.length, from = items.length ? 1 : null, to = items.length || null } = {},
) {
    return { data: items, meta: { current_page: current, last_page: last, total, from, to } };
}

describe('administration users page', () => {
    let component;

    beforeEach(() => {
        component = adminUsers();
        // Alpine injects $root: the element carrying x-data and the data-* attributes.
        component.$root = { dataset: { url: URL } };
        document.documentElement.lang = 'en';
    });

    describe('reading the list', () => {
        it('reads the first page when the page opens', async () => {
            http.get.mockResolvedValue(
                page([account(2), account(1, { is_self: true, is_admin: true })], { total: 2, to: 2 }),
            );

            await component.init();

            expect(http.get).toHaveBeenCalledWith(URL, { params: { q: '', page: 1 } });
            expect(component.items.map((item) => item.id)).toEqual([2, 1]);
            expect(component.total).toBe(2);
            expect(component.rangeLabel).toBe('1–2 of 2');
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);
        });

        it('says so when nothing matches', async () => {
            http.get.mockResolvedValue(page([], { total: 0 }));

            await component.init();

            expect(component.empty).toBe(true);
            expect(component.rangeLabel).toBe('');
        });

        it('searches the typed word, trimmed, from the first page', async () => {
            http.get.mockResolvedValue(page([account(3)], { current: 1, last: 3, total: 60, from: 1, to: 25 }));
            component.query = '  ada ';
            component.page = 2;
            component.confirmingId = 3;

            await component.search();

            expect(http.get).toHaveBeenCalledWith(URL, { params: { q: 'ada', page: 1 } });
            expect(component.activeQuery).toBe('ada');
            expect(component.confirmingId).toBeNull();
        });

        it('pages through the results of the search that is active', async () => {
            http.get.mockResolvedValue(page([account(3)], { current: 1, last: 3, total: 60, from: 1, to: 25 }));
            component.query = 'ada';
            await component.search();

            expect(component.hasPrevious).toBe(false);
            expect(component.hasNext).toBe(true);

            http.get.mockResolvedValue(page([account(4)], { current: 2, last: 3, total: 60, from: 26, to: 50 }));
            await component.next();

            expect(http.get).toHaveBeenLastCalledWith(URL, { params: { q: 'ada', page: 2 } });
            expect(component.page).toBe(2);
            expect(component.hasPrevious).toBe(true);

            http.get.mockResolvedValue(page([account(3)], { current: 1, last: 3, total: 60, from: 1, to: 25 }));
            await component.previous();

            expect(http.get).toHaveBeenLastCalledWith(URL, { params: { q: 'ada', page: 1 } });
        });

        it('does not go before the first page nor after the last', async () => {
            http.get.mockResolvedValue(page([account(1)]));
            await component.init();
            http.get.mockClear();

            await component.previous();
            await component.next();

            expect(http.get).not.toHaveBeenCalled();
        });

        it('shows the last page when the one that was shown is past the end now', async () => {
            http.get
                .mockResolvedValueOnce(page([], { current: 3, last: 2, total: 26, from: null, to: null }))
                .mockResolvedValueOnce(page([account(1)], { current: 2, last: 2, total: 26, from: 26, to: 26 }));

            await component.load(3);

            expect(http.get).toHaveBeenNthCalledWith(2, URL, { params: { q: '', page: 2 } });
            expect(component.page).toBe(2);
            expect(component.items.map((item) => item.id)).toEqual([1]);
            expect(component.loading).toBe(false);
        });

        it('drops the answer of a page that was asked for before a newer one', async () => {
            let answerSlow;
            http.get.mockReturnValueOnce(new Promise((resolve) => (answerSlow = resolve)));
            http.get.mockResolvedValueOnce(page([account(2)]));

            const slow = component.load(1);
            await component.load(1);
            answerSlow(page([account(1)]));
            await slow;

            expect(component.items.map((item) => item.id)).toEqual([2]);
            expect(component.loading).toBe(false);
        });

        it('tells why the list could not be read, unless the HTTP helper already did', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server down'));
            await component.load(1);

            expect(notify).toHaveBeenCalledWith('danger', 'Server down');
            expect(component.loading).toBe(false);

            notify.mockClear();
            const reported = new HttpError(0, 'Network');
            reported.reported = true;
            http.get.mockRejectedValueOnce(reported);
            await component.load(1);

            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('showing an account', () => {
        it('labels the actions with the name of the account', () => {
            const ada = account(5, { name: 'Ada', is_admin: false });
            const grace = account(6, { name: 'Grace', is_admin: true });

            expect(component.toggleText(ada)).toBe('Make administrator');
            expect(component.toggleLabel(ada)).toBe('Make Ada an administrator');
            expect(component.toggleText(grace)).toBe('Remove administrator');
            expect(component.toggleLabel(grace)).toBe('Remove Grace from the administrators');
            expect(component.deleteLabel(ada)).toBe('Delete the account of Ada');
            expect(component.confirmText(ada)).toBe('Delete Ada for good?');
            expect(component.workspacesLabel(account(7, { workspaces_count: 3 }))).toBe('Workspaces: 3');
        });

        it('writes the creation day in the language of the page, and nothing for a date it cannot read', () => {
            expect(component.createdLabel(account(1))).toMatch(/^Created on .*2026/);
            expect(component.createdLabel(account(1, { created_at: null }))).toBe('');
            expect(component.createdLabel(account(1, { created_at: 'yesterday' }))).toBe('');
        });

        it('knows the administrator who is looking: their row has no actions', () => {
            expect(component.isSelf(account(1, { is_self: true }))).toBe(true);
            expect(component.isSelf(account(2))).toBe(false);
        });
    });

    describe('creating an account', () => {
        beforeEach(() => {
            http.get.mockResolvedValue(page([account(9)]));
        });

        it('needs an address and a name', () => {
            expect(component.canCreate).toBe(false);

            component.newEmail = 'ada@example.com';
            expect(component.canCreate).toBe(false);

            component.newName = '  ';
            expect(component.canCreate).toBe(false);

            component.newName = 'Ada';
            expect(component.canCreate).toBe(true);

            component.busy = true;
            expect(component.canCreate).toBe(false);
        });

        it('sends them trimmed, says it worked, empties the form and shows the list from the top', async () => {
            http.post.mockResolvedValue({ data: account(9) });
            component.newEmail = ' ada@example.com ';
            component.newName = ' Ada ';
            component.query = 'old search';
            component.activeQuery = 'old search';

            await component.create();

            expect(http.post).toHaveBeenCalledWith(URL, { email: 'ada@example.com', name: 'Ada' });
            expect(notify).toHaveBeenCalledWith('success', 'Created');
            expect(component.newEmail).toBe('');
            expect(component.newName).toBe('');
            expect(component.query).toBe('');
            expect(http.get).toHaveBeenCalledWith(URL, { params: { q: '', page: 1 } });
            expect(component.busy).toBe(false);
        });

        it('ignores a second submit while one is in flight, and an incomplete form', async () => {
            await component.create();
            component.newEmail = 'ada@example.com';
            component.newName = 'Ada';
            component.busy = true;
            await component.create();

            expect(http.post).not.toHaveBeenCalled();
        });

        it('says under the form that the address already has an account, and keeps what was typed', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'account_exists' }));
            component.newEmail = 'ada@example.com';
            component.newName = 'Ada';

            await component.create();

            expect(component.formError).toBe('Already there');
            expect(component.newEmail).toBe('ada@example.com');
            expect(component.busy).toBe(false);
            expect(notify).not.toHaveBeenCalled();
            expect(http.get).not.toHaveBeenCalled();
        });

        it("shows the server's validation message under the form", async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid', { errors: { email: ['The email must be a valid email address.'] } }),
            );
            component.newEmail = 'ada';
            component.newName = 'Ada';

            await component.create();

            expect(component.formError).toBe('The email must be a valid email address.');
        });

        it('clears the previous refusal when it tries again', async () => {
            http.post.mockResolvedValue({ data: account(9) });
            component.formError = 'Already there';
            component.newEmail = 'ada@example.com';
            component.newName = 'Ada';

            await component.create();

            expect(component.formError).toBe('');
        });

        it('toasts a failure the HTTP helper has not reported', async () => {
            http.post.mockRejectedValue(new HttpError(500, 'Server down'));
            component.newEmail = 'ada@example.com';
            component.newName = 'Ada';

            await component.create();

            expect(notify).toHaveBeenCalledWith('danger', 'Server down');
        });
    });

    describe('making an administrator, or not any more', () => {
        it('promotes an account and shows the answer in place', async () => {
            const ada = account(5);
            component.items = [account(4), ada];
            http.patch.mockResolvedValue({ data: { ...ada, is_admin: true } });

            await component.toggleAdmin(ada);

            expect(http.patch).toHaveBeenCalledWith(URL + '/5', { is_admin: true });
            expect(component.items.map((item) => item.is_admin)).toEqual([false, true]);
            expect(notify).toHaveBeenCalledWith('success', 'Promoted');
            expect(component.busy).toBe(false);
        });

        it('demotes an administrator', async () => {
            const grace = account(6, { is_admin: true });
            component.items = [grace];
            http.patch.mockResolvedValue({ data: { ...grace, is_admin: false } });

            await component.toggleAdmin(grace);

            expect(http.patch).toHaveBeenCalledWith(URL + '/6', { is_admin: false });
            expect(component.items[0].is_admin).toBe(false);
            expect(notify).toHaveBeenCalledWith('success', 'Demoted');
        });

        it('says why the last administrator stays one, and changes nothing', async () => {
            const grace = account(6, { is_admin: true });
            component.items = [grace];
            http.patch.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'last_administrator' }));

            await component.toggleAdmin(grace);

            expect(notify).toHaveBeenCalledWith('danger', 'Keep one administrator');
            expect(component.items[0].is_admin).toBe(true);
            expect(component.busy).toBe(false);
        });

        it('shows the list as the server holds it when the account is gone', async () => {
            const ada = account(5);
            component.items = [ada];
            const gone = new HttpError(404, 'Gone');
            gone.reported = true;
            http.patch.mockRejectedValue(gone);
            http.get.mockResolvedValue(page([]));

            await component.toggleAdmin(ada);

            expect(http.get).toHaveBeenCalledWith(URL, { params: { q: '', page: 1 } });
            expect(component.items).toEqual([]);
            // The helper had said so already.
            expect(notify).not.toHaveBeenCalled();
        });

        it('ignores a click while another change is in flight', async () => {
            component.busy = true;

            await component.toggleAdmin(account(5));

            expect(http.patch).not.toHaveBeenCalled();
        });
    });

    describe('deleting an account', () => {
        it('asks for the confirmation in the row of the account, and can be given up', () => {
            const ada = account(5);

            component.askDelete(ada);

            expect(component.isConfirming(ada)).toBe(true);
            expect(component.isConfirming(account(6))).toBe(false);

            component.cancelDelete();

            expect(component.isConfirming(ada)).toBe(false);
        });

        it('deletes it once confirmed, says so and shows the page again', async () => {
            const ada = account(5);
            component.page = 2;
            component.confirmingId = 5;
            http.delete.mockResolvedValue(null);
            http.get.mockResolvedValue(page([account(1)], { current: 2, last: 2, total: 26, from: 26, to: 26 }));

            await component.confirmDelete(ada);

            expect(http.delete).toHaveBeenCalledWith(URL + '/5');
            expect(notify).toHaveBeenCalledWith('success', 'Deleted');
            expect(component.confirmingId).toBeNull();
            expect(http.get).toHaveBeenCalledWith(URL, { params: { q: '', page: 2 } });
            expect(component.busy).toBe(false);
        });

        it('keeps the confirmation open and says why when the deletion is refused', async () => {
            component.confirmingId = 5;
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'self_deletion' }));

            await component.confirmDelete(account(5));

            expect(notify).toHaveBeenCalledWith('danger', 'Not yourself');
            expect(component.confirmingId).toBe(5);
            expect(http.get).not.toHaveBeenCalled();
            expect(component.busy).toBe(false);
        });

        it('says that the last administrator cannot go', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'last_administrator' }));

            await component.confirmDelete(account(5, { is_admin: true }));

            expect(notify).toHaveBeenCalledWith('danger', 'Keep one administrator');
        });

        it('ignores a second click while the deletion is in flight', async () => {
            component.busy = true;

            await component.confirmDelete(account(5));

            expect(http.delete).not.toHaveBeenCalled();
        });
    });
});
