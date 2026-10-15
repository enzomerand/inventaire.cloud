/**
 * The workspaces and account pages under the real Alpine CSP build.
 *
 * `alpine-csp.test.js` scans the templates for syntax the CSP build refuses; this file goes further and runs them:
 * the Blade source of the switcher, of the workspaces page and of the account page is turned into plain HTML (the few
 * constructs they use are supported, anything else fails loudly so this helper is extended on purpose), mounted with
 * the real components, and driven the way a person would. Only the network (`http`), the toasts and the navigation
 * are replaced.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return {
        ...original,
        http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
        scheduleNavigation: vi.fn(),
    };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn(), saveFile: vi.fn() }));

const VIEWS = join(import.meta.dirname, '../../../resources/views');

/** Where the routes the templates mention lead. */
const ROUTES = {
    'api.v1.me.current-workspace': '/api/v1/me/current-workspace',
    'api.v1.workspaces.index': '/api/v1/workspaces',
    'api.v1.me.update': '/api/v1/me',
    'api.v1.me.sign-out-everywhere': '/api/v1/me/sign-out-everywhere',
    'api.v1.me.export': '/api/v1/me/export',
    'api.v1.me.deletion-code': '/api/v1/me/deletion-code',
    'api.v1.me.destroy': '/api/v1/me',
    home: '/',
    'app.workspaces': '/app/workspaces',
};

/** The workspaces of the person the templates are rendered for. */
const WORKSPACES = [
    { id: 'personal', name: 'Espace personnel', role: 'owner', current: false },
    { id: 'shop', name: 'Shop', role: 'owner', current: true },
    { id: 'shared', name: 'Shared', role: 'viewer', current: false },
];

const MESSAGES = {
    'error.too_many_requests': 'Slow down',
    'login.error.throttled': 'Wait :seconds seconds',
    'login.error.throttled_minutes': 'Wait :minutes minutes',
    'workspaces.role.owner': 'Owner',
    'workspaces.role.editor': 'Editor',
    'workspaces.role.viewer': 'Viewer',
    'workspaces.rename_named': 'Rename :name',
    'workspaces.delete_named': 'Delete :name',
    'workspaces.confirm_delete': 'Delete :name and everything in it?',
    'workspaces.notice.created': 'Workspace created.',
    'workspaces.notice.renamed': 'Workspace renamed.',
    'workspaces.notice.deleted': 'Workspace deleted.',
    'workspaces.error.last': 'Keep one workspace',
    'account.notice.saved': 'Account updated',
    'account.notice.signed_out_everywhere': 'Signed out everywhere else',
    'account.notice.code_sent': 'Code sent',
    'account.notice.code_resent': 'New code sent',
    'account.error.last_administrator': 'Last administrator',
    'account.error.sole_owner': 'Hand your workspaces over',
};

/** Escapes text for an HTML attribute, like Blade's `{{ }}`. */
function escape(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;');
}

/**
 * Turns the Blade source of a template into plain HTML. Only the constructs the three templates use are supported.
 *
 * @param {string} path Template, relative to resources/views.
 * @returns {string}
 */
