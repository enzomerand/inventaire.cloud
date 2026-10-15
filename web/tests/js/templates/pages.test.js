/**
 * The workspaces, account, administration and inventory pages under the real Alpine CSP build.
 *
 * `alpine-csp.test.js` scans the templates for syntax the CSP build refuses; this file goes further and runs them:
 * the Blade source of the switcher, of the workspaces page, of the account page, of the administration's users page and
 * of the pages of a workspace, of an inventory and of its fields is turned into plain HTML (the few constructs they use
 * are supported, anything else fails loudly so this helper is extended on purpose), mounted with the real components,
 * and driven the way a person would. Only the network (`http`), the toasts, the dialogs (Bootstrap's modal needs a real
 * browser) and the navigation are replaced.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { hideModal, showModal } from '../../../resources/js/lib/modal.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';
import { MESSAGES as INVENTORY_MESSAGES } from '../support/messages.js';

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
vi.mock('../../../resources/js/lib/modal.js', () => ({
    showModal: vi.fn(),
    hideModal: vi.fn(),
    onModalHidden: vi.fn(),
}));

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
    'api.v1.admin.users.index': '/api/v1/admin/users',
    'api.v1.admin.settings.show': '/api/v1/admin/settings',
    home: '/',
    'app.workspaces': '/app/workspaces',
};

/** Routes with parameters: `{name}` stands for a parameter the template gives (a literal, or a variable of PAGE). */
const ROUTE_PATTERNS = {
    'app.workspace': '/app/w/{workspace}',
    'app.inventory': '/app/w/{workspace}/inventories/{inventory}',
    'app.inventory.fields': '/app/w/{workspace}/inventories/{inventory}/fields',
    'api.v1.workspaces.inventories.index': '/api/v1/workspaces/{workspace}/inventories',
    'api.v1.inventories.show': '/api/v1/inventories/{inventory}',
    'api.v1.inventories.items.index': '/api/v1/inventories/{inventory}/items',
    'api.v1.inventories.items.show': '/api/v1/inventories/{inventory}/items/{item}',
    'api.v1.inventories.fields.store': '/api/v1/inventories/{inventory}/fields',
    'api.v1.inventories.fields.order': '/api/v1/inventories/{inventory}/fields/order',
    'api.v1.inventories.fields.update': '/api/v1/inventories/{inventory}/fields/{field}',
};

/** What the controllers of the pages hand to the templates. */
const PAGE = {
    workspace: { ulid: 'shop', name: 'Shop' },
    inventory: { ulid: 'tools', name: 'Tools', description: 'Hand tools' },
    types: [
        { type: 'text', label: 'Text' },
        { type: 'number', label: 'Number' },
        { type: 'money', label: 'Amount' },
        { type: 'percentage', label: 'Percentage' },
        { type: 'select', label: 'Choice' },
        { type: 'rating', label: 'Rating' },
        { type: 'barcode', label: 'Barcode' },
    ],
    currencies: [
        { code: 'EUR', name: 'Euro' },
        { code: 'USD', name: 'US Dollar' },
    ],
    abilities: {},
};

/** What an editor of the workspace may do; a test narrows it down by setting `PAGE.abilities`. */
const EDITOR = { view: true, create: true, update: true, delete: false, manage_fields: true, edit_items: true };

/** The workspaces of the person the templates are rendered for. */
const WORKSPACES = [
    { id: 'personal', name: 'Espace personnel', role: 'owner', current: false },
    { id: 'shop', name: 'Shop', role: 'owner', current: true },
    { id: 'shared', name: 'Shared', role: 'viewer', current: false },
];