function render(path) {
    let html = readFileSync(join(VIEWS, path), 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/@extends\([^)]*\)/g, '')
        .replace(/@section\('(?:title|heading)'[^\n]*\)\n/g, '');

    // The content section is the page; the layout around it is not under test.
    html = html.replace(/@section\('content'\)([\s\S]*)@endsection/, '$1');

    // The language select lists two languages.
    html = html.replace(/@foreach \(\$locales as \$code => \$name\)([\s\S]*?)@endforeach/g, (_match, body) =>
        [
            ['fr', 'Français'],
            ['en', 'English'],
        ]
            .map(([code, name]) => body.replaceAll('{{ $code }}', code).replaceAll('{{ $name }}', name))
            .join(''),
    );
    html = html.replace(/@selected\([^)]*\)/g, '');

    // Icons.
    html = html.replace(/<x-tabler-[\w-]+([^>]*?)\s*\/>/g, '<svg$1></svg>');

    // Echoes.
    html = html.replace(/\{\{\s*([\s\S]+?)\s*\}\}/g, (_match, expression) => {
        let found;

        if ((found = expression.match(/^__\('((?:[^'\\]|\\.)*)'(?:,\s*\[[^\]]*\])?\)$/))) {
            return escape(found[1].replace(':minutes', '10'));
        }

        if ((found = expression.match(/^route\('([\w.-]+)'(?:,\s*absolute:\s*false)?\)(#\w+)?$/))) {
            return ROUTES[found[1]] ?? fail(`unknown route ${found[1]}`);
        }

        if (expression.startsWith('json_encode($list')) {
            return escape(JSON.stringify(WORKSPACES));
        }

        const values = {
            $current: 'Shop',
            '$user?->name': 'Ada Lovelace',
            '$user?->email': 'ada@example.com',
            $accountLocale: 'fr',
            "config('auth.account_deletion.lifetime_minutes')": '10',
        };

        return values[expression] ?? fail(`unsupported echo {{ ${expression} }}`);
    });

    expect(html, 'unsupported Blade syntax left in the template').not.toMatch(/@\w+\(|\{\{|\{!!|@end\w+|<x-/);

    return html;
}

/** Fails the rendering with a message that says what to teach the helper. */
function fail(message) {
    throw new Error(`pages.test.js cannot render the template: ${message}`);
}

/** What `GET /api/v1/workspaces` answers for a list of interface items. */
function listing(items) {
    return { data: items.map((item) => ({ id: item.id, name: item.name, role: item.role, is_current: item.current })) };
}

describe('the workspaces and account pages under the real Alpine CSP build', () => {
    let Alpine;

    beforeAll(async () => {
        ({ default: Alpine } = await import('@alpinejs/csp'));
        const registrations = {
            accountData: '../../../resources/js/components/account-data.js',
            accountDeletion: '../../../resources/js/components/account-deletion.js',
            accountProfile: '../../../resources/js/components/account-profile.js',
            accountSecurity: '../../../resources/js/components/account-security.js',
            workspaceSwitcher: '../../../resources/js/components/workspace-switcher.js',
            workspaces: '../../../resources/js/components/workspaces.js',
        };

        for (const [name, path] of Object.entries(registrations)) {
            Alpine.data(name, (await import(/* @vite-ignore */ path)).default);
        }

        const { createWorkspacesStore } = await import('../../../resources/js/stores/workspaces.js');
        Alpine.store('workspaces', createWorkspacesStore());
        window.Alpine = Alpine;
        Alpine.start();
    });

    afterAll(() => {
        Alpine.stopObservingMutations();
    });

    /** Waits for the next animation frame: Alpine applies what a click or an input changed within one or two of them. */
    const frame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()));

    /** Waits for Alpine to flush its DOM updates and for pending promises. */
    async function flush() {
        await Alpine.nextTick();
        await frame();
        await frame();
    }

    /** Mounts templates the way a page has them, and waits for Alpine to start them. */
    async function mount(...paths) {
        document.documentElement.lang = 'en';
        document.body.innerHTML =
            `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>` +
            paths.map((path) => render(path)).join('');
        await flush();
    }

    /** Whether nothing between an element and the page hides it. */
    function visible(element) {
        for (let node = element; node && node !== document.body; node = node.parentElement) {
            if (node.style.display === 'none') {
                return false;
            }
        }

        return true;
    }

    /** The visible buttons, links and submit buttons whose text or accessible name is the given one. */
    function buttons(label, root = document.body) {
        return [...root.querySelectorAll('button')].filter(
            (button) =>
                visible(button) && (button.textContent.trim() === label || button.getAttribute('aria-label') === label),
        );
    }

    /** Clicks the one visible button of that name. */
    async function press(label, root = document.body) {
        const found = buttons(label, root);

        expect(found, `the visible "${label}" button`).toHaveLength(1);
        found[0].click();
        await flush();
    }

    /** Types into an input the way a person does. */
    async function type(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flush();
    }

    /** The rows of the workspaces list. */
    const rows = () => [...document.querySelectorAll('.list-group-item')];

    beforeEach(() => {
        Alpine.store('workspaces').replace([]);
        window.location.hash = '';
    });

    describe('the switcher of the header', () => {
        beforeEach(async () => {
            await mount('components/workspace-switcher.blade.php');
        });

        it('lists the workspaces, highlights the current one and names it in the button', () => {
            const items = [...document.querySelectorAll('.dropdown-menu button')];

            expect(items.map((item) => item.textContent.trim())).toEqual(['Espace personnel', 'Shop', 'Shared']);
            expect(items.map((item) => item.classList.contains('active'))).toEqual([false, true, false]);
            expect(items.map((item) => item.getAttribute('aria-current'))).toEqual([null, 'true', null]);
            expect(document.querySelector('.dropdown-toggle .app-workspace-name').textContent).toBe('Shop');
        });

        it('links to the creation form and to the management page', () => {
            const links = [...document.querySelectorAll('.dropdown-menu a')].map((link) => [
                link.textContent.trim(),
                link.getAttribute('href'),
            ]);

            expect(links).toEqual([
                ['New workspace', '/app/workspaces#create'],
                ['Manage workspaces', '/app/workspaces'],
            ]);
        });

        it('switches to the workspace that is clicked, then reloads', async () => {
            http.put.mockResolvedValue({ data: {} });

            await press('Shared', document.querySelector('.dropdown-menu'));

            expect(http.put).toHaveBeenCalledWith('/api/v1/me/current-workspace', { workspace: 'shared' });
            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('does nothing when the current workspace is clicked', async () => {
            await press('Shop', document.querySelector('.dropdown-menu'));

            expect(http.put).not.toHaveBeenCalled();
        });
    });

    describe('the workspaces page', () => {
        beforeEach(async () => {
            await mount('components/workspace-switcher.blade.php', 'app/workspaces.blade.php');
        });

        it('lists the workspaces with their role, the current one marked', () => {
            expect(rows().map((row) => row.querySelector('.fw-bold').textContent)).toEqual([
                'Espace personnel',
                'Shop',
                'Shared',
            ]);
            expect(
                rows().map((row) =>
                    [...row.querySelectorAll('.badge')].filter(visible).map((badge) => badge.textContent.trim()),
                ),
            ).toEqual([['Owner'], ['Current', 'Owner'], ['Viewer']]);
        });

        it('offers to rename and delete the workspaces that are owned, and to work in the ones that are not current', () => {
            const actions = rows().map((row) => ({
                work: buttons('Work in this workspace', row).length,
                rename: buttons(`Rename ${row.querySelector('.fw-bold').textContent}`, row).length,
                delete: buttons(`Delete ${row.querySelector('.fw-bold').textContent}`, row).length,
            }));

            expect(actions).toEqual([
                { work: 1, rename: 1, delete: 1 },
                { work: 0, rename: 1, delete: 1 },
                { work: 1, rename: 0, delete: 0 },
            ]);
        });

        it('switches the workspace to work in, then reloads', async () => {
            http.put.mockResolvedValue({ data: {} });

            await press('Work in this workspace', rows()[2]);

            expect(http.put).toHaveBeenCalledWith('/api/v1/me/current-workspace', { workspace: 'shared' });
            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('creates a workspace, which then shows on the page and in the header', async () => {
            const created = { id: 'new', name: 'Warehouse', role: 'owner', current: true };
            http.post.mockResolvedValue({ data: {} });
            http.get.mockResolvedValue(listing([...WORKSPACES.map((item) => ({ ...item, current: false })), created]));
            const form = document.querySelector('#create form');
            const submit = form.querySelector('button[type="submit"]');

            expect(submit.disabled).toBe(true);

            await type(form.querySelector('input'), '  Warehouse ');
            expect(submit.disabled).toBe(false);

            form.dispatchEvent(new Event('submit', { cancelable: true }));
            await flush();

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces', { name: 'Warehouse' });
            expect(notify).toHaveBeenCalledWith('success', 'Workspace created.');
            expect(rows().map((row) => row.querySelector('.fw-bold').textContent)).toContain('Warehouse');
            expect(
                [...document.querySelectorAll('.dropdown-menu button')].map((item) => item.textContent.trim()),
            ).toContain('Warehouse');
            expect(document.querySelector('.dropdown-toggle .app-workspace-name').textContent).toBe('Warehouse');
            expect(form.querySelector('input').value).toBe('');
            expect(submit.disabled).toBe(true);
        });

        it('renames a workspace in place, and the header follows', async () => {
            http.patch.mockResolvedValue({ data: {} });
            http.get.mockResolvedValue(
                listing(WORKSPACES.map((item) => (item.id === 'shop' ? { ...item, name: 'Home' } : item))),
            );
            const row = rows()[1];

            await press('Rename Shop', row);

            const form = row.querySelector('form');
            expect(visible(form)).toBe(true);
            expect(form.querySelector('input').value).toBe('Shop');
            expect(visible(row.querySelector('.fw-bold'))).toBe(false);

            await type(form.querySelector('input'), 'Home');
            form.dispatchEvent(new Event('submit', { cancelable: true }));
            await flush();

            expect(http.patch).toHaveBeenCalledWith('/api/v1/workspaces/shop', { name: 'Home' });
            expect(rows()[1].querySelector('.fw-bold').textContent).toBe('Home');
            expect(visible(rows()[1].querySelector('form'))).toBe(false);
            expect(document.querySelector('.dropdown-toggle .app-workspace-name').textContent).toBe('Home');
        });

        it('gives up a rename', async () => {
            const row = rows()[1];

            await press('Rename Shop', row);
            await press('Cancel', row);

            expect(visible(row.querySelector('form'))).toBe(false);
            expect(visible(row.querySelector('.fw-bold'))).toBe(true);
            expect(http.patch).not.toHaveBeenCalled();
        });

        it('asks for a confirmation before deleting, then deletes', async () => {
            http.delete.mockResolvedValue(null);
            http.get.mockResolvedValue(
                listing(
                    WORKSPACES.filter((item) => item.id !== 'shop').map((item) => ({
                        ...item,
                        current: item.id === 'personal',
                    })),
                ),
            );
            const row = rows()[1];

            await press('Delete Shop', row);

            expect(http.delete).not.toHaveBeenCalled();
            expect(row.textContent).toContain('Delete Shop and everything in it?');

            await press('Delete', row);

            expect(http.delete).toHaveBeenCalledWith('/api/v1/workspaces/shop');
            expect(rows().map((item) => item.querySelector('.fw-bold').textContent)).toEqual([
                'Espace personnel',
                'Shared',
            ]);
            expect(document.querySelector('.dropdown-toggle .app-workspace-name').textContent).toBe('Espace personnel');
        });

        it('says why the last workspace cannot be deleted, and keeps the confirmation', async () => {
            http.delete.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'last_workspace' }));
            const row = rows()[1];

            await press('Delete Shop', row);
            await press('Delete', row);

            expect(notify).toHaveBeenCalledWith('danger', 'Keep one workspace');
            expect(rows()).toHaveLength(3);
        });

        it('focuses the creation box when opened from "New workspace"', async () => {
            window.location.hash = '#create';
            await mount('components/workspace-switcher.blade.php', 'app/workspaces.blade.php');

            expect(document.activeElement).toBe(document.querySelector('#workspace-new-name'));
        });
    });

    describe('the account page', () => {
        beforeEach(async () => {
            await mount('app/account.blade.php');
        });

        describe('the profile', () => {
            it('shows the name and the language of the account, and the address read-only', () => {
                expect(document.querySelector('#account-name').value).toBe('Ada Lovelace');
                expect(document.querySelector('#account-locale').value).toBe('fr');
                expect(document.querySelector('#account-email').readOnly).toBe(true);
            });

            it('saves the name and the language', async () => {
                http.patch.mockResolvedValue({ data: {} });
                const form = document.querySelector('#account-name').closest('form');

                await type(document.querySelector('#account-name'), 'Ada Byron');
                document.querySelector('#account-locale').value = 'en';
                document.querySelector('#account-locale').dispatchEvent(new Event('change', { bubbles: true }));
                form.dispatchEvent(new Event('submit', { cancelable: true }));
                await flush();

                expect(http.patch).toHaveBeenCalledWith('/api/v1/me', { name: 'Ada Byron', locale: 'en' });
                expect(notify).toHaveBeenCalledWith('success', 'Account updated');
                expect(scheduleNavigation).toHaveBeenCalledWith(reloadPage);
            });

            it('cannot be saved without a name', async () => {
                await type(document.querySelector('#account-name'), '   ');

                expect(
                    document.querySelector('#account-name').closest('form').querySelector('button[type="submit"]')
                        .disabled,
                ).toBe(true);
            });
        });

        describe('signing out everywhere', () => {
            it('asks for a confirmation, then signs the other devices out', async () => {
                http.post.mockResolvedValue(null);
                const card = document.querySelector('[data-url="/api/v1/me/sign-out-everywhere"]');

                await press('Sign out everywhere', card);
                expect(http.post).not.toHaveBeenCalled();
                expect(card.textContent).toContain('Sign out of all your other devices?');

                await press('Sign out everywhere', card);

                expect(http.post).toHaveBeenCalledWith('/api/v1/me/sign-out-everywhere');
                expect(notify).toHaveBeenCalledWith('success', 'Signed out everywhere else');
            });
        });

        describe('the data', () => {
            it('downloads the export', async () => {
                const { saveFile } = await import('../../../resources/js/lib/page.js');
                const file = new Blob(['{}']);
                http.get.mockResolvedValue(file);

                await press('Download my data');

                expect(http.get).toHaveBeenCalledWith('/api/v1/me/export', { responseType: 'blob' });
                expect(saveFile).toHaveBeenCalledWith(
                    file,
                    expect.stringMatching(/^inventaire-export-\d{4}-\d{2}-\d{2}\.json$/),
                );
            });
        });

        describe('deleting the account', () => {
            const card = () => document.querySelector('[data-code-url]');

            it('asks for a code, then needs the whole code and the box ticked', async () => {
                http.post.mockResolvedValue({ data: { expires_in: 600 } });

                await press('Delete my account…');

                expect(http.post).toHaveBeenCalledWith('/api/v1/me/deletion-code', undefined, { silent: true });
                const form = card().querySelector('form');
                expect(visible(form)).toBe(true);
                const submit = form.querySelector('button[type="submit"]');
                expect(submit.disabled).toBe(true);

                await type(card().querySelector('#account-deletion-code'), '123 456');
                expect(submit.disabled).toBe(true);

                const box = form.querySelector('input[type="checkbox"]');
                box.checked = true;
                box.dispatchEvent(new Event('change', { bubbles: true }));
                await flush();
                expect(submit.disabled).toBe(false);
            });

            it('deletes the account with the code, then leaves for the home page', async () => {
                http.post.mockResolvedValue({ data: {} });
                http.delete.mockResolvedValue(null);
                await press('Delete my account…');
                const form = card().querySelector('form');

                await type(card().querySelector('#account-deletion-code'), '123456');
                const box = form.querySelector('input[type="checkbox"]');
                box.checked = true;
                box.dispatchEvent(new Event('change', { bubbles: true }));
                await flush();
                form.dispatchEvent(new Event('submit', { cancelable: true }));
                await flush();

                expect(http.delete).toHaveBeenCalledWith('/api/v1/me', { silent: true, data: { code: '123456' } });
                expect(redirectTo).toHaveBeenCalledWith('/');
            });

            it('shows the message of a wrong code in the alert of the card', async () => {
                http.post.mockResolvedValue({ data: {} });
                http.delete.mockRejectedValue(
                    new HttpError(422, 'Invalid', {
                        code: 'deletion_code_wrong',
                        errors: { code: ['Incorrect code. 4 attempts left.'] },
                    }),
                );
                await press('Delete my account…');
                const form = card().querySelector('form');
                await type(card().querySelector('#account-deletion-code'), '123456');
                const box = form.querySelector('input[type="checkbox"]');
                box.checked = true;
                box.dispatchEvent(new Event('change', { bubbles: true }));
                await flush();

                form.dispatchEvent(new Event('submit', { cancelable: true }));
                await flush();

                const alert = card().querySelector('.alert');
                expect(visible(alert)).toBe(true);
                expect(alert.textContent).toContain('Incorrect code. 4 attempts left.');
                expect(redirectTo).not.toHaveBeenCalled();
            });

            it('lists the workspaces to hand over when the account cannot be deleted yet', async () => {
                http.post.mockRejectedValue(
                    new HttpError(409, 'Conflict', {
                        code: 'sole_owner_of_shared_workspaces',
                        body: {
                            workspaces: [
                                { id: 'a', name: 'Team' },
                                { id: 'b', name: 'Shared <b>' },
                            ],
                        },
                    }),
                );

                await press('Delete my account…');

                const alert = card().querySelector('.alert');
                expect(visible(alert)).toBe(true);
                expect(alert.textContent).toContain('Hand your workspaces over');
                expect([...alert.querySelectorAll('li')].filter(visible).map((item) => item.textContent)).toEqual([
                    'Team',
                    'Shared <b>',
                ]);
                expect(alert.querySelector('b')).toBeNull();
                expect(visible(card().querySelector('form'))).toBe(false);
            });

            it('can be given up', async () => {
                http.post.mockResolvedValue({ data: {} });
                await press('Delete my account…');

                await press('Cancel', card());

                expect(visible(card().querySelector('form'))).toBe(false);
                expect(buttons('Delete my account…')).toHaveLength(1);
            });
        });
    });
});