const MESSAGES = {
    ...INVENTORY_MESSAGES,
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
    'login.notice.code_resent': 'New code sent',
    'account.error.last_administrator': 'Last administrator',
    'account.error.sole_owner': 'Hand your workspaces over',
    'admin.settings.notice.opened': 'Sign-ups are open',
    'admin.settings.notice.closed': 'Sign-ups are closed',
    'admin.users.range': ':first–:last of :total',
    'admin.users.workspace_count': 'Workspaces: :count',
    'admin.users.created_on': 'Created on :date',
    'admin.users.promote': 'Make administrator',
    'admin.users.demote': 'Remove administrator',
    'admin.users.promote_named': 'Make :name an administrator',
    'admin.users.demote_named': 'Remove :name from the administrators',
    'admin.users.delete_named': 'Delete the account of :name',
    'admin.users.confirm_delete': 'Delete the account of :name for good?',
    'admin.users.notice.created': 'Account created',
    'admin.users.notice.promoted': 'Promoted',
    'admin.users.notice.demoted': 'Demoted',
    'admin.users.notice.deleted': 'Account deleted',
    'admin.users.error.account_exists': 'Already an account',
    'admin.users.error.last_administrator': 'Keep one administrator',
    'admin.users.error.self_deletion': 'Not your own account',
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
        // The way a value is shown is shared by the table and the details of an item.
        .replace(/@include\('partials\.field-value'\)/g, () => render('partials/field-value.blade.php'))
        // The tabs of the administration are not under test.
        .replace(/@include\([^\n]*\)\n/g, '')
        .replace(/@inject\([^\n]*\)\n/g, '')
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

        if (
            (found = expression.match(/^route\('([\w.-]+)'(?:,\s*\[([^\]]*)\])?(?:,\s*absolute:\s*false)?\)(#\w+)?$/))
        ) {
            return route(found[1], found[2]) + (found[3] ?? '');
        }

        if ((found = expression.match(/^\$abilities\['(\w+)'\] \? '1' : '0'$/))) {
            return abilitiesOfPage()[found[1]] ? '1' : '0';
        }

        if (expression === 'json_encode($types, JSON_THROW_ON_ERROR)') {
            return escape(JSON.stringify(PAGE.types));
        }

        if (expression === 'json_encode($currencies, JSON_THROW_ON_ERROR)') {
            return escape(JSON.stringify(PAGE.currencies));
        }

        if (expression.startsWith('json_encode($list')) {
            return escape(JSON.stringify(WORKSPACES));
        }

        const values = {
            "$settings->registrationOpen() ? '1' : '0'": '1',
            $current: 'Shop',
            '$workspace->name': PAGE.workspace.name,
            '$inventory->name': PAGE.inventory.name,
            '$inventory->description': PAGE.inventory.description,
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

/** What the person of the page may do: an editor unless the test says otherwise. */
function abilitiesOfPage() {
    return { ...EDITOR, ...PAGE.abilities };
}

/**
 * The path of a route, with the parameters the template gives it.
 *
 * @param {string} name
 * @param {string | undefined} parameters What is between the brackets: `'workspace' => $workspace->ulid, 'inventory' => '__id__'`.
 * @returns {string}
 */
function route(name, parameters) {
    if (!(name in ROUTE_PATTERNS)) {
        return ROUTES[name] ?? fail(`unknown route ${name}`);
    }

    const variables = {
        '$workspace->ulid': PAGE.workspace.ulid,
        '$inventory->ulid': PAGE.inventory.ulid,
    };
    let path = ROUTE_PATTERNS[name];

    for (const [, key, value] of (parameters ?? '').matchAll(/'(\w+)'\s*=>\s*('[^']*'|\$[\w>-]+)/g)) {
        const resolved = value.startsWith("'")
            ? value.slice(1, -1)
            : (variables[value] ?? fail(`unknown variable ${value}`));

        path = path.replace(`{${key}}`, resolved);
    }

    return path.includes('{') ? fail(`route ${name} lacks a parameter: ${path}`) : path;
}

/** Fails the rendering with a message that says what to teach the helper. */
function fail(message) {
    throw new Error(`pages.test.js cannot render the template: ${message}`);
}

/** What `GET /api/v1/workspaces` answers for a list of interface items. */
function listing(items) {
    return { data: items.map((item) => ({ id: item.id, name: item.name, role: item.role, is_current: item.current })) };
}

describe('the pages under the real Alpine CSP build', () => {
    let Alpine;

    beforeAll(async () => {
        ({ default: Alpine } = await import('@alpinejs/csp'));
        const registrations = {
            accountData: '../../../resources/js/components/account-data.js',
            accountDeletion: '../../../resources/js/components/account-deletion.js',
            accountProfile: '../../../resources/js/components/account-profile.js',
            accountSecurity: '../../../resources/js/components/account-security.js',
            adminSettings: '../../../resources/js/components/admin-settings.js',
            adminUsers: '../../../resources/js/components/admin-users.js',
            inventoryFields: '../../../resources/js/components/inventory-fields.js',
            inventoryHeader: '../../../resources/js/components/inventory-header.js',
            inventoryItems: '../../../resources/js/components/inventory-items.js',
            workspaceInventories: '../../../resources/js/components/workspace-inventories.js',
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

    /** Whether nothing between an element and the page hides it (`x-show` sets a style, `:hidden` the attribute). */
    function visible(element) {
        for (let node = element; node && node !== document.body; node = node.parentElement) {
            if (node.style.display === 'none' || node.hidden) {
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
        PAGE.abilities = {};
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

        it('switches to the workspace that is clicked, then goes to its page', async () => {
            http.put.mockResolvedValue({ data: {} });

            await press('Shared', document.querySelector('.dropdown-menu'));

            expect(http.put).toHaveBeenCalledWith('/api/v1/me/current-workspace', { workspace: 'shared' });
            expect(redirectTo).toHaveBeenCalledWith('/app/w/shared');
            expect(reloadPage).not.toHaveBeenCalled();
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

    describe('the administration users page', () => {
        const ADA = {
            id: 2,
            name: 'Ada Lovelace',
            email: 'ada@example.com',
            is_admin: false,
            is_self: false,
            workspaces_count: 2,
            created_at: '2026-10-14T09:00:00+00:00',
        };
        const GRACE = {
            ...ADA,
            id: 3,
            name: 'Grace Hopper',
            email: 'grace@example.com',
            is_admin: true,
            workspaces_count: 1,
        };
        const ME = { ...ADA, id: 1, name: 'Jane Admin', email: 'jane@example.com', is_admin: true, is_self: true };

        /** What `GET /api/v1/admin/users` answers for one page. */
        const answer = (items, meta = {}) => ({
            data: items,
            meta: {
                current_page: 1,
                last_page: 1,
                total: items.length,
                from: items.length ? 1 : null,
                to: items.length || null,
                ...meta,
            },
        });

        /** The accounts the list shows, one element each. */
        const accounts = () =>
            [...document.querySelectorAll('.list-group-item')].filter((item) => item.querySelector('.fw-bold'));

        /** The names of the accounts the list shows. */
        const names = () => accounts().map((item) => item.querySelector('.fw-bold').textContent);

        beforeEach(async () => {
            http.get.mockResolvedValue(answer([ADA, GRACE, ME]));
            await mount('app/admin/users.blade.php');
        });

        it('reads the first page when it opens, and lists the accounts with what an administrator needs to know', () => {
            expect(http.get).toHaveBeenCalledWith('/api/v1/admin/users', { params: { q: '', page: 1 } });
            expect(names()).toEqual(['Ada Lovelace', 'Grace Hopper', 'Jane Admin']);
            expect(
                accounts().map((item) =>
                    [...item.querySelectorAll('.badge')].filter(visible).map((badge) => badge.textContent.trim()),
                ),
            ).toEqual([[], ['Administrator'], ['Administrator', 'You']]);
            expect(accounts()[0].textContent).toContain('ada@example.com');
            expect(accounts()[0].textContent).toContain('Workspaces: 2');
            expect(accounts()[0].textContent).toMatch(/Created on .*2026/);
            expect(document.body.textContent).toContain('1–3 of 3');
            // The spinner and the empty message are hidden.
            expect([...document.querySelectorAll('.list-group-item')].filter(visible)).toHaveLength(3);
        });

        it('offers actions on every account but the one of the administrator who is looking', () => {
            const actions = accounts().map((item) => ({
                toggle: buttons('Make administrator', item).length + buttons('Remove administrator', item).length,
                delete: [...item.querySelectorAll('button')].filter(
                    (button) =>
                        visible(button) && button.getAttribute('aria-label')?.startsWith('Delete the account of'),
                ).length,
            }));

            expect(actions).toEqual([
                { toggle: 1, delete: 1 },
                { toggle: 1, delete: 1 },
                { toggle: 0, delete: 0 },
            ]);
            expect(buttons('Make administrator', accounts()[0])).toHaveLength(1);
            expect(buttons('Remove administrator', accounts()[1])).toHaveLength(1);
        });

        it('makes an account an administrator, in place', async () => {
            http.patch.mockResolvedValue({ data: { ...ADA, is_admin: true } });

            await press('Make administrator', accounts()[0]);

            expect(http.patch).toHaveBeenCalledWith('/api/v1/admin/users/2', { is_admin: true });
            expect(notify).toHaveBeenCalledWith('success', 'Promoted');
            expect(
                [...accounts()[0].querySelectorAll('.badge')].filter(visible).map((badge) => badge.textContent.trim()),
            ).toEqual(['Administrator']);
            expect(buttons('Remove administrator', accounts()[0])).toHaveLength(1);
        });

        it('says why the last administrator stays one', async () => {
            http.patch.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'last_administrator' }));

            await press('Remove administrator', accounts()[1]);

            expect(notify).toHaveBeenCalledWith('danger', 'Keep one administrator');
            expect(buttons('Remove administrator', accounts()[1])).toHaveLength(1);
        });

        it('asks for a confirmation in the row before deleting, which can be given up', async () => {
            await press('Delete the account of Ada Lovelace', accounts()[0]);

            expect(accounts()[0].textContent).toContain('Delete the account of Ada Lovelace for good?');
            expect(buttons('Delete', accounts()[0])).toHaveLength(1);
            expect(buttons('Make administrator', accounts()[0])).toHaveLength(0);
            // Only that row asks.
            expect(buttons('Delete', accounts()[1])).toHaveLength(0);

            await press('Cancel', accounts()[0]);

            expect(buttons('Delete', accounts()[0])).toHaveLength(0);
            expect(buttons('Make administrator', accounts()[0])).toHaveLength(1);
            expect(http.delete).not.toHaveBeenCalled();
        });

        it('deletes the account once confirmed, then shows the list as the server holds it', async () => {
            http.delete.mockResolvedValue(null);
            http.get.mockResolvedValue(answer([GRACE, ME]));

            await press('Delete the account of Ada Lovelace', accounts()[0]);
            await press('Delete', accounts()[0]);

            expect(http.delete).toHaveBeenCalledWith('/api/v1/admin/users/2');
            expect(notify).toHaveBeenCalledWith('success', 'Account deleted');
            expect(names()).toEqual(['Grace Hopper', 'Jane Admin']);
        });

        it('searches the typed word and pages through the results', async () => {
            http.get.mockResolvedValue(answer([ADA], { current_page: 1, last_page: 2, total: 30, from: 1, to: 25 }));
            const search = document.querySelector('form[role="search"]');

            await type(search.querySelector('input'), ' ada ');
            search.dispatchEvent(new Event('submit', { cancelable: true }));
            await flush();

            expect(http.get).toHaveBeenLastCalledWith('/api/v1/admin/users', { params: { q: 'ada', page: 1 } });
            expect(document.body.textContent).toContain('1–25 of 30');
            expect(buttons('Previous')[0].disabled).toBe(true);
            expect(buttons('Next')[0].disabled).toBe(false);

            http.get.mockResolvedValue(answer([GRACE], { current_page: 2, last_page: 2, total: 30, from: 26, to: 30 }));
            await press('Next');

            expect(http.get).toHaveBeenLastCalledWith('/api/v1/admin/users', { params: { q: 'ada', page: 2 } });
            expect(names()).toEqual(['Grace Hopper']);
            expect(buttons('Next')[0].disabled).toBe(true);
            expect(buttons('Previous')[0].disabled).toBe(false);
        });

        it('says so when nothing matches', async () => {
            http.get.mockResolvedValue(answer([]));
            const search = document.querySelector('form[role="search"]');

            await type(search.querySelector('input'), 'nobody');
            search.dispatchEvent(new Event('submit', { cancelable: true }));
            await flush();

            expect(names()).toEqual([]);
            expect(document.body.textContent).toContain('No account found.');
        });

        describe('the creation form', () => {
            const form = () => document.querySelector('form.card-body');
            const fields = () => form().querySelectorAll('input');

            it('needs an address and a name', async () => {
                const submit = form().querySelector('button[type="submit"]');

                expect(submit.disabled).toBe(true);

                await type(fields()[0], 'new@example.com');
                expect(submit.disabled).toBe(true);

                await type(fields()[1], 'New Person');
                expect(submit.disabled).toBe(false);
            });

            it('creates the account, empties the form and shows the list from the top', async () => {
                http.post.mockResolvedValue({ data: {} });
                http.get.mockResolvedValue(answer([{ ...ADA, id: 9, name: 'New Person' }, ADA, GRACE, ME]));

                await type(fields()[0], ' new@example.com ');
                await type(fields()[1], 'New Person');
                form().dispatchEvent(new Event('submit', { cancelable: true }));
                await flush();

                expect(http.post).toHaveBeenCalledWith('/api/v1/admin/users', {
                    email: 'new@example.com',
                    name: 'New Person',
                });
                expect(notify).toHaveBeenCalledWith('success', 'Account created');
                expect(fields()[0].value).toBe('');
                expect(fields()[1].value).toBe('');
                expect(names()[0]).toBe('New Person');
            });

            it('says under the form that the address already has an account', async () => {
                http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'account_exists' }));

                await type(fields()[0], 'ada@example.com');
                await type(fields()[1], 'Ada');
                form().dispatchEvent(new Event('submit', { cancelable: true }));
                await flush();

                const alert = form().querySelector('.alert');
                expect(visible(alert)).toBe(true);
                expect(alert.textContent).toBe('Already an account');
                expect(fields()[0].value).toBe('ada@example.com');
            });

            it('shows no refusal until there is one', () => {
                expect(visible(form().querySelector('.alert'))).toBe(false);
            });
        });
    });

    describe('the administration settings page', () => {
        /** The switch of the registration setting. */
        const registrationSwitch = () => document.querySelector('input[type="checkbox"]');

        /** Flips the switch the way a person does. */
        async function flip(checked) {
            registrationSwitch().checked = checked;
            registrationSwitch().dispatchEvent(new Event('change', { bubbles: true }));
            await flush();
        }

        beforeEach(async () => {
            await mount('app/admin/settings.blade.php');
        });

        it('shows the switch on, as the page was rendered', () => {
            expect(registrationSwitch().checked).toBe(true);
            expect(registrationSwitch().disabled).toBe(false);
            expect(document.body.textContent).toContain('Anybody can create an account');
        });

        it('closes registration when the switch is turned off, and says so', async () => {
            http.put.mockResolvedValue({ data: { registration_open: false } });

            await flip(false);

            expect(http.put).toHaveBeenCalledWith('/api/v1/admin/settings', { registration_open: false });
            expect(notify).toHaveBeenCalledWith('success', 'Sign-ups are closed');
            expect(registrationSwitch().checked).toBe(false);
            expect(registrationSwitch().disabled).toBe(false);
        });

        it('turns the switch back on when the change could not be saved', async () => {
            http.put.mockRejectedValue(new HttpError(500, 'Server down'));

            await flip(false);

            expect(notify).toHaveBeenCalledWith('danger', 'Server down');
            expect(registrationSwitch().checked).toBe(true);
        });
    });

    /** Picks an option of a <select> the way a person does. */
    async function choose(select, value) {
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
    }

    /** Submits a form the way Enter or a click on its submit button does. */
    async function submit(form) {
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
    }

    /** Ticks or unticks a check box the way a person does. */
    async function tick(box, checked) {
        box.checked = checked;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
    }

    describe('the page of a workspace', () => {
        const INVENTORIES = [
            { id: 'tools', name: 'Tools', description: 'Hand tools', fields_count: 3, items_count: 12 },
            { id: 'books', name: 'Books', description: null, fields_count: 1, items_count: 1 },
        ];

        /** Opens the page, the workspace holding these inventories. */
        async function open(inventories = INVENTORIES) {
            http.get.mockResolvedValue({ data: inventories });
            await mount('app/workspace.blade.php');
        }

        const dialog = () => document.querySelector('#inventory-create-title').closest('.modal');

        it('lists the inventories as cards, each linking to its page, with what it holds', async () => {
            await open();

            expect(http.get).toHaveBeenCalledWith('/api/v1/workspaces/shop/inventories');

            const cards = [...document.querySelectorAll('a.card')];

            expect(cards.map((card) => card.getAttribute('href'))).toEqual([
                '/app/w/shop/inventories/tools',
                '/app/w/shop/inventories/books',
            ]);
            expect(cards.map((card) => card.querySelector('.card-title').textContent)).toEqual(['Tools', 'Books']);
            expect(cards.map((card) => card.querySelector('.small').textContent)).toEqual([
                '12 items · 3 fields',
                '1 item · 1 field',
            ]);
            expect(visible(cards[0].querySelector('p'))).toBe(true);
            expect(cards[0].querySelector('p').textContent).toBe('Hand tools');
            expect(visible(cards[1].querySelector('p'))).toBe(false);
        });

        it('shows names and descriptions as text, never as markup', async () => {
            await open([
                {
                    id: 'x',
                    name: '<img src=x onerror=alert(1)>',
                    description: '<b>bold</b>',
                    fields_count: 0,
                    items_count: 0,
                },
            ]);

            expect(document.querySelector('a.card img')).toBeNull();
            expect(document.querySelector('a.card b')).toBeNull();
            expect(document.querySelector('a.card .card-title').textContent).toBe('<img src=x onerror=alert(1)>');
        });

        it('explains an empty workspace, and offers to add the first inventory', async () => {
            await open([]);

            const empty = document.querySelector('.empty');

            expect(visible(empty)).toBe(true);
            expect(empty.textContent).toContain('No inventory yet');
            expect(document.querySelectorAll('a.card')).toHaveLength(0);
            expect(buttons('New inventory', empty)).toHaveLength(1);
        });

        it('does not claim the workspace is empty while it is reading the list', async () => {
            http.get.mockReturnValue(new Promise(() => {}));
            await mount('app/workspace.blade.php');

            expect(visible(document.querySelector('.empty'))).toBe(false);
            expect(visible(document.querySelector('[role="status"]'))).toBe(true);
        });

        it('offers to add an inventory to the people who may', async () => {
            await open();

            expect(buttons('New inventory')).toHaveLength(1);
        });

        it('offers nothing of the kind to a viewer, who is told the workspace is empty all the same', async () => {
            PAGE.abilities = { create: false };
            await open([]);

            expect(buttons('New inventory')).toHaveLength(0);
            expect(visible(document.querySelector('.empty'))).toBe(true);
        });

        it('adds an inventory from the dialog, then goes to its page', async () => {
            await open();
            http.post.mockResolvedValue({ data: { id: 'new', name: 'Garden', description: null } });

            await press('New inventory');

            expect(showModal).toHaveBeenCalledWith(dialog());

            const form = dialog().querySelector('form');
            const create = form.querySelector('button[type="submit"]');

            expect(create.disabled).toBe(true);

            await type(form.querySelector('#inventory-create-name'), ' Garden ');
            await type(form.querySelector('#inventory-create-description'), 'Plants and tools');

            expect(create.disabled).toBe(false);

            await submit(form);

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/inventories', {
                name: 'Garden',
                description: 'Plants and tools',
            });
            expect(hideModal).toHaveBeenCalledWith(dialog());
            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop/inventories/new');
        });

        it('shows the refusal under the input it is about', async () => {
            await open();
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', { errors: { name: ['The name is too long.'] } }),
            );
            await press('New inventory');
            const form = dialog().querySelector('form');

            await type(form.querySelector('#inventory-create-name'), 'x');
            await submit(form);

            expect(form.querySelector('#inventory-create-name').classList.contains('is-invalid')).toBe(true);
            expect(form.querySelector('#inventory-create-name + .invalid-feedback').textContent).toBe(
                'The name is too long.',
            );
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('says the quota is reached', async () => {
            await open();
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'inventory_limit_reached', body: { limit: 100 } }),
            );
            await press('New inventory');
            const form = dialog().querySelector('form');

            await type(form.querySelector('#inventory-create-name'), 'x');
            await submit(form);

            const alert = dialog().querySelector('.alert');

            expect(visible(alert)).toBe(true);
            expect(alert.textContent).toBe('Inventory limit reached (100 per workspace).');
        });
    });

    describe('the page of an inventory', () => {
        const STATE = [
            { id: 'aaaaaaaa', label: 'New', color: 'green' },
            { id: 'bbbbbbbb', label: 'Used' },
        ];
        const PARTS = [
            { id: 'cccccccc', label: 'Red' },
            { id: 'dddddddd', label: 'Blue' },
        ];
        const FIELDS = [
            { id: 'name', name: 'Name', type: 'text', options: { max_length: 120 }, required: true, position: 0 },
            {
                id: 'qty',
                name: 'Weight',
                type: 'number',
                options: { decimals: 2, unit: 'kg' },
                required: false,
                position: 1,
            },
            { id: 'price', name: 'Price', type: 'money', options: { currency: 'EUR' }, required: false, position: 2 },
            { id: 'state', name: 'State', type: 'select', options: { choices: STATE }, required: false, position: 3 },
            {
                id: 'parts',
                name: 'Parts',
                type: 'multiselect',
                options: { choices: PARTS },
                required: false,
                position: 4,
            },
            { id: 'fragile', name: 'Fragile', type: 'boolean', options: {}, required: false, position: 5 },
            { id: 'bought', name: 'Bought', type: 'date', options: {}, required: false, position: 6 },
            { id: 'site', name: 'Site', type: 'url', options: {}, required: false, position: 7 },
            { id: 'labels', name: 'Labels', type: 'tags', options: {}, required: false, position: 8 },
            { id: 'grade', name: 'Grade', type: 'rating', options: { max: 5 }, required: false, position: 9 },
            { id: 'tint', name: 'Tint', type: 'color', options: {}, required: false, position: 10 },
        ];
        const HAMMER = {
            id: 'i1',
            values: {
                name: 'Hammer',
                qty: '1.50',
                price: '19.90',
                state: 'aaaaaaaa',
                parts: ['cccccccc', 'dddddddd'],
                fragile: false,
                bought: '2026-10-15',
                site: 'https://example.com/hammer',
                labels: ['Tool'],
                grade: 4,
                tint: '#aabbcc',
            },
        };
        const TRICKY = {
            id: 'i2',
            values: { name: '<img src=x onerror="window.pwned = true">', site: 'javascript:alert(1)' },
        };

        /** What `GET /api/v1/inventories/tools/items` answers. */
        function itemsPage(items, { current = 1, last = 1, total = items.length, from = 1, to = items.length } = {}) {
            return { data: items, meta: { current_page: current, last_page: last, total, from, to } };
        }

        /** Opens the page of the inventory `tools`. */
        async function open({ fields = FIELDS, items = [HAMMER, TRICKY], meta = {}, itemsCount = items.length } = {}) {
            http.get.mockImplementation(async (url) =>
                url === '/api/v1/inventories/tools'
                    ? { data: { id: 'tools', name: 'Tools', items_count: itemsCount, fields } }
                    : itemsPage(items, meta),
            );
            await mount('app/inventory.blade.php');
        }

        const itemDialog = () => document.querySelector('#item-modal-title').closest('.modal');
        const editDialog = () => document.querySelector('#inventory-edit-title').closest('.modal');
        const deleteDialog = () => document.querySelector('#inventory-delete-title').closest('.modal');
        const bodyRows = () => [...document.querySelectorAll('tbody tr')];
        const cellsOf = (row) => [...row.querySelectorAll('td.app-cell')];
        const control = (id) => itemDialog().querySelector('#item-field-' + id);

        describe('the header', () => {
            beforeEach(async () => {
                PAGE.abilities = { delete: true };
                await open();
            });

            it('shows the name and the description, under the name of the workspace', () => {
                expect(document.querySelector('h1').textContent).toBe('Tools');
                expect(document.querySelector('h1 + p').textContent).toBe('Hand tools');
                expect(visible(document.querySelector('h1 + p'))).toBe(true);
                expect(document.querySelector('.breadcrumb a').textContent).toBe('Shop');
                expect(document.querySelector('.breadcrumb a').getAttribute('href')).toBe('/app/w/shop');
            });

            it('offers to manage the fields, to edit and to delete an owner', () => {
                const fields = [...document.querySelectorAll('a.btn')].find(
                    (link) => link.textContent.trim() === 'Fields',
                );

                expect(visible(fields)).toBe(true);
                expect(fields.getAttribute('href')).toBe('/app/w/shop/inventories/tools/fields');
                expect(buttons('Edit')).toHaveLength(1);
                expect(
                    buttons('Delete', document.querySelector('[x-data="inventoryHeader"]').firstElementChild),
                ).toHaveLength(0);
                expect(buttons('Delete').filter((button) => !button.closest('.modal'))).toHaveLength(1);
            });

            it('renames and describes the inventory from a dialog', async () => {
                http.patch.mockResolvedValue({ data: { id: 'tools', name: 'Workshop', description: null } });

                await press('Edit');

                expect(showModal).toHaveBeenCalledWith(editDialog());
                expect(editDialog().querySelector('#inventory-edit-name').value).toBe('Tools');
                expect(editDialog().querySelector('#inventory-edit-description').value).toBe('Hand tools');

                await type(editDialog().querySelector('#inventory-edit-name'), 'Workshop');
                await type(editDialog().querySelector('#inventory-edit-description'), '');
                await submit(editDialog().querySelector('form'));

                expect(http.patch).toHaveBeenCalledWith('/api/v1/inventories/tools', {
                    name: 'Workshop',
                    description: null,
                });
                expect(document.querySelector('h1').textContent).toBe('Workshop');
                expect(visible(document.querySelector('h1 + p'))).toBe(false);
                expect(document.querySelector('.breadcrumb-item.active').textContent).toBe('Workshop');
                expect(hideModal).toHaveBeenCalledWith(editDialog());
            });

            it('shows the refusal of a name under the input', async () => {
                http.patch.mockRejectedValue(
                    new HttpError(422, 'Invalid.', { errors: { name: ['The name is too long.'] } }),
                );
                await press('Edit');

                await submit(editDialog().querySelector('form'));

                expect(editDialog().querySelector('#inventory-edit-name').classList.contains('is-invalid')).toBe(true);
                expect(editDialog().querySelector('#inventory-edit-name + .invalid-feedback').textContent).toBe(
                    'The name is too long.',
                );
            });

            it('asks for a confirmation that states how many items go with the inventory, then deletes it', async () => {
                http.delete.mockResolvedValue(null);

                await press('Delete', document.querySelector('.btn-list'));

                expect(showModal).toHaveBeenCalledWith(deleteDialog());
                expect(deleteDialog().querySelector('.modal-body').textContent.trim()).toBe(
                    'Delete Tools and its 2 items? This cannot be undone.'.replace(' This cannot be undone.', ''),
                );
                expect(http.delete).not.toHaveBeenCalled();

                await press('Delete', deleteDialog());

                expect(http.delete).toHaveBeenCalledWith('/api/v1/inventories/tools');
                expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
            });
        });

        describe('what the role allows', () => {
            it('offers an editor the fields and the edition, but not the deletion', async () => {
                await open();

                const fields = [...document.querySelectorAll('a.btn')].find(
                    (link) => link.textContent.trim() === 'Fields',
                );

                expect(visible(fields)).toBe(true);
                expect(buttons('Edit')).toHaveLength(1);
                expect(buttons('Delete').filter((button) => !button.closest('.modal'))).toHaveLength(0);
            });

            it('offers a viewer nothing to change', async () => {
                PAGE.abilities = { update: false, delete: false, manage_fields: false, edit_items: false };
                await open();

                const fields = [...document.querySelectorAll('a.btn')].find(
                    (link) => link.textContent.trim() === 'Fields',
                );

                expect(visible(fields)).toBe(false);
                expect(buttons('Edit')).toHaveLength(0);
                expect(buttons('Delete').filter((button) => !button.closest('.modal'))).toHaveLength(0);
                expect(buttons('Add item')).toHaveLength(0);
            });
        });

        describe('the table', () => {
            it('has a column for each field in order, and a cell for each value', async () => {
                await open();

                expect([...document.querySelectorAll('thead th')].map((cell) => cell.textContent.trim())).toEqual([
                    ...FIELDS.map((field) => field.name),
                    'Actions',
                ]);
                expect(bodyRows()).toHaveLength(2);

                const [name, qty, price, state, parts, fragile, bought, site, labels, grade, tint] = cellsOf(
                    bodyRows()[0],
                );

                expect(name.textContent.trim()).toBe('Hammer');
                expect(qty.textContent.trim()).toBe('1.50 kg');
                expect(price.textContent.trim()).toBe('€19.90');
                expect(state.querySelector('.badge').textContent).toBe('New');
                expect(state.querySelector('.badge').className).toContain('bg-green-lt');
                expect([...parts.querySelectorAll('.badge')].map((badge) => badge.textContent)).toEqual([
                    'Red',
                    'Blue',
                ]);
                expect(fragile.querySelector('.badge').textContent).toBe('No');
                expect(bought.textContent.trim()).toBe('Oct 15, 2026');
                expect(labels.querySelector('.badge').textContent).toBe('Tool');
                expect(grade.querySelector('[role="img"]').textContent).toBe('★★★★☆');
                expect(grade.querySelector('[role="img"]').getAttribute('aria-label')).toBe('4 out of 5');
                expect(tint.querySelector('.app-swatch').style.backgroundColor).not.toBe('');
                expect(tint.textContent.trim()).toBe('#aabbcc');

                const link = site.querySelector('a');

                expect(link.getAttribute('href')).toBe('https://example.com/hammer');
                expect(link.getAttribute('rel')).toBe('noopener noreferrer nofollow');
                expect(link.getAttribute('target')).toBe('_blank');
            });

            it('shows an empty value as a dash', async () => {
                await open();

                expect(cellsOf(bodyRows()[1])[1].textContent.trim()).toBe('—');
            });

            it('shows what was typed as text, never as markup or as a link to a script', async () => {
                await open();

                const [name, , , , , , , site] = cellsOf(bodyRows()[1]);

                expect(document.querySelector('tbody img')).toBeNull();
                expect(window.pwned).toBeUndefined();
                expect(name.textContent.trim()).toBe('<img src=x onerror="window.pwned = true">');
                expect(site.querySelector('a')).toBeNull();
                expect(site.textContent.trim()).toBe('javascript:alert(1)');
                expect(document.querySelector('tbody a[href^="javascript"]')).toBeNull();
            });

            it('says which items are shown among all, and reads the next page', async () => {
                await open({ meta: { current: 1, last: 3, total: 120, from: 1, to: 50 } });

                expect(document.querySelector('.card-footer p').textContent).toBe('1–50 of 120');

                const previous = buttons('Previous')[0];
                const next = buttons('Next')[0];

                expect(previous.disabled).toBe(true);
                expect(next.disabled).toBe(false);

                await press('Next');

                expect(http.get).toHaveBeenLastCalledWith('/api/v1/inventories/tools/items', { params: { page: 2 } });
            });

            it('tells an inventory with no field to add some, with a link to the editor of the fields', async () => {
                await open({ fields: [], items: [] });

                const empty = [...document.querySelectorAll('.empty')].filter((element) => visible(element));

                expect(empty).toHaveLength(1);
                expect(empty[0].textContent).toContain('This inventory has no field yet');
                expect(empty[0].querySelector('a').getAttribute('href')).toBe('/app/w/shop/inventories/tools/fields');
                expect(visible(empty[0].querySelector('a'))).toBe(true);
                expect(visible(document.querySelector('table').parentElement)).toBe(false);
                expect(buttons('Add item')).toHaveLength(0);
            });

            it('does not send a viewer to the editor of the fields', async () => {
                PAGE.abilities = { manage_fields: false };
                await open({ fields: [], items: [] });

                const empty = [...document.querySelectorAll('.empty')].find((element) => visible(element));

                expect(visible(empty.querySelector('a'))).toBe(false);
            });

            it('tells an inventory with fields and no item to add the first one', async () => {
                await open({ items: [] });

                const empty = [...document.querySelectorAll('.empty')].filter((element) => visible(element));

                expect(empty).toHaveLength(1);
                expect(empty[0].textContent).toContain('No item yet');
                expect(buttons('Add item', empty[0])).toHaveLength(1);
            });
        });

        describe('adding an item', () => {
            beforeEach(async () => {
                await open({ items: [HAMMER] });
            });

            it('opens a form generated from the fields', async () => {
                await press('Add item');

                expect(showModal).toHaveBeenCalledWith(itemDialog());
                expect(itemDialog().querySelector('#item-modal-title').textContent).toBe('New item');
                expect(
                    [...itemDialog().querySelectorAll('.form-label')].map((label) => label.textContent.trim()),
                ).toEqual(FIELDS.map((field) => field.name));
                expect(itemDialog().querySelector('label[for="item-field-name"]').classList.contains('required')).toBe(
                    true,
                );
                expect(itemDialog().querySelector('label[for="item-field-qty"]').classList.contains('required')).toBe(
                    false,
                );

                expect(control('name').type).toBe('text');
                expect(control('name').getAttribute('maxlength')).toBe('120');
                expect(control('qty').getAttribute('inputmode')).toBe('decimal');
                expect(control('bought').type).toBe('date');
                expect(control('site').type).toBe('url');
                expect(control('tint').type).toBe('color');
                expect([...control('state').options].map((option) => option.textContent)).toEqual([
                    'Not set',
                    'New',
                    'Used',
                ]);
                expect([...control('fragile').options].map((option) => option.textContent)).toEqual([
                    'Not set',
                    'Yes',
                    'No',
                ]);
                expect(itemDialog().querySelectorAll('input[type="checkbox"]')).toHaveLength(2);
                expect(itemDialog().querySelectorAll('button[aria-label$="out of 5"]')).toHaveLength(5);
            });

            it('sends what was filled in, in the form the API takes', async () => {
                http.post.mockResolvedValue({ data: { id: 'new', values: {} } });
                await press('Add item');

                await type(control('name'), '  Saw ');
                await type(control('qty'), '2,5');
                await type(control('price'), '1 299,90');
                await choose(control('state'), 'bbbbbbbb');
                await tick(itemDialog().querySelectorAll('input[type="checkbox"]')[1], true);
                await choose(control('fragile'), 'true');
                await type(control('bought'), '2026-03-29');
                await type(control('site'), 'https://example.com/saw');
                await type(control('labels'), 'Cutting');
                control('labels').dispatchEvent(
                    new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
                );
                await flush();
                itemDialog().querySelectorAll('button[aria-label$="out of 5"]')[3].click();
                await flush();
                await type(control('tint'), '#ff0000');
                await submit(itemDialog().querySelector('form'));

                expect(http.post).toHaveBeenCalledWith('/api/v1/inventories/tools/items', {
                    values: {
                        name: 'Saw',
                        qty: '2.5',
                        price: '1299.90',
                        state: 'bbbbbbbb',
                        parts: ['dddddddd'],
                        fragile: true,
                        bought: '2026-03-29',
                        site: 'https://example.com/saw',
                        labels: ['Cutting'],
                        grade: 4,
                        tint: '#ff0000',
                    },
                });
                expect(hideModal).toHaveBeenCalledWith(itemDialog());
                expect(notify).toHaveBeenCalledWith('success', 'Item added.');
            });

            it('adds a tag with the comma too, and takes it off with its button', async () => {
                await press('Add item');

                await type(control('labels'), 'One');
                control('labels').dispatchEvent(
                    new KeyboardEvent('keydown', { key: ',', bubbles: true, cancelable: true }),
                );
                await flush();
                await type(control('labels'), 'Two');
                control('labels').dispatchEvent(new Event('blur'));
                await flush();

                const badges = () =>
                    [...itemDialog().querySelectorAll('.badge')].map((badge) => badge.textContent.trim());

                expect(badges()).toEqual(['One', 'Two']);

                itemDialog().querySelector('.badge .btn-close[aria-label="Remove the tag One"]').click();
                await flush();

                expect(badges()).toEqual(['Two']);
            });

            it('shows each refusal under the field it is about', async () => {
                http.post.mockRejectedValue(
                    new HttpError(422, 'Invalid.', {
                        errors: {
                            'values.name': ['The Name is required.'],
                            'values.qty': ['The Weight must be a number, written with a dot.'],
                        },
                    }),
                );
                await press('Add item');

                await submit(itemDialog().querySelector('form'));

                const feedback = (id) => control(id).closest('.mb-3').querySelector('.text-danger');

                expect(control('name').classList.contains('is-invalid')).toBe(true);
                expect(visible(feedback('name'))).toBe(true);
                expect(feedback('name').textContent).toBe('The Name is required.');
                expect(feedback('qty').textContent).toBe('The Weight must be a number, written with a dot.');
                expect(visible(feedback('price'))).toBe(false);
                expect(hideModal).not.toHaveBeenCalled();

                await type(control('name'), 'Saw');

                expect(control('name').classList.contains('is-invalid')).toBe(false);
                expect(visible(feedback('name'))).toBe(false);
            });
        });

        describe('changing an item', () => {
            beforeEach(async () => {
                await open({ items: [HAMMER] });
            });

            it('opens the form of a row that is clicked, with its values in the controls', async () => {
                bodyRows()[0].querySelector('td').click();
                await flush();

                expect(showModal).toHaveBeenCalledWith(itemDialog());
                expect(itemDialog().querySelector('#item-modal-title').textContent).toBe('Edit the item');
                expect(control('name').value).toBe('Hammer');
                expect(control('qty').value).toBe('1.50');
                expect(control('state').value).toBe('aaaaaaaa');
                expect(control('fragile').value).toBe('false');
                expect(control('bought').value).toBe('2026-10-15');
                expect(control('tint').value).toBe('#aabbcc');
                expect([...itemDialog().querySelectorAll('input[type="checkbox"]')].map((box) => box.checked)).toEqual([
                    true,
                    true,
                ]);
                expect(itemDialog().querySelector('.badge').textContent.trim()).toBe('Tool');
                expect(
                    [...itemDialog().querySelectorAll('button[aria-label$="out of 5"]')].map(
                        (star) => star.textContent,
                    ),
                ).toEqual(['★', '★', '★', '★', '☆']);
            });

            it('does not open the form when the address of the item is clicked', async () => {
                bodyRows()[0].querySelector('a').click();
                await flush();

                expect(showModal).not.toHaveBeenCalled();
            });

            it('opens the form with the button of the row, which is what a keyboard reaches', async () => {
                await press('Edit the item', bodyRows()[0]);

                expect(showModal).toHaveBeenCalledWith(itemDialog());
            });

            it('sends only what was changed', async () => {
                http.patch.mockResolvedValue({ data: HAMMER });
                bodyRows()[0].querySelector('td').click();
                await flush();

                await type(control('name'), 'Big hammer');
                await choose(control('state'), '');
                await submit(itemDialog().querySelector('form'));

                expect(http.patch).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i1', {
                    values: { name: 'Big hammer', state: null },
                });
                expect(notify).toHaveBeenCalledWith('success', 'Item saved.');
            });

            it('asks for a confirmation before deleting the item, then deletes it', async () => {
                http.delete.mockResolvedValue(null);
                bodyRows()[0].querySelector('td').click();
                await flush();

                await press('Delete', itemDialog().querySelector('.modal-footer'));

                expect(itemDialog().querySelector('.modal-body').textContent).toContain(
                    'Delete this item? This cannot be undone.',
                );
                expect(http.delete).not.toHaveBeenCalled();
                expect(control('name')).toBeNull();

                await press('Delete the item', itemDialog());

                expect(http.delete).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i1');
                expect(hideModal).toHaveBeenCalledWith(itemDialog());
                expect(notify).toHaveBeenCalledWith('success', 'Item deleted.');
            });

            it('goes back to the form when the deletion is given up', async () => {
                bodyRows()[0].querySelector('td').click();
                await flush();

                await press('Delete', itemDialog().querySelector('.modal-footer'));
                await press('Cancel', itemDialog().querySelector('.modal-footer'));

                expect(control('name').value).toBe('Hammer');
                expect(http.delete).not.toHaveBeenCalled();
            });
        });

        describe('reading an item as a viewer', () => {
            beforeEach(async () => {
                PAGE.abilities = { edit_items: false };
                await open({ items: [HAMMER] });
            });

            it('shows the details of the item, read only', async () => {
                bodyRows()[0].querySelector('td').click();
                await flush();

                expect(itemDialog().querySelector('#item-modal-title').textContent).toBe('Item');
                expect(itemDialog().querySelectorAll('input, select, textarea')).toHaveLength(0);

                const details = [...itemDialog().querySelectorAll('dl > div')].map((entry) => [
                    entry.querySelector('dt').textContent,
                    entry.querySelector('dd').textContent.trim(),
                ]);

                expect(details.slice(0, 3)).toEqual([
                    ['Name', 'Hammer'],
                    ['Weight', '1.50 kg'],
                    ['Price', '€19.90'],
                ]);
                expect(itemDialog().querySelector('dd a').getAttribute('rel')).toBe('noopener noreferrer nofollow');
                expect(buttons('Save', itemDialog())).toHaveLength(0);
                expect(buttons('Delete', itemDialog())).toHaveLength(0);
                expect(buttons('Close', itemDialog().querySelector('.modal-footer'))).toHaveLength(1);
            });
        });
    });

    describe('the field editor of an inventory', () => {
        const SELECT_FIELD = {
            id: 'state',
            name: 'State',
            type: 'select',
            options: {
                choices: [
                    { id: 'aaaaaaaa', label: 'New', color: 'green' },
                    { id: 'bbbbbbbb', label: 'Used' },
                ],
            },
            required: false,
            position: 2,
        };
        const FIELDS = [
            { id: 'name', name: 'Name', type: 'text', options: { max_length: 120 }, required: true, position: 0 },
            {
                id: 'qty',
                name: 'Weight',
                type: 'number',
                options: { decimals: 2, unit: 'kg' },
                required: false,
                position: 1,
            },
            SELECT_FIELD,
        ];

        /** Opens the editor of the fields of the inventory `tools`. */
        async function open(fields = FIELDS) {
            http.get.mockResolvedValue({ data: { id: 'tools', name: 'Tools', fields } });
            await mount('app/inventory-fields.blade.php');
        }

        const fieldDialog = () => document.querySelector('#field-modal-title').closest('.modal');
        const deleteDialog = () => document.querySelector('#field-delete-title').closest('.modal');
        const entries = () => [...document.querySelectorAll('.list-group-item')];
        const input = (id) => fieldDialog().querySelector('#' + id);

        it('lists the fields in order with their type, whether they are required and their options', async () => {
            await open();

            expect(entries().map((entry) => entry.querySelector('.fw-bold').textContent)).toEqual([
                'Name',
                'Weight',
                'State',
            ]);
            expect(
                entries().map((entry) =>
                    [...entry.querySelectorAll('.badge')].filter(visible).map((badge) => badge.textContent.trim()),
                ),
            ).toEqual([['Text', 'Required'], ['Number'], ['Choice']]);
            expect(entries().map((entry) => entry.querySelector('.text-secondary').textContent)).toEqual([
                'Maximum length: 120',
                'Decimals: 2 · Unit: kg',
                'Choices: New, Used',
            ]);
        });

        it('explains an inventory without a field', async () => {
            await open([]);

            expect(visible(document.querySelector('.empty'))).toBe(true);
            expect(entries()).toHaveLength(0);
        });

        it('links back to the items of the inventory', async () => {
            await open();

            const back = [...document.querySelectorAll('a.btn')].find(
                (link) => link.textContent.trim() === 'Back to the items',
            );

            expect(back.getAttribute('href')).toBe('/app/w/shop/inventories/tools');
        });

        it('moves a field up or down, and cannot go past either end', async () => {
            await open();
            http.put.mockResolvedValue({ data: [FIELDS[1], FIELDS[0], FIELDS[2]] });

            expect(buttons('Move Name up')[0].disabled).toBe(true);
            expect(buttons('Move State down')[0].disabled).toBe(true);

            await press('Move Name down');

            expect(http.put).toHaveBeenCalledWith('/api/v1/inventories/tools/fields/order', {
                fields: ['qty', 'name', 'state'],
            });
            expect(entries().map((entry) => entry.querySelector('.fw-bold').textContent)).toEqual([
                'Weight',
                'Name',
                'State',
            ]);
        });

        it('shows the options of the type that is picked, and sends them with the new field', async () => {
            await open();
            http.post.mockResolvedValue({ data: { id: 'new' } });

            await press('Add a field');

            expect(showModal).toHaveBeenCalledWith(fieldDialog());
            expect(fieldDialog().querySelector('#field-modal-title').textContent).toBe('Add a field');
            expect([...input('field-type').options].map((option) => option.textContent)).toEqual([
                'Text',
                'Number',
                'Amount',
                'Percentage',
                'Choice',
                'Rating',
                'Barcode',
            ]);
            expect(input('field-max-length')).not.toBeNull();
            expect(input('field-decimals')).toBeNull();

            await choose(input('field-type'), 'number');

            expect(input('field-max-length')).toBeNull();
            expect(input('field-decimals').value).toBe('0');
            expect(input('field-unit')).not.toBeNull();

            await type(input('field-name'), ' Length ');
            await type(input('field-decimals'), '2');
            await type(input('field-min'), '0,5');
            await type(input('field-unit'), 'm');
            await tick(fieldDialog().querySelector('input[type="checkbox"]'), true);
            await submit(fieldDialog().querySelector('form'));

            expect(http.post).toHaveBeenCalledWith('/api/v1/inventories/tools/fields', {
                type: 'number',
                name: 'Length',
                required: true,
                options: { decimals: 2, min: '0.5', max: null, unit: 'm' },
            });
            expect(notify).toHaveBeenCalledWith('success', 'Field added.');
        });

        it('offers the currencies, in the language of the page, for an amount', async () => {
            await open();
            await press('Add a field');

            await choose(input('field-type'), 'money');

            expect([...input('field-currency').options].map((option) => option.textContent)).toEqual([
                'Euro (EUR)',
                'US Dollar (USD)',
            ]);

            await choose(input('field-currency'), 'USD');
            await type(input('field-name'), 'Price');
            http.post.mockResolvedValue({ data: { id: 'new' } });
            await submit(fieldDialog().querySelector('form'));

            expect(http.post.mock.calls[0][1]).toEqual({
                type: 'money',
                name: 'Price',
                required: false,
                options: { currency: 'USD' },
            });
        });

        it('edits the choices of a select, keeping the ids of the ones that stay', async () => {
            await open();
            http.patch.mockResolvedValue({ data: SELECT_FIELD });

            await press('Edit State');

            expect(fieldDialog().querySelector('#field-modal-title').textContent).toBe('Edit the field');
            expect(input('field-type').disabled).toBe(true);
            expect(input('field-type').value).toBe('select');

            const rows = () => [...fieldDialog().querySelectorAll('.input-group')];

            expect(rows().map((row) => row.querySelector('input').value)).toEqual(['New', 'Used']);
            expect(rows().map((row) => row.querySelector('select').value)).toEqual(['green', '']);

            await type(rows()[0].querySelector('input'), 'Brand new');
            await choose(rows()[0].querySelector('select'), 'blue');
            await press('Remove the choice Used', fieldDialog());
            await press('Add a choice', fieldDialog());
            await type(rows()[1].querySelector('input'), 'Refurbished');
            await submit(fieldDialog().querySelector('form'));

            expect(http.patch).toHaveBeenCalledWith('/api/v1/inventories/tools/fields/state', {
                name: 'State',
                required: false,
                options: {
                    choices: [
                        { id: 'aaaaaaaa', label: 'Brand new', color: 'blue' },
                        { label: 'Refurbished', color: null },
                    ],
                },
            });
            expect(notify).toHaveBeenCalledWith('success', 'Field saved.');
        });

        it('shows the refusals under the inputs they are about', async () => {
            await open();
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid.', {
                    errors: {
                        name: ['This inventory already has a field with this name.'],
                        'options.max_length': ['Too long.'],
                    },
                }),
            );
            await press('Add a field');
            await type(input('field-name'), 'Name');

            await submit(fieldDialog().querySelector('form'));

            expect(input('field-name').classList.contains('is-invalid')).toBe(true);
            expect(input('field-name').parentElement.querySelector('.text-danger').textContent).toBe(
                'This inventory already has a field with this name.',
            );
            expect(input('field-max-length').parentElement.querySelector('.text-danger').textContent).toBe('Too long.');
            expect(hideModal).not.toHaveBeenCalled();
        });

        it('asks for a confirmation before deleting a field, then deletes it', async () => {
            await open();
            http.delete.mockResolvedValue(null);

            await press('Delete Weight');

            expect(showModal).toHaveBeenCalledWith(deleteDialog());
            expect(deleteDialog().querySelector('.modal-body').textContent.trim()).toBe('Delete the field Weight?');
            expect(http.delete).not.toHaveBeenCalled();

            await press('Delete the field', deleteDialog());

            expect(http.delete).toHaveBeenCalledWith('/api/v1/inventories/tools/fields/qty');
            expect(notify).toHaveBeenCalledWith('success', 'Field deleted.');
        });
    });
});
