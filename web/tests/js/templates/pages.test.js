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

import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { momentText } from '../../../resources/js/grid/rows.js';
import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { hideModal, onModalHidden, showModal } from '../../../resources/js/lib/modal.js';
import { hideOffcanvas, showOffcanvas } from '../../../resources/js/lib/offcanvas.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';
import { attachment, deferred, queryFacts, ulid, withUlids } from '../support/inventory.js';
import { MESSAGES as INVENTORY_MESSAGES } from '../support/messages.js';
import { useMemoryStorage, useRefusingStorage } from '../support/storage.js';

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
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));

const VIEWS = join(import.meta.dirname, '../../../resources/views');

/** Where the routes the templates mention lead. */
const ROUTES = {
    'api.v1.me.current-workspace': '/api/v1/me/current-workspace',
    'api.v1.workspaces.index': '/api/v1/workspaces',
    'api.v1.me.update': '/api/v1/me',
    'api.v1.me.sign-out-everywhere': '/api/v1/me/sign-out-everywhere',
    'api.v1.me.export': '/api/v1/me/export',
    'api.v1.me.deletion-code': '/api/v1/me/deletion-code',
    'api.v1.me.tokens.index': '/api/v1/me/tokens',
    'api.v1.me.destroy': '/api/v1/me',
    'api.v1.admin.users.index': '/api/v1/admin/users',
    'api.v1.admin.settings.show': '/api/v1/admin/settings',
    'api.v1.templates.index': '/api/v1/templates',
    home: '/',
    'docs.api': '/docs/api',
    'app.workspaces': '/app/workspaces',
};

/** Routes with parameters: `{name}` stands for a parameter the template gives (a literal, or a variable of PAGE). */
const ROUTE_PATTERNS = {
    'app.workspace': '/app/w/{workspace}',
    'app.inventory': '/app/w/{workspace}/inventories/{inventory}',
    'app.inventory.fields': '/app/w/{workspace}/inventories/{inventory}/fields',
    'api.v1.workspaces.inventories.index': '/api/v1/workspaces/{workspace}/inventories',
    'api.v1.templates.show': '/api/v1/templates/{slug}',
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
        { type: 'file', label: 'File' },
        { type: 'photo', label: 'Photo' },
    ],
    currencies: [
        { code: 'EUR', name: 'Euro' },
        { code: 'USD', name: 'US Dollar' },
    ],
    abilities: {},
};

/** The largest file the server takes, in bytes, as the page of an inventory says (`data-max-attachment-size`). */
const MAX_ATTACHMENT_SIZE = 5 * 1024 * 1024;

/** The largest spreadsheet the server takes, in bytes, as the page of an inventory says (`data-import-max-size`). */
const IMPORT_MAX_SIZE = 3 * 1024 * 1024;

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
    'tokens.ability.read': 'Read',
    'tokens.ability.write': 'Write',
    'tokens.ability.manage': 'Manage',
    'tokens.created': 'Created :date',
    'tokens.last_used': 'Last used :date',
    'tokens.never_used': 'Never used',
    'tokens.expires': 'Expires :date',
    'tokens.never_expires': 'Never expires',
    'tokens.revoke_named': 'Revoke the token :name',
    'tokens.confirm_revoke': 'Revoke the token :name? Anything that uses it stops working at once.',
    'tokens.copy': 'Copy',
    'tokens.copied': 'Copied',
    'tokens.select_hint': 'The token is selected: copy it with your keyboard.',
    'tokens.notice.revoked': 'Token revoked.',
    'tokens.error.limit': 'You already have :count API tokens.',
    'admin.settings.notice.opened': 'Sign-ups are open',
    'admin.settings.notice.closed': 'Sign-ups are closed',
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
        // The way a value is shown is shared by the table and the details of an item; the toolbar, the filters panel and the
        // table of the items are parts of the page of an inventory, and so are the parts of the dialog of an item.
        .replace(
            /@include\('partials\.(field-value|items-toolbar|items-filters|export-dialog|import-dialog|items-table|items-gallery|items-cell-editor|file-icon|item-attachments|item-file-field|item-history|item-upload-row)'\)/g,
            (_match, name) => render(`partials/${name}.blade.php`),
        )
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
            "config('inventories.max_attachment_size', 20 * 1024 * 1024)": String(MAX_ATTACHMENT_SIZE),
            "config('inventories.import_max_size', 10 * 1048576)": String(IMPORT_MAX_SIZE),
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
            accountTokens: '../../../resources/js/components/account-tokens.js',
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
        // The page of an inventory keeps its search, sort and filters in the address, and the view in the browser: every
        // test starts from a clean one.
        window.history.replaceState(null, '', '/');
        useMemoryStorage();
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

        describe('the API tokens', () => {
            const TOKENS = [
                {
                    id: 12,
                    name: 'Stock sync',
                    abilities: ['read', 'write'],
                    created_at: '2026-10-01T10:00:00+00:00',
                    last_used_at: '2026-10-05T08:30:00+00:00',
                    expires_at: null,
                },
                {
                    id: 9,
                    name: 'Reporting',
                    abilities: ['read'],
                    created_at: '2026-09-01T10:00:00+00:00',
                    last_used_at: null,
                    expires_at: '2027-01-01T10:00:00+00:00',
                },
            ];

            /** What a creation answers: the token and, once, its value. */
            const CREATED = {
                data: {
                    id: 15,
                    name: 'Nightly',
                    abilities: ['read', 'write'],
                    created_at: '2026-10-17T09:00:00+00:00',
                    last_used_at: null,
                    expires_at: '2026-11-16T09:00:00+00:00',
                    plain_text_token: 'inv_abc123',
                },
            };

            /** Where the cursor was put, and whether the element could take it at that moment (a hidden one cannot). */
            let focused;
            let focusSpy;

            const card = () => document.querySelector('[data-url="/api/v1/me/tokens"]');
            const form = () => card().querySelector('form');
            const field = (id) => card().querySelector(`#${id}`);
            const names = () => [...card().querySelectorAll('li .fw-bold')].map((name) => name.textContent);
            const words = (text) => text.replace(/\s+/g, ' ').trim();
            const live = () => card().querySelector('.visually-hidden[role="status"]');

            /** The block of the card that says there is no token, if it is shown. */
            const emptyMessage = () =>
                [...card().querySelectorAll('.card-body')].find(
                    (body) => body.textContent.trim() === 'You have no API token yet.',
                );

            /** Ticks or unticks a box the way a person does. */
            async function tick(box, checked) {
                box.checked = checked;
                box.dispatchEvent(new Event('change', { bubbles: true }));
                await flush();
            }

            /** Opens the form and fills it in. */
            async function fillIn({ name, abilities = [], expires } = {}) {
                await press('Create a token');
                await type(field('api-token-name'), name);

                for (const ability of abilities) {
                    await tick(form().querySelector(`input[value="${ability}"]`), true);
                }

                if (expires !== undefined) {
                    field('api-token-expiry').value = expires;
                    field('api-token-expiry').dispatchEvent(new Event('change', { bubbles: true }));
                    await flush();
                }
            }

            /** Sends the form. */
            async function submit() {
                form().dispatchEvent(new Event('submit', { cancelable: true }));
                await flush();
            }

            beforeEach(async () => {
                focused = [];
                focusSpy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function () {
                    focused.push({
                        id: this.id,
                        action: this.dataset.tokenAction ?? null,
                        label: this.textContent.trim(),
                        visible: visible(this),
                    });
                });
                http.get.mockResolvedValue({ data: TOKENS });
                await mount('app/account.blade.php');
            });

            afterEach(() => {
                focusSpy.mockRestore();
                Reflect.deleteProperty(navigator, 'clipboard');
            });

            describe('the list', () => {
                it('lists the tokens, with their permissions and dates in words, as the API gives them', () => {
                    expect(http.get).toHaveBeenCalledWith('/api/v1/me/tokens');
                    expect(names()).toEqual(['Stock sync', 'Reporting']);

                    const [first, second] = card().querySelectorAll('li');
                    expect([...first.querySelectorAll('.badge')].map((badge) => badge.textContent)).toEqual([
                        'Read',
                        'Write',
                    ]);
                    expect([...second.querySelectorAll('.badge')].map((badge) => badge.textContent)).toEqual(['Read']);
                    expect(words(first.querySelector('.small').textContent)).toBe(
                        words(
                            `Created ${momentText(TOKENS[0].created_at)} · Last used ${momentText(TOKENS[0].last_used_at)} · Never expires`,
                        ),
                    );
                    expect(words(second.querySelector('.small').textContent)).toBe(
                        words(
                            `Created ${momentText(TOKENS[1].created_at)} · Never used · Expires ${momentText(TOKENS[1].expires_at)}`,
                        ),
                    );
                    expect(emptyMessage() === undefined || !visible(emptyMessage())).toBe(true);
                });

                it('says there is no token yet when there is none', async () => {
                    http.get.mockResolvedValue({ data: [] });
                    await mount('app/account.blade.php');

                    expect(card().querySelectorAll('li')).toHaveLength(0);
                    expect(visible(emptyMessage())).toBe(true);
                });

                it('says it could not be read, and reads it again on demand', async () => {
                    http.get.mockRejectedValueOnce(new HttpError(500, 'The server hit an error.'));
                    await mount('app/account.blade.php');

                    expect(card().textContent).toContain('Your API tokens could not be loaded.');
                    expect(visible(emptyMessage())).toBe(false);

                    await press('Try again', card());

                    expect(names()).toEqual(['Stock sync', 'Reporting']);
                });

                it('links to the documentation of the API', () => {
                    const link = card().querySelector('a');

                    expect(link.textContent.trim()).toBe('API documentation');
                    expect(link.getAttribute('href')).toBe('/docs/api');
                });

                it('reads the list again when the security card has signed every other device out', async () => {
                    http.post.mockResolvedValue(null);
                    http.get.mockResolvedValue({ data: [] });
                    const security = document.querySelector('[data-url="/api/v1/me/sign-out-everywhere"]');

                    await press('Sign out everywhere', security);
                    await press('Sign out everywhere', security);

                    expect(names()).toEqual([]);
                    expect(visible(emptyMessage())).toBe(true);
                });
            });

            describe('making a token', () => {
                it('keeps the form closed until it is asked for, then puts the cursor on the name', async () => {
                    expect(visible(form())).toBe(false);

                    await press('Create a token');

                    expect(visible(form())).toBe(true);
                    expect(buttons('Create a token')).toHaveLength(0);
                    expect(focused).toEqual([{ id: 'api-token-name', action: null, label: '', visible: true }]);
                });

                it('offers read, 90 days and the other lifetimes, and the three permissions with a line each', async () => {
                    await press('Create a token');

                    const boxes = [...form().querySelectorAll('input[name="abilities"]')];
                    expect(boxes.map((box) => [box.value, box.checked])).toEqual([
                        ['read', true],
                        ['write', false],
                        ['manage', false],
                    ]);
                    expect(boxes.map((box) => words(box.closest('label').textContent))).toEqual([
                        expect.stringMatching(/^Read \S/),
                        expect.stringMatching(/^Write \S/),
                        expect.stringMatching(/^Manage \S/),
                    ]);
                    const expiry = field('api-token-expiry');
                    expect(expiry.value).toBe('90');
                    expect([...expiry.options].map((option) => [option.value, option.textContent])).toEqual([
                        ['7', '7 days'],
                        ['30', '30 days'],
                        ['90', '90 days'],
                        ['365', '365 days'],
                        ['', 'Never'],
                    ]);
                    expect(field('api-token-name').maxLength).toBe(80);
                    expect(field('api-token-name').required).toBe(true);
                });

                it('needs a name and at least one permission', async () => {
                    await press('Create a token');
                    const send = form().querySelector('button[type="submit"]');
                    expect(send.disabled).toBe(true);

                    await type(field('api-token-name'), 'Nightly');
                    expect(send.disabled).toBe(false);

                    await tick(form().querySelector('input[value="read"]'), false);
                    expect(send.disabled).toBe(true);
                });

                it('makes the token with what the form says, then shows its value once, and lists the token first', async () => {
                    http.post.mockResolvedValue(CREATED);
                    await fillIn({ name: 'Nightly', abilities: ['write'], expires: '30' });
                    focused.length = 0;

                    await submit();

                    expect(http.post).toHaveBeenCalledWith(
                        '/api/v1/me/tokens',
                        { name: 'Nightly', abilities: ['read', 'write'], expires_in_days: 30 },
                        { silent: true },
                    );
                    expect(names()).toEqual(['Nightly', 'Stock sync', 'Reporting']);
                    expect(visible(form())).toBe(false);

                    const value = field('api-token-value');
                    expect(value.value).toBe('inv_abc123');
                    expect(value.readOnly).toBe(true);
                    expect(visible(value)).toBe(true);
                    expect(card().querySelector('#api-token-warning').textContent).toContain(
                        'it will not be shown again',
                    );
                    expect(value.getAttribute('aria-describedby')).toBe('api-token-warning');
                    expect(card().querySelector('ul').textContent).not.toContain('inv_abc123');
                    expect(buttons('Create a token')).toHaveLength(0);
                    expect(focused).toEqual([{ id: 'api-token-value', action: null, label: '', visible: true }]);
                });

                it('sends null for a token that never expires', async () => {
                    http.post.mockResolvedValue(CREATED);
                    await fillIn({ name: 'Forever', expires: '' });

                    await submit();

                    expect(http.post.mock.calls[0][1].expires_in_days).toBeNull();
                });

                it('copies the value, says so, and hides it for good when the person has it', async () => {
                    const writeText = vi.fn().mockResolvedValue(undefined);
                    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
                    http.post.mockResolvedValue(CREATED);
                    await fillIn({ name: 'Nightly' });
                    await submit();

                    await press('Copy', card());

                    expect(writeText).toHaveBeenCalledWith('inv_abc123');
                    expect(buttons('Copied', card())).toHaveLength(1);
                    expect(live().textContent).toBe('Copied');

                    focused.length = 0;
                    await press('I have copied the token');

                    expect(field('api-token-value').value).toBe('');
                    expect(visible(field('api-token-value'))).toBe(false);
                    expect(buttons('Create a token')).toHaveLength(1);
                    expect(focused).toEqual([{ id: '', action: null, label: 'Create a token', visible: true }]);
                    expect(card().textContent).not.toContain('inv_abc123');
                });

                it('can be given up: the form closes and the cursor goes back to its button', async () => {
                    await fillIn({ name: 'Nightly' });
                    focused.length = 0;

                    await press('Cancel', form());

                    expect(visible(form())).toBe(false);
                    expect(http.post).not.toHaveBeenCalled();
                    expect(focused).toEqual([{ id: '', action: null, label: 'Create a token', visible: true }]);

                    await press('Create a token');
                    expect(field('api-token-name').value).toBe('');
                });

                it('shows the validation messages next to their fields, wired to them', async () => {
                    http.post.mockRejectedValue(
                        new HttpError(422, 'The given data was invalid.', {
                            errors: {
                                name: ['The name field is required.'],
                                abilities: ['Pick a permission.'],
                                expires_in_days: ['The lifetime is not valid.'],
                            },
                        }),
                    );
                    await fillIn({ name: 'x' });
                    focused.length = 0;

                    await submit();

                    const name = field('api-token-name');
                    expect(name.classList.contains('is-invalid')).toBe(true);
                    expect(name.getAttribute('aria-invalid')).toBe('true');
                    expect(name.getAttribute('aria-describedby').split(' ')).toContain('api-token-name-error');
                    expect(field('api-token-name-error').textContent).toBe('The name field is required.');
                    expect(visible(field('api-token-abilities-error'))).toBe(true);
                    expect(field('api-token-abilities-error').textContent).toBe('Pick a permission.');
                    expect(field('api-token-expiry').classList.contains('is-invalid')).toBe(true);
                    expect(field('api-token-expiry-error').textContent).toBe('The lifetime is not valid.');
                    expect(visible(form())).toBe(true);
                    expect(names()).toEqual(['Stock sync', 'Reporting']);
                    expect(focused).toEqual([{ id: 'api-token-name', action: null, label: '', visible: true }]);
                });

                it('says in an alert that the limit of tokens is reached, or that creations come too fast', async () => {
                    http.post.mockRejectedValueOnce(
                        new HttpError(409, 'Conflict', { code: 'token_limit_reached', body: { limit: 20 } }),
                    );
                    await fillIn({ name: 'Nightly' });
                    await submit();

                    const alert = form().querySelector('.alert-danger');
                    expect(alert.getAttribute('role')).toBe('alert');
                    expect(visible(alert)).toBe(true);
                    expect(alert.textContent).toBe('You already have 20 API tokens.');

                    http.post.mockRejectedValueOnce(new HttpError(429, 'Too many', { retryAfter: 1800 }));
                    await submit();

                    expect(alert.textContent).toBe('Wait 30 minutes');
                });
            });

            describe('revoking a token', () => {
                it('asks for a confirmation in the row, with the cursor on its button, before revoking anything', async () => {
                    await press('Revoke the token Stock sync');

                    const row = card().querySelectorAll('li')[0];
                    expect(http.delete).not.toHaveBeenCalled();
                    expect(words(row.textContent)).toContain(
                        'Revoke the token Stock sync? Anything that uses it stops working at once.',
                    );
                    expect(buttons('Revoke the token Reporting')).toHaveLength(1);
                    expect(focused).toEqual([{ id: '', action: 'confirm', label: 'Revoke', visible: true }]);
                });

                it('can be given up, which gives the cursor back to the button of the row', async () => {
                    await press('Revoke the token Stock sync');
                    focused.length = 0;

                    await press('Cancel', card().querySelectorAll('li')[0]);

                    expect(http.delete).not.toHaveBeenCalled();
                    expect(names()).toEqual(['Stock sync', 'Reporting']);
                    expect(focused).toEqual([{ id: '', action: 'revoke', label: 'Revoke', visible: true }]);
                });

                it('revokes the token, takes it off the list and says so politely', async () => {
                    http.delete.mockResolvedValue(null);
                    await press('Revoke the token Stock sync');
                    focused.length = 0;

                    await press('Revoke the token Stock sync');

                    expect(http.delete).toHaveBeenCalledWith('/api/v1/me/tokens/12');
                    expect(names()).toEqual(['Reporting']);
                    expect(live().textContent).toBe('Token revoked.');
                    expect(live().getAttribute('aria-live')).toBe('polite');
                    expect(focused).toEqual([{ id: '', action: null, label: 'Active tokens', visible: true }]);
                });

                it('keeps the token in the list when it could not be revoked', async () => {
                    http.delete.mockRejectedValue(new HttpError(422, 'Cannot do it.'));
                    await press('Revoke the token Stock sync');
                    await press('Revoke the token Stock sync');

                    expect(names()).toEqual(['Stock sync', 'Reporting']);
                    expect(notify).toHaveBeenCalledWith('danger', 'Cannot do it.');
                });
            });

            describe('what assistive technology finds', () => {
                it('has a name on every button, a label on every field, and every description it points to', async () => {
                    http.post.mockResolvedValue(CREATED);
                    await fillIn({ name: 'Nightly' });
                    await submit();

                    for (const button of card().querySelectorAll('button')) {
                        expect(
                            (button.getAttribute('aria-label') ?? button.textContent).trim(),
                            `the name of ${button.outerHTML.slice(0, 80)}`,
                        ).not.toBe('');
                    }

                    for (const input of card().querySelectorAll('input, select')) {
                        const label = input.id
                            ? card().querySelector(`label[for="${input.id}"]`)
                            : input.closest('label');

                        expect(label, `the label of ${input.outerHTML.slice(0, 80)}`).not.toBeNull();
                    }

                    for (const element of card().querySelectorAll('[aria-describedby]')) {
                        for (const id of element.getAttribute('aria-describedby').split(' ')) {
                            expect(card().querySelector(`#${id}`), `#${id}`).not.toBeNull();
                        }
                    }

                    expect(form().querySelector('fieldset > legend').textContent.trim()).toBe('Permissions');
                    expect(card().querySelector('h2').textContent.trim()).toBe('API tokens');
                });

                it('stacks the rows on a narrow screen, and breaks the long names', () => {
                    const row = card().querySelector('li .row');

                    expect([...row.children].map((column) => column.className)).toEqual([
                        'col-12 col-md',
                        'col-12 col-md-auto',
                    ]);
                    expect(row.querySelector('.fw-bold').classList.contains('text-break')).toBe(true);
                });
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

        /** Takes the first step of the dialog: an empty inventory, which leads to the form. */
        async function startFromScratch() {
            dialog().querySelector('[data-step="choose"] [data-autofocus]').click();
            await flush();
        }

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
            expect(document.querySelector('[role="status"]').textContent).toBe('Loading…');
        });

        it('keeps the live region of the page where it is, and only changes what it says', async () => {
            const reading = deferred();
            http.get.mockReturnValue(reading.promise);
            await mount('app/workspace.blade.php');

            const region = document.querySelector('[x-data="workspaceInventories"] > [role="status"]');

            expect(region.hidden).toBe(false);
            expect(region.textContent).toBe('Loading…');

            reading.resolve({ data: INVENTORIES });
            await flush();

            expect(region.isConnected).toBe(true);
            expect(region.hidden).toBe(false);
            expect(region.textContent).toBe('');
            // The spinner people see is no second announcement.
            expect(visible(document.querySelector('[aria-hidden="true"].text-secondary'))).toBe(false);
        });

        it('takes the addresses of the templates from the named routes, not from literals', async () => {
            const source = readFileSync(join(VIEWS, 'app/workspace.blade.php'), 'utf8');

            expect(source).toContain(`data-templates-url="{{ route('api.v1.templates.index', absolute: false) }}"`);
            expect(source).toContain(
                `data-template-url="{{ route('api.v1.templates.show', ['slug' => '__id__'], absolute: false) }}"`,
            );

            await open();
            const root = document.querySelector('[x-data="workspaceInventories"]');

            expect(root.dataset.templatesUrl).toBe('/api/v1/templates');
            expect(root.dataset.templateUrl).toBe('/api/v1/templates/__id__');
        });

        it('does not claim the workspace is empty when the list could not be read, and reads it again on request', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server down'));
            await mount('app/workspace.blade.php');

            const alert = document.querySelector('.alert-danger[role="alert"]');

            expect(visible(alert)).toBe(true);
            expect(alert.textContent).toContain('The inventories could not be loaded.');
            expect(visible(document.querySelector('.empty'))).toBe(false);

            http.get.mockResolvedValue({ data: [] });
            await press('Try again', alert);

            expect(visible(alert)).toBe(false);
            expect(visible(document.querySelector('.empty'))).toBe(true);
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

            await startFromScratch();

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
            await startFromScratch();
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
            await startFromScratch();
            const form = dialog().querySelector('form');

            await type(form.querySelector('#inventory-create-name'), 'x');
            await submit(form);

            const alert = dialog().querySelector('.alert');

            expect(visible(alert)).toBe(true);
            expect(alert.textContent).toBe('Inventory limit reached (100 per workspace).');
        });

        describe('starting from a template', () => {
            const CATALOGUE = [
                {
                    slug: 'home-inventory',
                    category: 'home',
                    category_label: 'Home',
                    icon: 'home',
                    name: 'Home inventory',
                    summary: 'Everything you own, for your insurer.',
                    fields_count: 3,
                    url: '/templates/home-inventory',
                },
                {
                    slug: 'wine-cellar',
                    category: 'home',
                    category_label: 'Home',
                    icon: 'bottle',
                    name: 'Wine cellar',
                    summary: 'Bottles, vintages and where they lie.',
                    fields_count: 1,
                    url: '/templates/wine-cellar',
                },
                {
                    slug: 'it-equipment',
                    category: 'it',
                    category_label: 'IT',
                    icon: 'device-laptop',
                    name: 'IT equipment',
                    summary: 'Laptops, phones and licences.',
                    fields_count: 2,
                    url: '/templates/it-equipment',
                },
            ];

            /** A field of a template, as the API describes it. */
            const templateField = (key, name, type, typeLabel, required = false) => ({
                key,
                name,
                type,
                type_label: typeLabel,
                required,
                options: {},
            });

            const DETAILS = {
                'home-inventory': {
                    ...CATALOGUE[0],
                    description: ['A first paragraph.'],
                    fields: [
                        templateField('room', 'Room', 'select', 'Choice'),
                        templateField('item', 'Item', 'text', 'Text', true),
                        templateField('price', 'Purchase price', 'money', 'Amount'),
                    ],
                    examples: [],
                },
                'it-equipment': {
                    ...CATALOGUE[2],
                    description: [],
                    fields: [
                        templateField('serial', 'Serial number', 'text', 'Text', true),
                        templateField('bought', 'Bought on', 'date', 'Date'),
                    ],
                    examples: [],
                },
            };

            /** Answers the three routes the dialog reads: the inventories, the catalogue, and a template by its slug. */
            function serve({ catalogue = CATALOGUE } = {}) {
                http.get.mockImplementation(async (url) => {
                    // One inventory, so that the page offers a single "New inventory" button.
                    if (url === '/api/v1/workspaces/shop/inventories') {
                        return { data: INVENTORIES.slice(0, 1) };
                    }

                    if (url === '/api/v1/templates') {
                        return { data: catalogue };
                    }

                    const slug = url.match(/^\/api\/v1\/templates\/(.+)$/)?.[1];

                    if (slug !== undefined && slug in DETAILS) {
                        return { data: DETAILS[slug] };
                    }

                    throw new HttpError(404, 'Not found');
                });
            }

            const STEPS = ['choose', 'pick', 'preview', 'form'];
            const step = (name) => dialog().querySelector(`[data-step="${name}"]`);

            /** The steps of the dialog that are on screen: there is always exactly one. */
            const shown = () => STEPS.filter((name) => visible(step(name)));

            /** The templates of the picker, as the cards show them. */
            const cards = () => [...step('pick').querySelectorAll('.app-choice')];
            const cardNames = () => cards().map((card) => card.querySelector('.fw-bold').textContent);

            /** A button of the dialog, by its text (hidden or not). */
            const button = (label) =>
                [...dialog().querySelectorAll('button')].find((candidate) => candidate.textContent.trim() === label);

            /** A paragraph of the picker, by its text. */
            const note = (text) =>
                [...step('pick').querySelectorAll('p')].find((paragraph) => paragraph.textContent === text);

            const search = () => dialog().querySelector('#inventory-template-search');
            const chips = () => [...step('pick').querySelectorAll('[aria-pressed]')];
            const announcement = () => dialog().querySelector('[aria-live="polite"]').textContent;

            /** Takes the second choice of the first step: a template. */
            async function startFromTemplate() {
                dialog().querySelector('[data-step="choose"] button:not([data-autofocus])').click();
                await flush();
            }

            /** Opens the page and its dialog, and takes the way of the templates up to the picker. */
            async function openPicker() {
                serve();
                await mount('app/workspace.blade.php');
                await press('New inventory');
                await startFromTemplate();
            }

            /** Picks the template of that name in the picker, and waits for its preview. */
            async function pick(name) {
                cards()
                    .find((card) => card.querySelector('.fw-bold').textContent === name)
                    .click();
                await flush();
            }

            it('asks first whether to start from scratch or from a template, and reads no template yet', async () => {
                serve();
                await mount('app/workspace.blade.php');
                await press('New inventory');

                expect(shown()).toEqual(['choose']);

                const choices = [...step('choose').querySelectorAll('button')];

                expect(choices.map((choice) => choice.getAttribute('aria-labelledby'))).toEqual([
                    'inventory-choice-scratch',
                    'inventory-choice-template',
                ]);
                expect(document.getElementById('inventory-choice-scratch').textContent).toBe('Start from scratch');
                expect(document.getElementById('inventory-choice-template').textContent).toBe('Start from a template');
                expect(visible(button('Back'))).toBe(false);
                expect(http.get.mock.calls.map(([url]) => url)).toEqual(['/api/v1/workspaces/shop/inventories']);
            });

            it('reads the catalogue when the picker opens, once per page, and lists the templates as cards', async () => {
                await openPicker();

                expect(shown()).toEqual(['pick']);
                expect(http.get).toHaveBeenCalledWith('/api/v1/templates');
                expect(cardNames()).toEqual(['Home inventory', 'Wine cellar', 'IT equipment']);
                expect(cards()[0].querySelector('.app-clamp').textContent).toBe(
                    'Everything you own, for your insurer.',
                );
                expect(cards().map((card) => card.querySelector('.small:last-child').textContent)).toEqual([
                    '3 fields',
                    '1 field',
                    '2 fields',
                ]);
                expect(dialog().querySelector('.modal-dialog').classList.contains('modal-lg')).toBe(true);
                expect(document.activeElement).toBe(search());

                // The dialog is closed and opened again: the catalogue is not read a second time.
                http.get.mockClear();
                onModalHidden.mock.calls.at(-1)[1]();
                await press('New inventory');

                expect(dialog().querySelector('.modal-dialog').classList.contains('modal-lg')).toBe(false);

                await startFromTemplate();

                expect(cardNames()).toHaveLength(3);
                expect(http.get).not.toHaveBeenCalled();
            });

            it('puts the cursor back in the search box when the catalogue is read again, the retry button being gone', async () => {
                http.get.mockImplementation(async (url) => {
                    if (url === '/api/v1/templates') {
                        throw new HttpError(500, 'Server down');
                    }

                    return { data: INVENTORIES };
                });
                await mount('app/workspace.blade.php');
                await press('New inventory');
                await startFromTemplate();

                const alert = step('pick').querySelector('.alert-danger');

                buttons('Try again', alert)[0].focus();
                serve();
                await press('Try again', alert);

                expect(visible(alert)).toBe(false);
                expect(document.activeElement).toBe(search());
            });

            it('keeps the live regions of the picker and of the preview on the page, and only changes what they say', async () => {
                const catalogue = deferred();

                http.get.mockImplementation(async (url) =>
                    url === '/api/v1/workspaces/shop/inventories'
                        ? { data: INVENTORIES.slice(0, 1) }
                        : catalogue.promise,
                );
                await mount('app/workspace.blade.php');
                await press('New inventory');

                const [pickStatus, pickCount] = step('pick').querySelectorAll('[role="status"]');
                const previewStatus = step('preview').querySelector('[role="status"]');
                const regions = [pickStatus, pickCount, previewStatus];
                const onPage = () => regions.every((region) => region.isConnected && !region.hidden);

                expect(regions.every(Boolean)).toBe(true);
                expect(onPage()).toBe(true);
                expect([pickStatus.textContent, previewStatus.textContent]).toEqual(['', '']);

                await startFromTemplate();

                expect(onPage()).toBe(true);
                expect(pickStatus.textContent).toBe('Loading…');

                catalogue.resolve({ data: CATALOGUE });
                await flush();

                expect(onPage()).toBe(true);
                expect(pickStatus.textContent).toBe('');
                expect(pickCount.textContent).toBe('3 templates');

                const template = deferred();

                http.get.mockImplementation(async () => template.promise);
                await pick('Home inventory');

                expect(onPage()).toBe(true);
                expect(previewStatus.textContent).toBe('Loading…');
                expect(pickStatus.textContent).toBe('');

                template.resolve({ data: DETAILS['home-inventory'] });
                await flush();

                expect(onPage()).toBe(true);
                expect(previewStatus.textContent).toBe('');
            });

            it('narrows the templates by search, whatever the case or the accents, and says how many are left', async () => {
                await openPicker();

                expect(announcement()).toBe('3 templates');

                await type(search(), 'CELLÁR');

                expect(cardNames()).toEqual(['Wine cellar']);
                expect(announcement()).toBe('1 template');

                await type(search(), 'insurer own');

                expect(cardNames()).toEqual(['Home inventory']);

                await type(search(), 'nothing like this');

                expect(cardNames()).toEqual([]);
                expect(visible(note('No template matches your search.'))).toBe(true);
                expect(announcement()).toBe('0 templates');

                await type(search(), '');

                expect(cardNames()).toHaveLength(3);
                expect(visible(note('No template matches your search.'))).toBe(false);
            });

            it('narrows the templates by category, with buttons that say which one is in force', async () => {
                await openPicker();

                expect(chips().map((chip) => [chip.textContent, chip.getAttribute('aria-pressed')])).toEqual([
                    ['All templates', 'true'],
                    ['Home', 'false'],
                    ['IT', 'false'],
                ]);

                chips()[2].click();
                await flush();

                expect(cardNames()).toEqual(['IT equipment']);
                expect(chips().map((chip) => chip.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);

                // The search and the category narrow together.
                chips()[1].click();
                await flush();
                await type(search(), 'wine');

                expect(cardNames()).toEqual(['Wine cellar']);

                chips()[0].click();
                await type(search(), '');

                expect(cardNames()).toHaveLength(3);
            });

            it('offers no categories when the catalogue has only one', async () => {
                serve({ catalogue: CATALOGUE.slice(0, 2) });
                await mount('app/workspace.blade.php');
                await press('New inventory');
                await startFromTemplate();

                expect(cardNames()).toHaveLength(2);
                expect(visible(step('pick').querySelector('[role="group"]'))).toBe(false);
            });

            it('says when there is no template at all', async () => {
                serve({ catalogue: [] });
                await mount('app/workspace.blade.php');
                await press('New inventory');
                await startFromTemplate();

                expect(visible(note('No template is available yet.'))).toBe(true);
                expect(visible(note('No template matches your search.'))).toBe(false);
            });

            it('says when the catalogue could not be read, and reads it again on request', async () => {
                http.get.mockImplementation(async (url) => {
                    if (url === '/api/v1/templates') {
                        throw new HttpError(500, 'Server down');
                    }

                    return { data: INVENTORIES };
                });
                await mount('app/workspace.blade.php');
                await press('New inventory');
                await startFromTemplate();

                const alert = step('pick').querySelector('.alert-danger');

                expect(visible(alert)).toBe(true);
                expect(alert.textContent).toContain('The templates could not be loaded.');
                expect(cards()).toHaveLength(0);

                serve();
                await press('Try again', alert);

                expect(visible(alert)).toBe(false);
                expect(cardNames()).toHaveLength(3);
            });

            it('previews a template: its summary, and its fields with their type and a mark on the required ones', async () => {
                await openPicker();
                await pick('Home inventory');

                expect(shown()).toEqual(['preview']);
                expect(http.get).toHaveBeenCalledWith('/api/v1/templates/home-inventory', { silent: true });

                const preview = step('preview');

                expect(preview.querySelector('h3').textContent).toBe('Home inventory');
                expect(document.activeElement).toBe(preview.querySelector('h3'));
                expect(preview.querySelector('.badge').textContent).toBe('Home');
                expect(preview.textContent).toContain('Everything you own, for your insurer.');

                const rows = [...preview.querySelectorAll('li')].map((row) => [
                    row.querySelector('.app-grow').textContent,
                    row.querySelectorAll('.badge')[1].textContent,
                    visible(row.querySelectorAll('.badge')[0]),
                ]);

                expect(rows).toEqual([
                    ['Room', 'Choice', false],
                    ['Item', 'Text', true],
                    ['Purchase price', 'Amount', false],
                ]);
                expect(visible(button('Use this template'))).toBe(true);
                expect(visible(button('Back'))).toBe(true);
            });

            it('shows what the API says as text, never as markup', async () => {
                serve({
                    catalogue: [{ ...CATALOGUE[0], name: '<img src=x onerror=alert(1)>', summary: '<b>bold</b>' }],
                });
                await mount('app/workspace.blade.php');
                await press('New inventory');
                await startFromTemplate();

                expect(dialog().querySelector('img')).toBeNull();
                expect(dialog().querySelector('.app-choice b')).toBeNull();
                expect(cardNames()).toEqual(['<img src=x onerror=alert(1)>']);
                expect(cards()[0].querySelector('.app-clamp').textContent).toBe('<b>bold</b>');
            });

            it('shows what it knows while the fields are being read, and offers to read them again when that failed', async () => {
                await openPicker();
                const reading = deferred();

                http.get.mockImplementation(() => reading.promise);
                await pick('Home inventory');

                // What the catalogue says is shown meanwhile, and the template can already be used.
                expect(step('preview').querySelector('h3').textContent).toBe('Home inventory');
                expect(step('preview').querySelector('[role="status"]').textContent).toBe('Loading…');
                expect(button('Use this template').disabled).toBe(false);

                reading.reject(new HttpError(500, 'Server down'));
                await flush();

                const alert = step('preview').querySelector('.alert-danger');

                expect(visible(alert)).toBe(true);
                expect(step('preview').querySelector('[role="status"]').textContent).toBe('');
                expect(notify).toHaveBeenCalledWith('danger', 'Server down');

                serve();
                await press('Try again', alert);

                expect(visible(alert)).toBe(false);
                expect(step('preview').querySelectorAll('li')).toHaveLength(3);
            });

            it('goes on to the form with the name and the description the template proposes, and creates from it', async () => {
                await openPicker();
                await pick('Home inventory');
                await press('Use this template');

                expect(shown()).toEqual(['form']);
                expect(dialog().querySelector('#inventory-create-name').value).toBe('Home inventory');
                expect(dialog().querySelector('#inventory-create-description').value).toBe(
                    'Everything you own, for your insurer.',
                );
                expect(step('form').querySelector('p').textContent).toBe('Template: Home inventory');
                expect(visible(step('form').querySelector('p'))).toBe(true);
                expect(document.activeElement).toBe(dialog().querySelector('#inventory-create-name'));
                expect(button('Create the inventory').disabled).toBe(false);

                await type(dialog().querySelector('#inventory-create-name'), 'My flat');
                http.post.mockResolvedValue({ data: { id: 'flat', name: 'My flat', description: null } });
                await submit(dialog().querySelector('form'));

                expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/inventories', {
                    name: 'My flat',
                    description: 'Everything you own, for your insurer.',
                    template: 'home-inventory',
                });
                expect(redirectTo).toHaveBeenCalledWith('/app/w/shop/inventories/flat');
            });

            it('goes back one step at a time, and keeps what was typed for the same template', async () => {
                await openPicker();
                await pick('Home inventory');
                await press('Use this template');
                await type(dialog().querySelector('#inventory-create-name'), 'My flat');

                await press('Back');

                expect(shown()).toEqual(['preview']);

                await press('Use this template');

                expect(dialog().querySelector('#inventory-create-name').value).toBe('My flat');

                await press('Back');
                await press('Back');

                expect(shown()).toEqual(['pick']);
                expect(document.activeElement).toBe(search());

                await pick('IT equipment');
                await press('Use this template');

                expect(dialog().querySelector('#inventory-create-name').value).toBe('IT equipment');
                expect(step('form').querySelector('p').textContent).toBe('Template: IT equipment');

                await press('Back');
                await press('Back');
                await press('Back');

                expect(shown()).toEqual(['choose']);
                expect(visible(button('Back'))).toBe(false);
            });

            it('starts from an empty form when the person turns to starting from scratch after a template', async () => {
                await openPicker();
                await pick('Home inventory');
                await press('Use this template');
                await press('Back');
                await press('Back');
                await press('Back');
                await startFromScratch();

                expect(shown()).toEqual(['form']);
                expect(dialog().querySelector('#inventory-create-name').value).toBe('');
                expect(dialog().querySelector('#inventory-create-description').value).toBe('');
                expect(visible(step('form').querySelector('p'))).toBe(false);

                http.post.mockResolvedValue({ data: { id: 'new', name: 'Garden', description: null } });
                await type(dialog().querySelector('#inventory-create-name'), 'Garden');
                await submit(dialog().querySelector('form'));

                expect(http.post.mock.calls[0][1]).toStrictEqual({ name: 'Garden', description: null });
            });

            it('goes back from the form of an empty inventory to the first step', async () => {
                serve();
                await mount('app/workspace.blade.php');
                await press('New inventory');
                await startFromScratch();

                expect(document.activeElement).toBe(dialog().querySelector('#inventory-create-name'));

                await press('Back');

                expect(shown()).toEqual(['choose']);
            });

            it('starts again from the first step each time the dialog opens', async () => {
                await openPicker();
                await pick('Home inventory');
                await press('Use this template');

                // Bootstrap tells the component when the dialog has been closed, whichever way it was.
                onModalHidden.mock.calls.at(-1)[1]();
                await flush();

                expect(shown()).toEqual(['choose']);
                expect(dialog().querySelector('#inventory-create-name').value).toBe('');
                expect(search().value).toBe('');
            });

            it('never submits the form from another step, whatever the Enter key does', async () => {
                await openPicker();
                await type(search(), 'wine');

                const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
                search().dispatchEvent(enter);
                await submit(dialog().querySelector('form'));

                expect(enter.defaultPrevented).toBe(true);
                expect(http.post).not.toHaveBeenCalled();
            });

            it('shows the refusals of the server at the top of the dialog', async () => {
                await openPicker();
                await pick('Home inventory');
                await press('Use this template');

                http.post.mockRejectedValueOnce(
                    new HttpError(422, 'Invalid.', { errors: { template: ['The selected template is invalid.'] } }),
                );
                await submit(dialog().querySelector('form'));

                const alert = dialog().querySelector('.modal-body > .alert');

                expect(visible(alert)).toBe(true);
                expect(alert.textContent).toBe('The selected template is invalid.');

                http.post.mockRejectedValueOnce(
                    new HttpError(409, 'Conflict', { code: 'field_limit_reached', body: { limit: 100 } }),
                );
                await submit(dialog().querySelector('form'));

                expect(alert.textContent).toBe('Field limit reached (100 per inventory).');
                expect(redirectTo).not.toHaveBeenCalled();
            });

            describe('from a link', () => {
                /** Opens the page of the workspace at an address that may name a template. */
                async function openAt(query) {
                    window.history.replaceState(null, '', '/app/w/shop' + query);
                    serve();
                    await mount('app/workspace.blade.php');
                }

                const UNKNOWN = 'This template does not exist. Pick one from the list.';

                it('opens the dialog on the preview of the template the address names, and cleans the address', async () => {
                    await openAt('?template=home-inventory&view=cards');

                    expect(showModal).toHaveBeenCalledWith(dialog());
                    expect(shown()).toEqual(['preview']);
                    expect(step('preview').querySelector('h3').textContent).toBe('Home inventory');
                    expect(step('preview').querySelectorAll('li')).toHaveLength(3);
                    expect(window.location.search).toBe('?view=cards');

                    await press('Use this template');

                    expect(dialog().querySelector('#inventory-create-name').value).toBe('Home inventory');
                });

                it('gives the focus to the button that adds an inventory as the dialog opens, for it to be given back there', async () => {
                    let anchor = null;

                    showModal.mockImplementationOnce(() => {
                        anchor = document.activeElement;
                    });
                    await openAt('?template=home-inventory');

                    expect(anchor).not.toBeNull();
                    expect(anchor).toBe(buttons('New inventory')[0]);
                });

                it('goes back from that preview to the picker', async () => {
                    await openAt('?template=home-inventory');
                    await press('Back');

                    expect(shown()).toEqual(['pick']);
                    expect(cardNames()).toHaveLength(3);
                });

                it('opens the picker, with a notice, when no template has that name', async () => {
                    await openAt('?template=no-such-template');

                    expect(notify).toHaveBeenCalledWith('warning', UNKNOWN);
                    expect(showModal).toHaveBeenCalledWith(dialog());
                    expect(shown()).toEqual(['pick']);
                    expect(cardNames()).toHaveLength(3);
                    expect(window.location.search).toBe('');
                });

                it('does not ask the server about what cannot be the name of a template', async () => {
                    await openAt('?template=' + encodeURIComponent('../../me'));

                    const asked = http.get.mock.calls.map(([url]) => url);

                    expect(asked.filter((url) => url.startsWith('/api/v1/templates/'))).toEqual([]);
                    expect(notify).toHaveBeenCalledWith('warning', UNKNOWN);
                    expect(shown()).toEqual(['pick']);
                });

                it('opens nothing for a person who may not add inventories, and cleans the address all the same', async () => {
                    PAGE.abilities = { create: false };
                    await openAt('?template=home-inventory');

                    expect(showModal).not.toHaveBeenCalled();
                    expect(window.location.search).toBe('');
                });

                it('leaves the dialog and the address alone when the address names no template', async () => {
                    await openAt('?view=cards');

                    expect(showModal).not.toHaveBeenCalled();
                    expect(window.location.search).toBe('?view=cards');
                });
            });
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
        ].map((one) => ({ ...one, ...queryFacts(one.type) }));
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
        function itemsPage(
            items,
            { current = 1, last = 1, total = items.length, from = 1, to = items.length, totals = {} } = {},
        ) {
            return { data: items, meta: { current_page: current, last_page: last, total, from, to, totals } };
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
                    'Date added',
                    'Last modified',
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

            it('does not claim the inventory has no field or no item when it could not be read, and reads it again on request', async () => {
                http.get.mockRejectedValue(new HttpError(500, 'Server down'));
                await mount('app/inventory.blade.php');

                const alert = document.querySelector('.card-body > .alert-danger');

                expect(visible(alert)).toBe(true);
                expect(alert.textContent).toContain('The items could not be loaded.');
                expect([...document.querySelectorAll('.empty')].filter((element) => visible(element))).toHaveLength(0);
                expect(buttons('Add item')).toHaveLength(0);

                http.get.mockImplementation(async (url) =>
                    url === '/api/v1/inventories/tools'
                        ? { data: { id: 'tools', name: 'Tools', items_count: 1, fields: FIELDS } }
                        : itemsPage([HAMMER]),
                );
                await press('Try again', alert);

                expect(visible(alert)).toBe(false);
                expect(bodyRows()).toHaveLength(1);
                expect(buttons('Add item')).toHaveLength(1);
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

        describe('searching, filtering, sorting and totalling the items', () => {
            const STORED = {
                id: 'i3',
                values: { name: 'Saw', qty: '2.50', price: '10.00' },
                created_at: '2026-10-15T10:00:00Z',
                updated_at: '2026-10-16T08:30:00Z',
            };
            const TOTALS = { qty: '4.00', price: '29.90' };
            const searchBox = () => document.querySelector('input[type="search"]');
            const headers = () => [...document.querySelectorAll('thead th')];
            const headerOf = (name) => headers().find((cell) => cell.textContent.trim() === name);
            const panel = () => document.querySelector('#item-filters-title').closest('.offcanvas');
            const lastParams = () => http.get.mock.lastCall[1].params;
            /** What a reading that asks for the totals adds to its parameters: the fields of the fixture that add up. */
            const ASK_TOTALS = { 'totals[0]': 'qty', 'totals[1]': 'price' };
            const filtersButton = () => document.querySelector('button[aria-haspopup="dialog"]');

            it('shows a search box, the filters button and the number of items, and none of it while nothing was read', async () => {
                http.get.mockReturnValue(new Promise(() => {}));
                await mount('app/inventory.blade.php');

                expect(visible(searchBox())).toBe(false);

                await open({ items: [HAMMER, TRICKY] });

                expect(visible(searchBox())).toBe(true);
                expect(searchBox().getAttribute('aria-label')).toBe('Search');
                expect(visible(filtersButton())).toBe(true);
                expect(filtersButton().textContent).toContain('Filters');
                expect(document.body.textContent).toContain('2 items');
            });

            it('hides them in an inventory that holds no item', async () => {
                await open({ items: [] });

                expect(visible(searchBox())).toBe(false);
            });

            it('has a title the cursor can be put on when the controls it was on are gone (the last chip of an empty inventory)', async () => {
                await open({ items: [] });

                const title = document.querySelector('.card-title');

                expect(title.textContent).toBe('Items');
                expect(title.getAttribute('tabindex')).toBe('-1');

                title.focus();

                expect(document.activeElement).toBe(title);
            });

            it('searches once typing pauses, from the first page, and keeps the words in the address', async () => {
                await open({ items: [HAMMER], meta: { current: 1, last: 3, total: 120, from: 1, to: 50 } });
                http.get.mockClear();

                await type(searchBox(), 'ham');
                await type(searchBox(), 'hamm');
                expect(http.get).not.toHaveBeenCalled();
                await new Promise((resolve) => setTimeout(resolve, 400));
                await flush();

                expect(http.get).toHaveBeenCalledTimes(1);
                expect(lastParams()).toEqual({ page: 1, q: 'hamm', ...ASK_TOTALS });
                expect(window.location.search).toBe('?q=hamm');
            });

            it('stops waiting for the end of the typing when the page is taken apart (Alpine calls destroy())', async () => {
                await open({ items: [HAMMER] });
                http.get.mockClear();

                await type(searchBox(), 'ham');
                document.body.replaceChildren();
                await flush();
                await new Promise((resolve) => setTimeout(resolve, 400));

                expect(http.get).not.toHaveBeenCalled();
            });

            it('searches at once on Enter, and clears the search with its button', async () => {
                await open({ items: [HAMMER] });

                await type(searchBox(), 'ham');
                searchBox().dispatchEvent(
                    new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
                );
                await flush();

                expect(lastParams()).toEqual({ page: 1, q: 'ham', ...ASK_TOTALS });

                await press('Clear the search');

                expect(searchBox().value).toBe('');
                expect(lastParams()).toEqual({ page: 1, ...ASK_TOTALS });
                expect(buttons('Clear the search')).toHaveLength(0);
            });

            it('keeps the table on screen while it reads again, with a spinner in the toolbar instead of a full-page one', async () => {
                await open({ items: [HAMMER] });
                const slow = { resolve: null };
                http.get.mockImplementation(
                    () =>
                        new Promise((resolve) => {
                            slow.resolve = () => resolve(itemsPage([HAMMER]));
                        }),
                );

                await type(searchBox(), 'ham');
                searchBox().dispatchEvent(
                    new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
                );
                await flush();

                const table = document.querySelector('.table-responsive');

                expect(visible(table)).toBe(true);
                expect(bodyRows()).toHaveLength(1);
                expect(table.getAttribute('aria-busy')).toBe('true');
                expect(table.className).toContain('opacity-75');
                expect(
                    visible(document.querySelector('.card-toolbar .spinner-border, .border-bottom .spinner-border')),
                ).toBe(true);
                expect(visible(document.querySelector('.card-body[role="status"]'))).toBe(false);

                slow.resolve();
                await flush();

                expect(table.getAttribute('aria-busy')).toBe('false');
            });

            it('shows how many items match out of how many the inventory holds', async () => {
                window.history.replaceState(null, '', '/?q=saw');
                await open({ items: [STORED], itemsCount: 120, meta: { total: 1 } });

                expect(document.body.textContent).toContain('1 result out of 120');
            });

            it('makes the header of each field that can be sorted a button, and the others plain text', async () => {
                await open();

                expect(headerOf('Price').querySelector('button')).not.toBeNull();
                expect(headerOf('Price').getAttribute('aria-sort')).toBe('none');
                expect(headerOf('Date added').querySelector('button')).not.toBeNull();
                expect(headerOf('Parts').querySelector('button')).toBeNull();
                expect(headerOf('Parts').hasAttribute('aria-sort')).toBe(false);
                expect(headerOf('Labels').querySelector('button')).toBeNull();
            });

            it('sorts ascending, then descending, then back to the newest first, as a header is clicked', async () => {
                await open();
                const price = () => headerOf('Price').querySelector('button');

                price().click();
                await flush();
                expect(lastParams()).toEqual({ page: 1, sort: 'price' });
                expect(headerOf('Price').getAttribute('aria-sort')).toBe('ascending');
                expect(price().classList.contains('asc')).toBe(true);

                price().click();
                await flush();
                expect(lastParams()).toEqual({ page: 1, sort: 'price', direction: 'desc' });
                expect(headerOf('Price').getAttribute('aria-sort')).toBe('descending');
                expect(price().classList.contains('desc')).toBe(true);

                price().click();
                await flush();
                expect(lastParams()).toEqual({ page: 1 });
                expect(headerOf('Price').getAttribute('aria-sort')).toBe('none');
            });

            it('sorts by the date an item was added or changed, from the headers or from the select', async () => {
                await open();

                headerOf('Last modified').querySelector('button').click();
                await flush();

                expect(lastParams()).toEqual({ page: 1, sort: 'updated_at' });

                const select = document.querySelector('#item-sort');

                expect([...select.options].map((option) => option.textContent).slice(0, 4)).toEqual([
                    'Newest first',
                    'Date added',
                    'Last modified',
                    'Name',
                ]);
                expect(select.value).toBe('updated_at');

                await choose(select, 'price');
                expect(lastParams()).toEqual({ page: 1, sort: 'price' });

                await press('Ascending order');
                expect(lastParams()).toEqual({ page: 1, sort: 'price', direction: 'desc' });
                expect(buttons('Descending order')).toHaveLength(1);
            });

            it('shows the dates an item was added and last changed', async () => {
                await open({ items: [STORED] });

                const [added, changed] = [...bodyRows()[0].querySelectorAll('td.text-secondary')];

                expect(added.textContent).toMatch(/Oct 15, 2026/);
                expect(changed.textContent).toMatch(/Oct 16, 2026/);
            });

            it('shows the totals of the numbers and the amounts under their columns', async () => {
                await open({ items: [STORED], meta: { totals: TOTALS } });

                const foot = document.querySelector('tfoot');
                const cells = [...foot.querySelectorAll('td')];

                expect(visible(foot)).toBe(true);
                expect(cells[0].textContent.replace(/\s+/g, ' ').trim()).toBe('Total');
                expect(cells[1].textContent.trim()).toBe('4.00 kg');
                expect(cells[2].textContent.trim()).toBe('€29.90');
                expect(cells[3].textContent.trim()).toBe('');
                expect(cells[0].textContent).not.toContain('kg');
            });

            it('calls them the total of the selection when the list is narrowed down, and keeps them right as items come and go', async () => {
                window.history.replaceState(null, '', '/?q=saw');
                await open({ items: [STORED], itemsCount: 3, meta: { totals: TOTALS } });

                expect(document.querySelector('tfoot td').textContent.replace(/\s+/g, ' ').trim()).toBe(
                    'Selection total',
                );

                http.delete.mockResolvedValue(null);
                http.get.mockImplementation(async (url) =>
                    url === '/api/v1/inventories/tools'
                        ? { data: { id: 'tools', name: 'Tools', items_count: 2, fields: FIELDS } }
                        : itemsPage([STORED], { totals: { qty: '1.00', price: '5.00' } }),
                );
                bodyRows()[0].querySelector('td').click();
                await flush();
                await press('Delete', itemDialog().querySelector('.modal-footer'));
                await press('Delete the item');

                expect([...document.querySelectorAll('tfoot td')][2].textContent.trim()).toBe('€5.00');
            });

            it('leaves the foot out when no column adds up', async () => {
                await open({ fields: [FIELDS[0], FIELDS[3]], items: [STORED] });

                expect(visible(document.querySelector('tfoot'))).toBe(false);
            });

            describe('the filters', () => {
                beforeEach(async () => {
                    await open({ items: [STORED], itemsCount: 3 });
                    await openPanel();
                });

                /** Opens the panel with the button of the toolbar. */
                async function openPanel() {
                    filtersButton().click();
                    await flush();
                }

                const row = (name) =>
                    [...panel().querySelectorAll('fieldset')].find(
                        (one) => one.querySelector('legend').textContent === name,
                    );

                it('opens a panel with a row for each field, whose controls follow its kind', () => {
                    expect(showOffcanvas).toHaveBeenCalledWith(panel());
                    expect([...panel().querySelectorAll('legend')].map((legend) => legend.textContent)).toEqual(
                        FIELDS.map((field) => field.name),
                    );
                    // A number: a lowest and a highest value.
                    expect(row('Price').querySelectorAll('input').length).toBe(2);
                    expect(row('Price').querySelector('input').getAttribute('inputmode')).toBe('decimal');
                    expect(row('Bought').querySelector('input').getAttribute('type')).toBe('date');
                    // A yes / no: an answer. A choice: the choices to tick. Text: only whether it holds a value.
                    expect([...row('Fragile').querySelectorAll('option')].map((option) => option.textContent)).toEqual([
                        'Any',
                        'Yes',
                        'No',
                        'Any value',
                        'Empty',
                        'Not empty',
                    ]);
                    expect(
                        [...row('State').querySelectorAll('.form-check-label')].map((label) => label.textContent),
                    ).toEqual(['New', 'Used']);
                    expect([...row('Parts').querySelectorAll('.form-check-label')]).toHaveLength(2);
                    expect(row('Name').querySelectorAll('input')).toHaveLength(0);
                    expect([...row('Name').querySelectorAll('option')].map((option) => option.textContent)).toEqual([
                        'Any value',
                        'Empty',
                        'Not empty',
                    ]);
                });

                it('applies what was typed, shows each filter as a chip with a count on the button, and closes the panel', async () => {
                    const [min, max] = row('Price').querySelectorAll('input');

                    await type(min, '89,90');
                    await type(max, '120');
                    await tick(row('State').querySelector('input[type="checkbox"]'), true);
                    await submit(panel().querySelector('form'));

                    expect(lastParams()).toEqual({
                        page: 1,
                        'filter[price][min]': '89.90',
                        'filter[price][max]': '120',
                        'filter[state][in][0]': 'aaaaaaaa',
                        ...ASK_TOTALS,
                    });
                    expect(hideOffcanvas).toHaveBeenCalledWith(panel());

                    const chips = [...document.querySelectorAll('.badge .btn-close')].map((close) =>
                        close.parentElement.textContent.trim(),
                    );

                    expect(chips).toEqual(['Price: €89.90 – €120.00', 'State: New']);
                    expect(document.querySelector('button[aria-haspopup="dialog"] .badge').textContent).toBe('2');
                    expect(visible(document.querySelector('button[aria-haspopup="dialog"] .badge'))).toBe(true);
                });

                it('says what is wrong with a box, under it, and applies nothing', async () => {
                    http.get.mockClear();
                    const [min] = row('Price').querySelectorAll('input');

                    await type(min, 'cheap');
                    await submit(panel().querySelector('form'));

                    expect(http.get).not.toHaveBeenCalled();
                    expect(hideOffcanvas).not.toHaveBeenCalled();
                    expect(min.classList.contains('is-invalid')).toBe(true);
                    expect(min.parentElement.querySelector('.invalid-feedback').textContent).toBe(
                        'Enter a number with at most 6 decimals.',
                    );
                });

                it('links the message under a refused box to the box, and takes the link away once it is typed again', async () => {
                    const [min, max] = row('Price').querySelectorAll('input');

                    await type(min, '1000000000000000');
                    await submit(panel().querySelector('form'));

                    const feedback = min.parentElement.querySelector('.invalid-feedback');

                    expect(feedback.id).not.toBe('');
                    expect(min.getAttribute('aria-invalid')).toBe('true');
                    expect(min.getAttribute('aria-describedby')).toBe(feedback.id);
                    expect(document.getElementById(feedback.id).textContent).toBe(
                        'Enter a number with at most 15 digits before the decimal sign.',
                    );
                    expect(max.hasAttribute('aria-invalid')).toBe(false);
                    expect(max.hasAttribute('aria-describedby')).toBe(false);

                    await type(min, '5');

                    expect(min.hasAttribute('aria-invalid')).toBe(false);
                    expect(min.hasAttribute('aria-describedby')).toBe(false);
                });

                it('disables the boxes of a value for a field that is asked to hold none', async () => {
                    const [min, max] = row('Price').querySelectorAll('input');

                    await choose(row('Price').querySelector('select'), 'empty');

                    expect(min.disabled).toBe(true);
                    expect(max.disabled).toBe(true);

                    await submit(panel().querySelector('form'));

                    expect(lastParams()).toEqual({ page: 1, 'filter[price][empty]': 'true', ...ASK_TOTALS });
                });

                it('removes a filter with the close button of its chip, or all of them with Reset', async () => {
                    await choose(row('Fragile').querySelector('select'), 'true');
                    await choose(row('Name').querySelector('select'), 'filled');
                    await submit(panel().querySelector('form'));

                    expect(lastParams()).toEqual({
                        page: 1,
                        'filter[name][empty]': 'false',
                        'filter[fragile][is]': 'true',
                        ...ASK_TOTALS,
                    });

                    await press('Remove the filter Name: not empty');

                    expect(lastParams()).toEqual({ page: 1, 'filter[fragile][is]': 'true', ...ASK_TOTALS });

                    await openPanel();
                    await press('Reset');

                    expect(lastParams()).toEqual({ page: 1, ...ASK_TOTALS });
                    expect(document.querySelectorAll('.badge .btn-close')).toHaveLength(0);
                });

                it('puts the cursor on the next chip when one is removed, and on the button of the filters after the last', async () => {
                    await choose(row('Fragile').querySelector('select'), 'true');
                    await choose(row('Name').querySelector('select'), 'filled');
                    await submit(panel().querySelector('form'));
                    const closer = (label) => document.querySelector(`.badge .btn-close[aria-label="${label}"]`);

                    await press('Remove the filter Name: not empty');

                    // The list was read again after the chip it came from was taken out of the page, and shown.
                    expect(visible(closer('Remove the filter Fragile: Yes'))).toBe(true);
                    expect(bodyRows()).toHaveLength(1);
                    expect(document.activeElement).toBe(closer('Remove the filter Fragile: Yes'));

                    await press('Remove the filter Fragile: Yes');

                    expect(visible(filtersButton())).toBe(true);
                    expect(bodyRows()).toHaveLength(1);
                    expect(document.activeElement).toBe(filtersButton());
                });

                it('lets a chip wrap, so that a long name never pushes the page sideways, and keeps its close button whole', async () => {
                    await choose(row('Fragile').querySelector('select'), 'true');
                    await submit(panel().querySelector('form'));

                    const chip = document.querySelector('.badge .btn-close').parentElement;

                    expect(chip.classList.contains('text-wrap')).toBe(true);
                    expect(chip.classList.contains('text-break')).toBe(true);
                    expect(chip.classList.contains('mw-100')).toBe(true);
                    expect(document.querySelector('.badge .btn-close').classList.contains('flex-shrink-0')).toBe(true);
                });

                it('brings the filters of the address back into the boxes of the panel', async () => {
                    window.history.replaceState(
                        null,
                        '',
                        `/?filter[${ulid('price')}][min]=89.90&filter[${ulid('state')}][in][]=bbbbbbbb`,
                    );
                    await open({ fields: withUlids(['price', 'state'], FIELDS), items: [STORED], itemsCount: 3 });
                    await openPanel();

                    expect(row('Price').querySelector('input').value).toBe('89.90');
                    expect(row('State').querySelectorAll('input[type="checkbox"]')[1].checked).toBe(true);
                    expect(row('State').querySelectorAll('input[type="checkbox"]')[0].checked).toBe(false);
                });
            });

            describe('when nothing matches', () => {
                beforeEach(async () => {
                    window.history.replaceState(null, '', `/?q=zzz&filter[${ulid('price')}][min]=5`);
                    await open({
                        fields: withUlids(['price'], FIELDS),
                        items: [],
                        itemsCount: 3,
                        meta: { total: 0, from: null, to: null },
                    });
                });

                it('says so, apart from an empty inventory, and offers to clear the search and the filters', async () => {
                    const empty = [...document.querySelectorAll('.empty')].filter((element) => visible(element));

                    expect(empty).toHaveLength(1);
                    expect(empty[0].textContent).toContain('No result');
                    expect(empty[0].textContent).not.toContain('No item yet');
                    expect(visible(document.querySelector('table').parentElement)).toBe(false);
                    expect(visible(searchBox())).toBe(true);
                    expect(searchBox().value).toBe('zzz');

                    http.get.mockImplementation(async (url) =>
                        url === '/api/v1/inventories/tools'
                            ? { data: { id: 'tools', name: 'Tools', items_count: 3, fields: FIELDS } }
                            : itemsPage([STORED]),
                    );
                    expect(document.querySelector('.badge .btn-close')).not.toBeNull();
                    await press('Clear the search and the filters');

                    expect(lastParams()).toEqual({ page: 1, 'totals[0]': 'qty', 'totals[1]': ulid('price') });
                    expect(searchBox().value).toBe('');
                    // The button that was pressed went away with the message: the cursor is in the search box.
                    expect(document.activeElement).toBe(searchBox());
                    expect(bodyRows()).toHaveLength(1);
                    expect([...document.querySelectorAll('.empty')].filter((element) => visible(element))).toHaveLength(
                        0,
                    );
                    expect(window.location.search).toBe('');
                });
            });

            it('takes out of the view what the address asks for and the server refuses, and keeps the rest', async () => {
                window.history.replaceState(null, '', '/?sort=gone&q=saw');
                http.get.mockImplementation(async (url, options) => {
                    if (url === '/api/v1/inventories/tools') {
                        return { data: { id: 'tools', name: 'Tools', items_count: 1, fields: FIELDS } };
                    }

                    if (options.params.sort === 'gone') {
                        throw new HttpError(422, 'Invalid', { errors: { sort: ['This field does not exist.'] } });
                    }

                    return itemsPage([STORED]);
                });
                await mount('app/inventory.blade.php');

                expect(notify).toHaveBeenCalledWith(
                    'warning',
                    'Some of the search options no longer apply: they were removed.',
                );
                expect(bodyRows()).toHaveLength(1);
                expect(searchBox().value).toBe('saw');
                expect(window.location.search).toBe('?q=saw');
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
                // A color picker cannot be empty, so a field without a color offers to set one instead.
                expect(control('tint').tagName).toBe('BUTTON');
                expect(control('tint').textContent.trim()).toBe('Set a color');
                expect(visible(control('tint-picker'))).toBe(false);
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
                await press('Set a color', itemDialog());

                expect(visible(control('tint-picker'))).toBe(true);
                expect(control('tint-picker').value).toBe('#000000');

                await type(control('tint-picker'), '#ff0000');
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
                expect(control('tint-picker').value).toBe('#aabbcc');
                expect(visible(control('tint-picker'))).toBe(true);
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
                // The question is announced, and the cursor is on the button that answers it.
                expect(itemDialog().querySelector('.modal-body p[role="alert"]').textContent).toContain(
                    'Delete this item?',
                );
                expect(document.activeElement.textContent.trim()).toBe('Delete the item');
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

        describe('editing a cell in place', () => {
            const cellButton = (rowIndex, columnIndex) =>
                cellsOf(bodyRows()[rowIndex])[columnIndex].querySelector('button.app-cell-button');
            const editor = () => document.querySelector('#item-inline-editor');
            const PRICE = 2;
            const NAME = 0;
            const STATE = 3;
            const key = (name, eventKey) =>
                name.dispatchEvent(new KeyboardEvent('keydown', { key: eventKey, bubbles: true, cancelable: true }));

            beforeEach(async () => {
                await open({ items: [HAMMER, TRICKY] });
            });

            it('gives each cell of an editor a button that says what it edits and what it holds', () => {
                expect(cellButton(0, NAME).getAttribute('aria-label')).toBe('Edit Name: Hammer');
                expect(cellButton(0, PRICE).getAttribute('aria-label')).toBe('Edit Price: €19.90');
                expect(cellButton(0, STATE).getAttribute('aria-label')).toBe('Edit State: New');
                expect(cellButton(1, 1).getAttribute('aria-label')).toBe('Edit Weight: Not set');
                expect(cellButton(0, NAME).type).toBe('button');
                expect(cellButton(0, NAME).textContent.trim()).toBe('Hammer');
            });

            it('puts the web address beside the button of its cell, never inside it', () => {
                const site = cellsOf(bodyRows()[0])[7];

                expect(site.querySelector('button a')).toBeNull();
                expect(site.querySelector('button').textContent.trim()).toBe('https://example.com/hammer');

                const link = site.querySelector('a');

                expect(link.getAttribute('href')).toBe('https://example.com/hammer');
                expect(link.getAttribute('rel')).toBe('noopener noreferrer nofollow');
                expect(link.getAttribute('aria-label')).toBe('Open the link of Site');
                expect(cellsOf(bodyRows()[1])[7].querySelector('a')).toBeNull();
            });

            it('turns the cell into a box with the cursor in it, holding the value, when its button is pressed', async () => {
                cellButton(0, PRICE).click();
                await flush();

                expect(editor().tagName).toBe('INPUT');
                expect(editor().value).toBe('19.90');
                expect(editor().getAttribute('aria-label')).toBe('Price');
                expect(editor().getAttribute('inputmode')).toBe('decimal');
                expect(document.activeElement).toBe(editor());
                expect(cellButton(0, PRICE)).toBeNull();
                expect(showModal).not.toHaveBeenCalled();
            });

            it('saves the value on Enter, shows the item as the answer has it, and puts the cursor back on the cell', async () => {
                http.patch.mockResolvedValue({ data: { ...HAMMER, values: { ...HAMMER.values, price: '25.50' } } });
                cellButton(0, PRICE).click();
                await flush();

                await type(editor(), '25,50');
                key(editor(), 'Enter');
                await flush();

                expect(http.patch).toHaveBeenCalledTimes(1);
                expect(http.patch).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i1', {
                    values: { price: '25.50' },
                });
                expect(editor()).toBeNull();
                expect(cellButton(0, PRICE).textContent.trim()).toBe('€25.50');
                expect(document.activeElement).toBe(cellButton(0, PRICE));
            });

            it('gives up on Escape: nothing is sent, the value is as it was, the cursor is back on the cell', async () => {
                cellButton(0, PRICE).click();
                await flush();

                await type(editor(), '99');
                key(editor(), 'Escape');
                await flush();

                expect(http.patch).not.toHaveBeenCalled();
                expect(editor()).toBeNull();
                expect(cellButton(0, PRICE).textContent.trim()).toBe('€19.90');
                expect(document.activeElement).toBe(cellButton(0, PRICE));
            });

            it('saves when the cursor leaves the box, and leaves the cursor where the person put it', async () => {
                http.patch.mockResolvedValue({ data: { ...HAMMER, values: { ...HAMMER.values, name: 'Big hammer' } } });
                cellButton(0, NAME).click();
                await flush();

                await type(editor(), 'Big hammer');
                // Tab: the browser moves the cursor to the next control, which blurs the box.
                cellButton(0, PRICE).focus();
                await flush();

                expect(http.patch).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i1', {
                    values: { name: 'Big hammer' },
                });
                expect(editor()).toBeNull();
                expect(cellButton(0, NAME).textContent.trim()).toBe('Big hammer');
                expect(document.activeElement).toBe(cellButton(0, PRICE));
            });

            it('sends nothing when the cursor leaves a box that was not changed', async () => {
                cellButton(0, NAME).click();
                await flush();

                cellButton(0, PRICE).focus();
                await flush();

                expect(http.patch).not.toHaveBeenCalled();
                expect(editor()).toBeNull();
            });

            it('clears a value that is emptied', async () => {
                http.patch.mockResolvedValue({ data: { ...HAMMER, values: { ...HAMMER.values, price: undefined } } });
                cellButton(0, PRICE).click();
                await flush();

                await type(editor(), '');
                key(editor(), 'Enter');
                await flush();

                expect(http.patch).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i1', {
                    values: { price: null },
                });
                expect(cellButton(0, PRICE).textContent.trim()).toBe('—');
            });

            it('edits a choice and a yes / no in a select', async () => {
                http.patch.mockResolvedValue({ data: { ...HAMMER, values: { ...HAMMER.values, state: 'bbbbbbbb' } } });
                cellButton(0, STATE).click();
                await flush();

                expect(editor().tagName).toBe('SELECT');
                expect([...editor().options].map((option) => option.textContent)).toEqual(['Not set', 'New', 'Used']);
                expect(editor().value).toBe('aaaaaaaa');

                await choose(editor(), 'bbbbbbbb');
                key(editor(), 'Enter');
                await flush();

                expect(http.patch).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i1', {
                    values: { state: 'bbbbbbbb' },
                });
                expect(cellButton(0, STATE).textContent.trim()).toBe('Used');
            });

            it('shows what the server refuses under the box, which stays for the value to be fixed', async () => {
                http.patch.mockRejectedValueOnce(
                    new HttpError(422, 'Invalid', { errors: { 'values.price': ['The price must be a number.'] } }),
                );
                cellButton(0, PRICE).click();
                await flush();

                await type(editor(), 'abc');
                key(editor(), 'Enter');
                await flush();

                expect(editor().classList.contains('is-invalid')).toBe(true);
                expect(editor().getAttribute('aria-invalid')).toBe('true');
                expect(editor().getAttribute('aria-describedby')).toBe('item-inline-error');
                expect(document.getElementById('item-inline-error').textContent).toBe('The price must be a number.');
                expect(editor().disabled).toBe(false);
                expect(editor().closest('td').querySelector('.text-danger').textContent).toBe(
                    'The price must be a number.',
                );
                expect(visible(editor().closest('td').querySelector('.text-danger'))).toBe(true);
                expect(document.activeElement).toBe(editor());

                http.patch.mockResolvedValueOnce({ data: { ...HAMMER, values: { ...HAMMER.values, price: '12.00' } } });
                await type(editor(), '12');

                expect(editor().classList.contains('is-invalid')).toBe(false);
                expect(editor().hasAttribute('aria-describedby')).toBe(false);

                key(editor(), 'Enter');
                await flush();

                expect(editor()).toBeNull();
                expect(cellButton(0, PRICE).textContent.trim()).toBe('€12.00');
            });

            it('says an item is gone with a toast, and reads the list again', async () => {
                http.patch.mockRejectedValueOnce(new HttpError(404, 'This item no longer exists.'));
                http.get.mockClear();
                cellButton(0, NAME).click();
                await flush();

                await type(editor(), 'Big hammer');
                key(editor(), 'Enter');
                await flush();

                expect(notify).toHaveBeenCalledWith('warning', 'This item no longer exists.');
                expect(editor()).toBeNull();
                expect(http.get).toHaveBeenCalledWith('/api/v1/inventories/tools/items', { params: { page: 1 } });
            });

            it('reads the totals again when an amount is changed, without replacing the rows', async () => {
                await open({ items: [HAMMER, TRICKY], meta: { totals: { qty: '1.50', price: '19.90' } } });
                const rowsBefore = bodyRows();
                http.patch.mockResolvedValue({ data: { ...HAMMER, values: { ...HAMMER.values, price: '30.00' } } });
                http.get.mockClear();
                http.get.mockResolvedValue(itemsPage([TRICKY, HAMMER], { totals: { qty: '1.50', price: '30.00' } }));
                cellButton(0, PRICE).click();
                await flush();

                await type(editor(), '30');
                key(editor(), 'Enter');
                await flush();

                expect(http.get).toHaveBeenCalledTimes(1);
                expect(document.querySelector('tfoot').textContent).toContain('€30.00');
                // The row of the item that was changed is still the first, whatever order the list is in now.
                expect(cellButton(0, NAME).textContent.trim()).toBe('Hammer');
                expect(bodyRows()).toHaveLength(rowsBefore.length);
            });

            it('edits one cell at a time', async () => {
                cellButton(0, NAME).click();
                await flush();
                cellButton(0, PRICE).click();
                await flush();

                expect(document.querySelectorAll('#item-inline-editor')).toHaveLength(1);
                expect(editor().value).toBe('19.90');
                expect(cellButton(0, NAME)).not.toBeNull();
            });

            it('does not open the dialog when a cell is pressed, nor when its editor is clicked', async () => {
                cellButton(0, NAME).click();
                await flush();
                editor().click();
                await flush();

                expect(showModal).not.toHaveBeenCalled();
            });

            it('still opens the dialog from the padding of the row and from the button of the row', async () => {
                bodyRows()[0].querySelector('td').click();
                await flush();

                expect(showModal).toHaveBeenCalledWith(itemDialog());
            });

            it.each([
                ['labels', 8, 'tags'],
                ['parts', 4, 'several choices'],
                ['grade', 9, 'a rating'],
                ['tint', 10, 'a color'],
            ])(
                'opens the dialog with the cursor on the field for %s (%s), which a cell cannot hold',
                async (id, column) => {
                    cellButton(0, column).click();
                    await flush();

                    expect(editor()).toBeNull();
                    expect(showModal).toHaveBeenCalledTimes(1);
                    expect(showModal.mock.calls[0][0]).toBe(itemDialog());
                    expect(itemDialog().querySelector('#item-modal-title').textContent).toBe('Edit the item');

                    const [first, second] = showModal.mock.calls[0][1].focus;
                    const wanted = itemDialog().querySelector(first) ?? itemDialog().querySelector(second);

                    expect(wanted).not.toBeNull();
                    expect(wanted.closest('[data-field]').getAttribute('data-field')).toBe(id);
                },
            );

            it('opens the dialog on the text of a long note too', async () => {
                const notes = {
                    id: 'notes',
                    name: 'Notes',
                    type: 'textarea',
                    options: {},
                    required: false,
                    position: 0,
                };
                await open({ fields: [notes], items: [{ id: 'i7', values: { notes: 'Line one\nLine two' } }] });

                cellsOf(bodyRows()[0])[0].querySelector('button').click();
                await flush();

                expect(document.querySelector('#item-inline-editor')).toBeNull();
                expect(showModal.mock.calls[0][1].focus[0]).toContain('[data-field="notes"]');
                expect(itemDialog().querySelector('[data-field="notes"] textarea')).not.toBeNull();
            });
        });

        describe('the gallery', () => {
            const cards = () => [...document.querySelectorAll('.row-cards > div > .card')];
            const galleryBody = () => document.querySelector('.row-cards').closest('.card-body');
            const viewButton = (label) => document.querySelector(`.btn-group button[aria-label="${label}"]`);
            const titleOf = (card) => card.querySelector('.card-title button');

            beforeEach(async () => {
                await open({ items: [HAMMER, TRICKY], meta: { totals: { qty: '1.50', price: '19.90' } } });
            });

            it('offers the table and the gallery as two buttons, the table being pressed on a wide screen', () => {
                expect(viewButton('Table').getAttribute('aria-pressed')).toBe('true');
                expect(viewButton('Gallery').getAttribute('aria-pressed')).toBe('false');
                expect(viewButton('Table').classList.contains('active')).toBe(true);
                expect(document.querySelector('.btn-group').getAttribute('aria-label')).toBe('View');
                expect(visible(document.querySelector('.table-responsive'))).toBe(true);
                expect(visible(galleryBody())).toBe(false);
            });

            it('swaps the table for responsive cards, one per item, when the gallery is chosen', async () => {
                viewButton('Gallery').click();
                await flush();

                expect(viewButton('Gallery').getAttribute('aria-pressed')).toBe('true');
                expect(viewButton('Table').getAttribute('aria-pressed')).toBe('false');
                expect(visible(document.querySelector('.table-responsive'))).toBe(false);
                expect(visible(galleryBody())).toBe(true);
                expect(cards()).toHaveLength(2);
                expect(cards()[0].parentElement.className).toBe('col-12 col-sm-6 col-lg-4 col-xl-3');
            });

            it('calls a card by the first text the item holds, and says "Untitled item" when it holds none', async () => {
                viewButton('Gallery').click();
                await flush();

                expect(titleOf(cards()[0]).textContent).toBe('Hammer');
                expect(titleOf(cards()[0]).tagName).toBe('BUTTON');
                expect(titleOf(cards()[0]).getAttribute('aria-label')).toBe('Edit the item: Hammer');

                await open({ items: [{ id: 'i9', values: { qty: '2.00' } }] });
                viewButton('Gallery').click();
                await flush();

                expect(titleOf(cards()[0]).textContent).toBe('Untitled item');
            });

            it('shows five other values with their labels, as the table shows them', async () => {
                viewButton('Gallery').click();
                await flush();

                const labels = [...cards()[0].querySelectorAll('dt')].map((label) => label.textContent);
                const values = [...cards()[0].querySelectorAll('dd')].map((value) => value.textContent.trim());

                expect(labels).toEqual(['Weight', 'Price', 'State', 'Parts', 'Fragile']);
                expect(values[0]).toBe('1.50 kg');
                expect(values[1]).toBe('€19.90');
                expect(cards()[0].querySelector('dd .badge').textContent).toBe('New');
            });

            it('shows what was typed as text, never as markup', async () => {
                viewButton('Gallery').click();
                await flush();

                expect(document.querySelector('.row-cards img')).toBeNull();
                expect(window.pwned).toBeUndefined();
                expect(titleOf(cards()[1]).textContent).toBe('<img src=x onerror="window.pwned = true">');
            });

            it('opens the form of the item when the name or the rest of the card is pressed', async () => {
                viewButton('Gallery').click();
                await flush();

                titleOf(cards()[0]).click();
                await flush();

                expect(showModal).toHaveBeenCalledTimes(1);
                expect(showModal).toHaveBeenLastCalledWith(itemDialog());
                expect(itemDialog().querySelector('#item-modal-title').textContent).toBe('Edit the item');
                expect(control('name').value).toBe('Hammer');

                cards()[1].querySelector('.card-body').click();
                await flush();

                expect(showModal).toHaveBeenCalledTimes(2);
                expect(control('name').value).toBe('<img src=x onerror="window.pwned = true">');
            });

            it('does not open the form when a link of the card is clicked', async () => {
                const fields = [FIELDS[0], FIELDS[7]];
                await open({ fields, items: [{ id: 'i5', values: { name: 'Saw', site: 'https://example.com/saw' } }] });
                viewButton('Gallery').click();
                await flush();

                const link = cards()[0].querySelector('dd a');

                expect(link.getAttribute('href')).toBe('https://example.com/saw');
                expect(link.getAttribute('rel')).toBe('noopener noreferrer nofollow');

                link.click();
                await flush();

                expect(showModal).not.toHaveBeenCalled();
            });

            it('shows the totals as a line above the cards', async () => {
                viewButton('Gallery').click();
                await flush();

                const line = galleryBody().querySelector('.mb-3');

                expect(visible(line)).toBe(true);
                expect(line.textContent.replace(/\s+/g, ' ').trim()).toBe('Total Weight 1.50 kg Price €19.90');
            });

            it('keeps the toolbar, with the sort select and its direction on show, and the count and the pagination', async () => {
                const sort = document.querySelector('#item-sort').closest('.col-12');

                expect(sort.className).toContain('d-sm-none');

                viewButton('Gallery').click();
                await flush();

                expect(sort.className).not.toContain('d-sm-none');
                expect(visible(document.querySelector('input[type="search"]'))).toBe(true);
                expect(document.body.textContent).toContain('2 items');
                expect(visible(document.querySelector('.card-footer'))).toBe(true);
            });

            it('keeps the choice for the inventory and writes it in the address', async () => {
                viewButton('Gallery').click();
                await flush();

                expect(window.localStorage.getItem('inventaire.items.view:/api/v1/inventories/tools')).toBe('gallery');
                expect(window.location.search).toBe('?view=gallery');

                viewButton('Table').click();
                await flush();

                expect(visible(document.querySelector('.table-responsive'))).toBe(true);
                expect(window.location.search).toBe('?view=table');
            });

            it('opens in the gallery when the address says so, or the choice made last, or the screen is a phone', async () => {
                window.history.replaceState(null, '', '/?view=gallery');
                await open({ items: [HAMMER] });
                expect(viewButton('Gallery').getAttribute('aria-pressed')).toBe('true');

                window.history.replaceState(null, '', '/');
                window.localStorage.setItem('inventaire.items.view:/api/v1/inventories/tools', 'gallery');
                await open({ items: [HAMMER] });
                expect(viewButton('Gallery').getAttribute('aria-pressed')).toBe('true');

                window.localStorage.clear();
                window.matchMedia = () => ({ matches: true });
                await open({ items: [HAMMER] });
                delete window.matchMedia;
                expect(viewButton('Gallery').getAttribute('aria-pressed')).toBe('true');
                expect(visible(galleryBody())).toBe(true);
            });

            it('still works when the browser refuses to keep the choice', async () => {
                useRefusingStorage();

                viewButton('Gallery').click();
                await flush();

                expect(visible(galleryBody())).toBe(true);
            });

            it('is the same page of the same items for a viewer, whose cards open the details', async () => {
                PAGE.abilities = { edit_items: false };
                await open({ items: [HAMMER] });
                viewButton('Gallery').click();
                await flush();

                titleOf(cards()[0]).click();
                await flush();

                expect(itemDialog().querySelector('#item-modal-title').textContent).toBe('Item');
                expect(itemDialog().querySelector('dl')).not.toBeNull();
            });

            it('is reached with the keyboard: the name of a card is a button that opens the item', async () => {
                viewButton('Gallery').click();
                await flush();

                titleOf(cards()[0]).focus();

                expect(document.activeElement).toBe(titleOf(cards()[0]));
                expect(titleOf(cards()[0]).type).toBe('button');

                // A button is pressed by Enter and Space: the browser turns either into a click.
                titleOf(cards()[0]).click();
                await flush();

                expect(showModal).toHaveBeenCalledTimes(1);
            });
        });

        describe('reading an item as a viewer', () => {
            beforeEach(async () => {
                PAGE.abilities = { edit_items: false };
                await open({ items: [HAMMER] });
            });

            it('shows a long text whole in the details, with its line breaks, and cut to one line in the table', async () => {
                const long = 'Line one\nLine two ' + 'x'.repeat(100);
                const notes = {
                    id: 'notes',
                    name: 'Notes',
                    type: 'textarea',
                    options: {},
                    required: false,
                    position: 0,
                };
                await open({ fields: [notes], items: [{ id: 'i7', values: { notes: long } }] });

                expect(cellsOf(bodyRows()[0])[0].textContent.trim().endsWith('…')).toBe(true);
                expect(cellsOf(bodyRows()[0])[0].textContent).not.toContain(
                    'Line two xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                );

                bodyRows()[0].querySelector('td').click();
                await flush();

                const detail = itemDialog().querySelector('dl dd span');

                expect(detail.textContent).toBe(long);
                expect(detail.classList.contains('app-multiline')).toBe(true);
            });

            it('gives no button, no box and no link beside the value to a person who may not edit', async () => {
                expect(bodyRows()[0].querySelectorAll('button.app-cell-button')).toHaveLength(0);
                expect(bodyRows()[0].querySelectorAll('td.app-cell button')).toHaveLength(0);

                const site = cellsOf(bodyRows()[0])[7];

                // The address is a link of its own, as it always was.
                expect(site.querySelector('a').getAttribute('href')).toBe('https://example.com/hammer');
                expect(site.querySelector('a').getAttribute('aria-label')).toBeNull();
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

        describe('exporting the items', () => {
            const EXPORTS = '/api/v1/inventories/tools/exports';
            const exportDialog = () => document.querySelector('#export-modal-title').closest('.modal');
            const radios = (name) => [...exportDialog().querySelectorAll(`input[name="export-${name}"]`)];
            const radio = (name, value) => radios(name).find((one) => one.value === value);
            const separator = () => exportDialog().querySelector('#export-delimiter');
            const startButton = () => buttons('Start the export', exportDialog())[0];
            const footerAlert = () => exportDialog().querySelector('.modal-footer [role="alert"]');
            const rowsOf = () => [...exportDialog().querySelectorAll('.list-group-item')];
            const searchBox = () => document.querySelector('input[type="search"]');

            /** An export as the API describes it: finished unless the test says otherwise. */
            function record(id, extra = {}) {
                return {
                    id,
                    format: 'xlsx',
                    scope: 'all',
                    status: 'completed',
                    error: null,
                    file_name: `tools-${id}.xlsx`,
                    size: 2048,
                    items_count: 2,
                    created_at: '2026-10-15T10:00:00Z',
                    completed_at: '2026-10-15T10:00:05Z',
                    expires_at: '2026-10-16T10:00:00Z',
                    download_url: `${EXPORTS}/${id}/download`,
                    ...extra,
                };
            }

            /** Opens the page, then the export dialog, which finds the given exports. */
            async function openDialog(list = []) {
                await open();

                const readItems = http.get.getMockImplementation();

                http.get.mockImplementation(async (url, options) =>
                    url === EXPORTS ? { data: list } : readItems(url, options),
                );
                await press('Export');
            }

            it('is offered by a button of the toolbar to every role, even a viewer', async () => {
                PAGE.abilities = { update: false, delete: false, manage_fields: false, edit_items: false };
                await open();

                const button = buttons('Export');

                expect(button).toHaveLength(1);
                expect(button[0].closest('.row')).not.toBeNull();
                expect(button[0].getAttribute('aria-haspopup')).toBe('dialog');
                expect(button[0].querySelector('svg')).not.toBeNull();
            });

            it('opens a dialog that reads the exports, with the cursor on the format that is chosen', async () => {
                await openDialog([record('e1')]);

                expect(showModal).toHaveBeenCalledWith(exportDialog(), {
                    focus: ['input[name="export-format"]:checked'],
                });
                expect(http.get).toHaveBeenCalledWith(EXPORTS, expect.objectContaining({ silent: false }));
                expect(exportDialog().getAttribute('aria-labelledby')).toBe('export-modal-title');
                expect(document.querySelector('#export-modal-title').textContent).toBe('Export the items');
            });

            it('offers the six formats as radio cards that say what each is good for, Excel chosen', async () => {
                await openDialog();

                expect(radios('format').map((one) => one.value)).toEqual(['xlsx', 'ods', 'csv', 'json', 'pdf', 'zip']);
                expect(radios('format').map((one) => one.checked)).toEqual([true, false, false, false, false, false]);

                const cards = [...exportDialog().querySelectorAll('.form-selectgroup-item')];

                expect(cards.map((card) => card.querySelector('strong').textContent)).toEqual([
                    'Excel (.xlsx)',
                    'OpenDocument (.ods)',
                    'CSV',
                    'JSON',
                    'PDF',
                    'Files (.zip)',
                ]);
                expect(cards[2].textContent).toContain('Plain text that any spreadsheet or program can read.');
                // A card is one label around its radio: a click anywhere on it chooses the format.
                expect(cards.every((card) => card.tagName === 'LABEL' && card.querySelector('input') !== null)).toBe(
                    true,
                );
                expect(exportDialog().querySelector('fieldset legend').textContent).toBe('Format');
            });

            it('shows the separator of a CSV file, and the files of a ZIP, only for those formats', async () => {
                await openDialog();

                expect(visible(separator())).toBe(false);
                expect(visible(radio('files', 'photos'))).toBe(false);

                await tick(radio('format', 'csv'), true);

                expect(visible(separator())).toBe(true);
                expect(visible(radio('files', 'photos'))).toBe(false);
                expect(exportDialog().querySelector('label[for="export-delimiter"]').textContent).toBe('CSV separator');
                expect([...separator().options].map((one) => one.value)).toEqual(['semicolon', 'comma', 'tab']);
                // The separator also decides the decimal sign: each choice says which one it comes with.
                expect([...separator().options].map((one) => one.textContent)).toEqual([
                    'Semicolon (Excel in French, decimals with a comma)',
                    'Comma (decimals with a point)',
                    'Tab (decimals with a point)',
                ]);

                await tick(radio('format', 'zip'), true);

                expect(visible(separator())).toBe(false);
                expect(visible(radio('files', 'photos'))).toBe(true);
                expect(radio('files', 'photos').checked).toBe(true);
                expect(radios('files').map((one) => one.labels[0].textContent.trim())).toEqual([
                    'The photos of the photo fields',
                    'All the attachments, photos and other files',
                ]);
            });

            it('does not offer the current view while nothing narrows or orders the list, and offers it afterwards', async () => {
                await openDialog();

                expect(radio('scope', 'all').checked).toBe(true);
                expect(radio('scope', 'view').disabled).toBe(true);
                expect(radio('scope', 'view').getAttribute('aria-describedby')).toBe('export-scope-view-hint');
                expect(exportDialog().querySelector('#export-scope-view-hint').textContent).toBe(
                    'Search, filter or sort the items first to export only that view.',
                );
                expect(exportDialog().querySelector('label[for="export-scope-view"]').textContent).toBe('Current view');

                // Narrow the list down, with the dialog closed (the page cannot be reached behind it).
                await type(searchBox(), 'ham');
                await new Promise((resolve) => setTimeout(resolve, 400));
                await flush();
                await press('Export');

                expect(radio('scope', 'view').disabled).toBe(false);
                expect(exportDialog().querySelector('#export-scope-view-hint').textContent).toMatch(
                    / items?, as the list/,
                );
            });

            it('asks for an export of the options chosen, and shows it at once, waiting', async () => {
                http.post.mockResolvedValue({
                    data: record('new', {
                        format: 'csv',
                        status: 'pending',
                        file_name: null,
                        size: null,
                        items_count: null,
                        completed_at: null,
                        expires_at: null,
                        download_url: null,
                    }),
                });
                await openDialog();
                await tick(radio('format', 'csv'), true);
                await choose(separator(), 'tab');

                await submit(exportDialog().querySelector('form'));

                expect(http.post).toHaveBeenCalledWith(EXPORTS, { format: 'csv', scope: 'all', delimiter: 'tab' });
                expect(rowsOf()).toHaveLength(1);
                expect(rowsOf()[0].querySelector('.fw-medium').textContent).toBe('CSV');
                expect(rowsOf()[0].querySelector('.badge').textContent).toBe('Waiting');
                expect(visible(rowsOf()[0].querySelector('a'))).toBe(false);
                expect(exportDialog().querySelector('[aria-live="polite"]').textContent).toBe(
                    'Export started. It will be ready in a moment.',
                );
                expect(visible(footerAlert())).toBe(false);
            });

            it('asks for the current view as the parameters of the list', async () => {
                http.post.mockResolvedValue({ data: record('new') });
                await open();
                await type(searchBox(), 'ham');
                await new Promise((resolve) => setTimeout(resolve, 400));
                await flush();
                await press('Export');
                await tick(radio('scope', 'view'), true);

                await submit(exportDialog().querySelector('form'));

                expect(http.post).toHaveBeenCalledWith(EXPORTS, {
                    format: 'xlsx',
                    scope: 'view',
                    query: { q: 'ham' },
                });
            });

            it('lists the exports: the file of the ones that are ready is a plain link, the others have none', async () => {
                await openDialog([
                    record('e1'),
                    record('e2', {
                        format: 'pdf',
                        scope: 'view',
                        status: 'running',
                        file_name: null,
                        size: null,
                        items_count: null,
                        download_url: null,
                    }),
                    record('e3', { status: 'failed', error: 'The export could not be made.', download_url: null }),
                ]);

                expect(rowsOf().map((row) => row.querySelector('.badge').textContent)).toEqual([
                    'Ready',
                    'In progress',
                    'Failed',
                ]);
                expect(rowsOf()[0].querySelector('.text-secondary').textContent).toContain('All items');
                expect(rowsOf()[1].querySelector('.text-secondary').textContent).toContain('Current view');
                expect(visible(rowsOf()[2].querySelector('.text-danger'))).toBe(true);
                expect(rowsOf()[2].querySelector('.text-danger').textContent).toBe('The export could not be made.');

                const [ready, running, failed] = rowsOf().map((row) => row.querySelector('a'));

                expect(visible(ready)).toBe(true);
                expect(ready.getAttribute('href')).toBe(`${EXPORTS}/e1/download`);
                expect(ready.hasAttribute('download')).toBe(true);
                expect(ready.getAttribute('aria-label')).toBe('Download tools-e1.xlsx');
                // The API recognises the web front by its Referer: the link must not hide it.
                expect(ready.hasAttribute('rel')).toBe(false);
                expect(ready.textContent.trim()).toBe('Download');
                expect(visible(running)).toBe(false);
                expect(visible(failed)).toBe(false);
            });

            it('deletes an export from its row', async () => {
                http.delete.mockResolvedValue(null);
                await openDialog([record('e1'), record('e2')]);

                const remove = rowsOf()[0].querySelector('button[data-export-delete]');

                expect(remove.textContent.trim()).toBe('Delete');
                expect(remove.getAttribute('aria-label')).toMatch(/^Delete the Excel \(\.xlsx\) export of /);

                remove.click();
                await flush();

                expect(http.delete).toHaveBeenCalledWith(`${EXPORTS}/e1`);
                expect(rowsOf()).toHaveLength(1);
                expect(rowsOf()[0].querySelector('a').getAttribute('href')).toBe(`${EXPORTS}/e2/download`);
                expect(exportDialog().querySelector('[aria-live="polite"]').textContent).toBe('Export deleted.');
            });

            it('says when there is no export yet, and when the list cannot be read', async () => {
                const empty = () =>
                    [...exportDialog().querySelectorAll('p')].find((one) =>
                        one.textContent.startsWith('No export yet.'),
                    );
                const unread = () => exportDialog().querySelector('.alert-warning');

                await openDialog([]);

                expect(visible(exportDialog().querySelector('.list-group'))).toBe(false);
                expect(visible(empty())).toBe(true);
                expect(visible(unread())).toBe(false);

                const base = http.get.getMockImplementation();

                http.get.mockImplementation(async (url, options) => {
                    if (url === EXPORTS) {
                        throw new HttpError(500, 'Down.');
                    }

                    return base(url, options);
                });
                await press('Export');

                expect(visible(unread())).toBe(true);
                expect(unread().textContent).toContain('The exports could not be loaded.');
                expect(visible(empty())).toBe(false);

                http.get.mockImplementation(async (url, options) =>
                    url === EXPORTS ? { data: [record('e1')] } : base(url, options),
                );
                await press('Try again', exportDialog());

                expect(rowsOf()).toHaveLength(1);
                expect(visible(unread())).toBe(false);
                expect(visible(empty())).toBe(false);
            });

            it('says why an export was refused, under the buttons, where it stays in view', async () => {
                http.post.mockRejectedValue(
                    new HttpError(409, 'Something went wrong.', { code: 'export_limit_reached' }),
                );
                await openDialog();

                expect(footerAlert()).not.toBeNull();
                expect(visible(footerAlert())).toBe(false);

                await submit(exportDialog().querySelector('form'));

                expect(visible(footerAlert())).toBe(true);
                expect(footerAlert().textContent).toBe(
                    'You already have too many exports of this inventory. Delete some, then try again.',
                );
                expect(startButton().disabled).toBe(false);
            });

            it('keeps a polite live region and a way to close the dialog', async () => {
                await openDialog();

                const status = exportDialog().querySelector('[role="status"]');

                expect(status.getAttribute('aria-live')).toBe('polite');
                expect(buttons('Close', exportDialog().querySelector('.modal-footer'))).toHaveLength(1);
                expect(buttons('Close', exportDialog().querySelector('.modal-header'))).toHaveLength(1);
                expect(startButton().type).toBe('submit');
            });
        });

        describe('importing items', () => {
            const IMPORTS = '/api/v1/inventories/tools/imports';
            const importDialog = () => document.querySelector('#import-modal-title').closest('.modal');
            const stepOf = (name) => importDialog().querySelector(`[data-import-step="${name}"]`);
            const columnsOf = () => [...importDialog().querySelectorAll('[data-import-column]')];
            const destination = (index) => importDialog().querySelector(`#import-destination-${index}`);
            const typeSelect = (index) => importDialog().querySelector(`#import-type-${index}`);
            const nameInput = (index) => importDialog().querySelector(`#import-name-${index}`);
            const startButton = () => buttons('Start the import', importDialog())[0];
            const footerAlert = () => importDialog().querySelector('.modal-footer [role="alert"]');
            const alertsOf = () => [...footerAlert().querySelectorAll('li')].map((one) => one.textContent);

            /** A column of the file, as the API describes it. */
            const column = (index, header, samples = [], guess = 'text') => ({ index, header, samples, guess });

            /** An import as the API describes it: waiting for a worker, unless the test says otherwise. */
            function record(id, status = 'pending', extra = {}) {
                return {
                    id,
                    format: 'csv',
                    status,
                    error: null,
                    file_name: 'tools.csv',
                    size: 2048,
                    options: {
                        has_header: true,
                        delimiter: 'semicolon',
                        encoding: 'utf-8',
                        decimal: 'comma',
                        date_order: 'dmy',
                        on_error: 'abort',
                    },
                    columns: [],
                    mapping: null,
                    rows_total: null,
                    rows_imported: null,
                    rows_failed: null,
                    fields_created: null,
                    errors: null,
                    created_at: '2026-10-15T10:00:00Z',
                    completed_at: null,
                    expires_at: null,
                    ...extra,
                };
            }

            /** An import that waits for its mapping: a name to match, a weight to match, a price to create, a date to ignore. */
            function uploaded(id = 'i1', extra = {}) {
                return record(id, 'uploaded', {
                    columns: [
                        column(0, 'Name', ['Hammer', 'Saw']),
                        column(1, 'Weight', ['1,5', '2'], 'number'),
                        column(2, 'Unit price', ['9,90 €'], 'money'),
                        column(3, 'Created on', [], 'datetime'),
                    ],
                    mapping: [
                        { column: 0, target: 'field', field: 'name' },
                        { column: 1, target: 'field', field: 'qty' },
                        { column: 2, target: 'new', type: 'money', name: 'Unit price' },
                        { column: 3, target: 'ignore' },
                    ],
                    ...extra,
                });
            }

            /** Opens the page, then the import dialog, which finds the given imports. */
            async function openDialog(list = [], pageOptions = {}) {
                await open(pageOptions);

                const readItems = http.get.getMockImplementation();

                http.get.mockImplementation(async (url, options) =>
                    url === IMPORTS ? { data: list } : readItems(url, options),
                );
                await press('Import');
            }

            /** Picks a file in the chooser of the dialog, the way a person does. */
            async function sendFile(file) {
                const input = importDialog().querySelector('#import-file');

                Object.defineProperty(input, 'files', { value: [file], configurable: true });
                input.dispatchEvent(new Event('change', { bubbles: true }));
                await flush();
            }

            const csv = (size = 64) => new File(['x'.repeat(size)], 'tools.csv', { type: 'text/csv' });

            /** Opens the dialog and sends a file that comes back as the given import. */
            async function openOnMapping(answer = uploaded()) {
                http.post.mockResolvedValue({ data: answer });
                await openDialog();
                await sendFile(csv());
            }

            it('is offered by a button of the toolbar to the people who may edit, and to nobody else', async () => {
                await open();

                const button = buttons('Import');

                expect(button).toHaveLength(1);
                expect(button[0].closest('.row')).not.toBeNull();
                expect(button[0].getAttribute('aria-haspopup')).toBe('dialog');
                expect(button[0].querySelector('svg')).not.toBeNull();

                PAGE.abilities = { update: false, delete: false, manage_fields: false, edit_items: false };
                await open();

                expect(buttons('Import')).toHaveLength(0);
            });

            it('is offered by the empty states, where the toolbar is not there', async () => {
                await open({ items: [], itemsCount: 0 });

                expect(buttons('Import')).toHaveLength(0);
                expect(buttons('Import a file')).toHaveLength(1);

                await press('Import a file');

                expect(showModal).toHaveBeenCalledWith(importDialog(), { focus: ['#import-file'] });

                await open({ fields: [], items: [], itemsCount: 0 });

                expect(buttons('Import a file')).toHaveLength(1);
                expect(buttons('Import a file')[0].closest('.empty-action')).not.toBeNull();

                PAGE.abilities = { manage_fields: false };
                await open({ fields: [], items: [], itemsCount: 0 });

                expect(buttons('Import a file')).toHaveLength(0);

                PAGE.abilities = { update: false, delete: false, manage_fields: false, edit_items: false };
                await open({ items: [], itemsCount: 0 });

                expect(buttons('Import a file')).toHaveLength(0);
            });

            it('opens a dialog that reads the imports and asks for the file, with the cursor on the chooser', async () => {
                await openDialog();

                expect(showModal).toHaveBeenCalledWith(importDialog(), { focus: ['#import-file'] });
                expect(http.get).toHaveBeenCalledWith(
                    IMPORTS,
                    expect.objectContaining({ signal: expect.any(AbortSignal) }),
                );
                expect(importDialog().getAttribute('aria-labelledby')).toBe('import-modal-title');
                expect(document.querySelector('#import-modal-title').textContent).toBe('Import items');
                expect(visible(stepOf('file'))).toBe(true);
                expect(visible(stepOf('mapping'))).toBe(false);
                expect(visible(stepOf('result'))).toBe(false);

                const input = importDialog().querySelector('#import-file');

                expect(input.type).toBe('file');
                expect(input.getAttribute('accept')).toBe('.csv,.tsv,.txt,.xlsx,.ods');
                expect(importDialog().querySelector('label[for="import-file"]').textContent).toBe('Spreadsheet file');
                expect(importDialog().querySelector('#import-file-hint').textContent).toBe(
                    'CSV, TSV, TXT, Excel (.xlsx) or OpenDocument (.ods) file, up to 3 MB.',
                );
                expect(input.getAttribute('aria-describedby')).toBe('import-file-hint');
                expect(buttons('Close', importDialog().querySelector('.modal-footer'))).toHaveLength(1);
                expect(buttons('Start the import', importDialog())).toHaveLength(0);
            });

            it('keeps a polite live region and no form, so that Enter cannot start an import', async () => {
                await openDialog();

                expect(importDialog().querySelector('[role="status"][aria-live="polite"]')).not.toBeNull();
                expect(importDialog().querySelector('form')).toBeNull();
            });

            it('sends the file as soon as it is chosen and shows one row for each column, with the proposed destination', async () => {
                await openOnMapping();

                expect(http.post).toHaveBeenCalledWith(IMPORTS, expect.any(FormData), expect.any(Object));
                expect(http.post.mock.calls[0][1].get('file').name).toBe('tools.csv');
                expect(visible(stepOf('mapping'))).toBe(true);
                expect(visible(stepOf('file'))).toBe(false);
                expect(columnsOf()).toHaveLength(4);
                expect(columnsOf().map((one) => one.querySelector('.fw-medium').textContent)).toEqual([
                    'Name',
                    'Weight',
                    'Unit price',
                    'Created on',
                ]);
                expect(columnsOf()[1].textContent).toContain('1,5 · 2');
                expect(columnsOf()[3].textContent).toContain('Empty column');
                expect([0, 1, 2, 3].map((index) => destination(index).value)).toEqual([
                    'field:name',
                    'field:qty',
                    'new',
                    'ignore',
                ]);
                expect(importDialog().querySelector('#import-columns-title').textContent).toBe('Columns of the file');
                expect(importDialog().textContent).toContain('3 columns will be imported, 1 new field will be created');
                expect(importDialog().querySelector('[role="status"]').textContent).toBe('File read: 4 columns found.');
                expect(visible(startButton())).toBe(true);
                expect(buttons('Cancel the import', importDialog())).toHaveLength(1);
            });

            it('names the controls of each column, and lists the destinations: ignore, new field, then the existing fields', async () => {
                await openOnMapping();

                expect(destination(1).getAttribute('aria-label')).toBe('Destination of the column Weight');
                expect(typeSelect(2).getAttribute('aria-label')).toBe(
                    'Type of the new field for the column Unit price',
                );
                expect(nameInput(2).getAttribute('aria-label')).toBe('Name of the new field for the column Unit price');

                const options = [...destination(0).querySelectorAll('option')].map((one) => [
                    one.value,
                    one.textContent,
                ]);

                expect(options.slice(0, 2)).toEqual([
                    ['ignore', 'Ignore this column'],
                    ['new', 'New field'],
                ]);
                expect(options.slice(2).map(([, name]) => name)).toEqual(FIELDS.map((one) => one.name));
                expect(destination(0).querySelector('optgroup').label).toBe('Existing field');
            });

            it('shows the type and the name of a field to create for the columns that create one only', async () => {
                await openOnMapping();

                expect(typeSelect(2).value).toBe('money');
                expect(nameInput(2).value).toBe('Unit price');
                expect(typeSelect(0)).toBeNull();
                expect(nameInput(1)).toBeNull();
                expect([...typeSelect(2).options].map((one) => one.value)).not.toContain('file');
                expect([...typeSelect(2).options].map((one) => one.value)).not.toContain('multiselect');
                expect(typeSelect(2).options).toHaveLength(17);

                await choose(destination(3), 'new');

                expect(typeSelect(3).value).toBe('datetime');
                expect(nameInput(3).value).toBe('Created on');

                await choose(destination(3), 'field:bought');

                expect(typeSelect(3)).toBeNull();
                expect(destination(3).value).toBe('field:bought');
            });

            it('says the choices of a field to create are made from the values of its column', async () => {
                await openOnMapping();
                const hint = () =>
                    [...columnsOf()[2].querySelectorAll('.form-hint')].find((one) =>
                        one.textContent.includes('different values'),
                    );

                expect(visible(hint())).toBe(false);

                await choose(typeSelect(2), 'select');

                expect(visible(hint())).toBe(true);
            });

            it('does not offer a new field to a person who may not create fields, nor the one the mapping proposed', async () => {
                PAGE.abilities = { manage_fields: false };
                await openOnMapping();

                expect([...destination(0).querySelectorAll('option')].map((one) => one.value)).not.toContain('new');
                expect(destination(2).value).toBe('ignore');
                expect(typeSelect(2)).toBeNull();
            });

            it('shows the separator and the encoding for a CSV file only', async () => {
                await openOnMapping();

                const separator = () => importDialog().querySelector('#import-delimiter');
                const encoding = () => importDialog().querySelector('#import-encoding');

                expect(visible(separator())).toBe(true);
                expect(visible(encoding())).toBe(true);
                expect([...separator().options].map((one) => one.value)).toEqual(['semicolon', 'comma', 'tab']);
                expect([...encoding().options].map((one) => one.value)).toEqual(['utf-8', 'windows-1252', 'utf-16']);
                expect(separator().value).toBe('semicolon');
                expect(encoding().value).toBe('utf-8');

                await openOnMapping(
                    uploaded('i2', {
                        format: 'xlsx',
                        options: {
                            has_header: true,
                            delimiter: null,
                            encoding: null,
                            decimal: 'point',
                            date_order: 'mdy',
                            on_error: 'abort',
                        },
                    }),
                );

                expect(visible(separator())).toBe(false);
                expect(visible(encoding())).toBe(false);
                expect(visible(importDialog().querySelector('#import-header'))).toBe(true);
            });

            it('shows the options of the cells the way the API read them', async () => {
                await openOnMapping();

                expect(importDialog().querySelector('#import-decimal').value).toBe('comma');
                expect(importDialog().querySelector('#import-date-order').value).toBe('dmy');
                expect(importDialog().querySelector('#import-on-error-abort').checked).toBe(true);
                expect(importDialog().querySelector('#import-on-error-skip').checked).toBe(false);
                expect(importDialog().querySelector('#import-header').checked).toBe(true);
            });

            it('reads the file again, and starts the mapping over, when the separator is changed', async () => {
                await openOnMapping();
                http.patch.mockResolvedValue({
                    data: uploaded('i1', {
                        options: { ...uploaded().options, delimiter: 'tab' },
                        columns: [column(0, 'Only')],
                        mapping: [{ column: 0, target: 'ignore' }],
                    }),
                });

                await choose(importDialog().querySelector('#import-delimiter'), 'tab');

                expect(http.patch).toHaveBeenCalledWith(
                    `${IMPORTS}/i1`,
                    { has_header: true, delimiter: 'tab', encoding: 'utf-8' },
                    { timeout: expect.any(Number) },
                );
                expect(http.patch.mock.calls[0][2].timeout).toBeGreaterThanOrEqual(120_000);
                expect(columnsOf()).toHaveLength(1);
                expect(importDialog().querySelector('#import-delimiter').value).toBe('tab');
            });

            it('reads the file again when the row of the headers is unticked, and when the encoding is changed', async () => {
                await openOnMapping();
                http.patch.mockResolvedValue({
                    data: uploaded('i1', { options: { ...uploaded().options, has_header: false } }),
                });

                await tick(importDialog().querySelector('#import-header'), false);

                expect(http.patch).toHaveBeenLastCalledWith(
                    `${IMPORTS}/i1`,
                    { has_header: false, delimiter: 'semicolon', encoding: 'utf-8' },
                    { timeout: expect.any(Number) },
                );

                await choose(importDialog().querySelector('#import-encoding'), 'windows-1252');

                expect(http.patch).toHaveBeenLastCalledWith(
                    `${IMPORTS}/i1`,
                    { has_header: false, delimiter: 'semicolon', encoding: 'windows-1252' },
                    { timeout: expect.any(Number) },
                );
            });

            it('does not read the file again for the options of the cells', async () => {
                await openOnMapping();

                await choose(importDialog().querySelector('#import-decimal'), 'point');
                await choose(importDialog().querySelector('#import-date-order'), 'ymd');
                await tick(importDialog().querySelector('#import-on-error-skip'), true);

                expect(http.patch).not.toHaveBeenCalled();
            });

            it('does not start an import whose mapping has something wrong, and says what under the column', async () => {
                await openOnMapping();
                await type(nameInput(2), '   ');

                await press('Start the import', importDialog());

                expect(http.post).toHaveBeenCalledTimes(1);
                expect(columnsOf()[2].textContent).toContain('Give the new field a name.');
                expect(nameInput(2).getAttribute('aria-invalid')).toBe('true');
                expect(nameInput(1)).toBeNull();
                expect(document.getElementById(nameInput(2).getAttribute('aria-describedby')).textContent).toBe(
                    'Give the new field a name.',
                );
                expect(visible(footerAlert())).toBe(true);
                expect(alertsOf()).toEqual(['Some columns need your attention.']);
                expect(document.activeElement).toBe(nameInput(2));

                await type(nameInput(2), 'Price');

                expect(nameInput(2).getAttribute('aria-invalid')).toBe('false');
                expect(visible(footerAlert())).toBe(false);
            });

            it('asks for a column for a field that is required', async () => {
                await openOnMapping();
                await choose(destination(0), 'ignore');

                await press('Start the import', importDialog());

                expect(visible(footerAlert())).toBe(true);
                expect(alertsOf()).toEqual(['The field Name is required: match a column with it.']);
            });

            it('starts the import with the mapping and the options that were chosen, then follows it', async () => {
                await openOnMapping();
                http.post.mockResolvedValue({ data: record('i1', 'pending') });
                await choose(destination(3), 'field:bought');
                await choose(typeSelect(2), 'percentage');
                await type(nameInput(2), 'Discount');
                await choose(importDialog().querySelector('#import-decimal'), 'point');
                await choose(importDialog().querySelector('#import-date-order'), 'mdy');
                await tick(importDialog().querySelector('#import-on-error-skip'), true);

                await press('Start the import', importDialog());

                expect(http.post).toHaveBeenLastCalledWith(`${IMPORTS}/i1/start`, {
                    mapping: [
                        { column: 0, target: 'field', field: 'name' },
                        { column: 1, target: 'field', field: 'qty' },
                        { column: 2, target: 'new', type: 'percentage', name: 'Discount' },
                        { column: 3, target: 'field', field: 'bought' },
                    ],
                    decimal: 'point',
                    date_order: 'mdy',
                    on_error: 'skip',
                });
                expect(visible(stepOf('result'))).toBe(true);
                expect(visible(stepOf('mapping'))).toBe(false);
                expect(stepOf('result').querySelector('h3').textContent).toBe('Import waiting');
                expect(stepOf('result').textContent).toContain('The import is running. You can close this window');
                expect(buttons('Start the import', importDialog())).toHaveLength(0);
                expect(buttons('Import another file', importDialog())).toHaveLength(0);
                expect(importDialog().querySelector('[role="status"]').textContent).toBe(
                    'Import started. It will be done in a moment.',
                );
            });

            it('tells what an import did, lists the rows it left out, and reads the items again', async () => {
                await openOnMapping();
                http.post.mockResolvedValue({
                    data: record('i1', 'completed', {
                        rows_imported: 10,
                        rows_failed: 2,
                        fields_created: 1,
                        errors: [
                            { row: 4, column: 'Weight', message: 'Not a number.' },
                            { row: 9, column: 'Created on', message: 'Not a date.' },
                        ],
                    }),
                });
                const reads = http.get.mock.calls.filter((call) => call[0] === '/api/v1/inventories/tools').length;

                await press('Start the import', importDialog());

                const success = stepOf('result').querySelector('.alert-success');

                expect(stepOf('result').querySelector('h3').textContent).toBe('Import finished');
                expect(visible(success)).toBe(true);
                expect([...success.querySelectorAll('li')].map((one) => one.textContent)).toEqual([
                    '10 items imported',
                    '1 field created',
                    '2 rows skipped because of errors',
                ]);
                expect(visible(stepOf('result').querySelector('.alert-danger'))).toBe(false);

                const rows = [...stepOf('result').querySelectorAll('[data-import-error]')];

                expect(rows.map((one) => [...one.children].map((cell) => cell.lastElementChild.textContent))).toEqual([
                    ['4', 'Weight', 'Not a number.'],
                    ['9', 'Created on', 'Not a date.'],
                ]);
                expect(visible(stepOf('result').querySelector('[role="table"]'))).toBe(true);
                expect(
                    [...stepOf('result').querySelectorAll('[role="columnheader"]')].map((one) => one.textContent),
                ).toEqual(['Row', 'Column', 'Problem']);
                expect(http.get.mock.calls.filter((call) => call[0] === '/api/v1/inventories/tools')).toHaveLength(
                    reads + 1,
                );
                expect(buttons('Import another file', importDialog())).toHaveLength(1);
                expect(importDialog().querySelector('[role="status"]').textContent).toBe(
                    'Import finished: 10 items imported.',
                );
            });

            it('tells why an import failed, in the words of the server', async () => {
                await openOnMapping();
                http.post.mockResolvedValue({
                    data: record('i1', 'failed', {
                        error: 'Some rows are not valid, so nothing was imported.',
                        rows_failed: 3,
                        errors: [{ row: 4, column: 'Weight', message: 'Not a number.' }],
                    }),
                });

                await press('Start the import', importDialog());

                const danger = stepOf('result').querySelector('.alert-danger');

                expect(stepOf('result').querySelector('h3').textContent).toBe('Import failed');
                expect(visible(danger)).toBe(true);
                expect(danger.textContent).toContain('Some rows are not valid, so nothing was imported.');
                expect(danger.textContent).toContain('3 rows are not valid');
                expect(visible(stepOf('result').querySelector('.alert-success'))).toBe(false);
                expect(stepOf('result').textContent).toContain('Only the first 1 rows in error out of 3 are listed.');
            });

            it('asks for another file once an import has ended', async () => {
                await openOnMapping();
                http.post.mockResolvedValue({ data: record('i1', 'completed', { rows_imported: 1 }) });
                await press('Start the import', importDialog());

                await press('Import another file', importDialog());

                expect(visible(stepOf('file'))).toBe(true);
                expect(visible(stepOf('result'))).toBe(false);
                expect(columnsOf()).toHaveLength(0);
            });

            it('says why the import was refused, under the buttons, where it stays in view', async () => {
                await openOnMapping();
                http.post.mockRejectedValue(
                    new HttpError(422, 'Invalid.', { errors: { mapping: ['Two columns go to one field.'] } }),
                );

                await press('Start the import', importDialog());

                expect(visible(footerAlert())).toBe(true);
                expect(alertsOf()).toEqual(['Two columns go to one field.']);
                expect(visible(stepOf('mapping'))).toBe(true);
                expect(startButton().disabled).toBe(false);
            });

            it('gives up the import and asks for a file again', async () => {
                await openOnMapping();
                http.delete.mockResolvedValue({});

                await press('Cancel the import', importDialog());

                expect(http.delete).toHaveBeenCalledWith(`${IMPORTS}/i1`);
                expect(visible(stepOf('file'))).toBe(true);
                expect(visible(stepOf('mapping'))).toBe(false);
                expect(buttons('Cancel the import', importDialog())).toHaveLength(0);
            });

            it('refuses a file larger than the server takes, before it goes up', async () => {
                await openDialog();

                await sendFile(csv(IMPORT_MAX_SIZE + 1));

                expect(http.post).not.toHaveBeenCalled();
                expect(visible(footerAlert())).toBe(true);
                expect(alertsOf()).toEqual(['This file is larger than 3 MB.']);
                expect(visible(stepOf('file'))).toBe(true);
            });

            it('says what is wrong with the file, in the words of the server', async () => {
                http.post.mockRejectedValue(
                    new HttpError(422, 'Invalid.', { errors: { file: ['The file is empty.'] } }),
                );
                await openDialog();

                await sendFile(csv());

                expect(alertsOf()).toEqual(['The file is empty.']);
            });

            it('goes on with the import the person left on its way', async () => {
                await openDialog([uploaded('i9')]);

                expect(visible(stepOf('mapping'))).toBe(true);
                expect(visible(stepOf('file'))).toBe(false);
                expect(columnsOf()).toHaveLength(4);
                expect(http.post).not.toHaveBeenCalled();
            });

            it('goes on with the progress of an import that a worker has, and with the end of one that is over', async () => {
                await openDialog([record('i9', 'running')]);

                expect(visible(stepOf('result'))).toBe(true);
                expect(stepOf('result').querySelector('h3').textContent).toBe('Import in progress');
                expect(stepOf('result').textContent).toContain('The import is running.');
                expect(visible(stepOf('result').querySelector('.alert-warning'))).toBe(false);
            });

            it('puts the cursor on the file chooser once the file step shows', async () => {
                await openDialog();

                expect(visible(stepOf('file'))).toBe(true);
                expect(document.activeElement).toBe(importDialog().querySelector('#import-file'));
            });

            it('labels each cell of the rows in error on a phone, where the header row is not displayed', async () => {
                await openOnMapping();
                http.post.mockResolvedValue({
                    data: record('i1', 'failed', {
                        error: 'Some rows are not valid, so nothing was imported.',
                        rows_failed: 1,
                        errors: [{ row: 4, column: 'Weight', message: 'Not a number.' }],
                    }),
                });

                await press('Start the import', importDialog());

                const header = stepOf('result').querySelector('[role="row"]:not([data-import-error])');
                const [row] = stepOf('result').querySelectorAll('[data-import-error]');
                const captions = [...row.querySelectorAll('[role="cell"]')].map((cell) => cell.firstElementChild);

                // On a phone the header row is `display: none`, so out of the accessibility tree too...
                expect(header.classList.contains('d-none')).toBe(true);
                expect(header.classList.contains('d-md-flex')).toBe(true);
                // ...which is why each cell names its column itself, in view and for a screen reader, up to the "md" breakpoint
                // where the header row takes over (a caption that is not displayed is not read either).
                expect(captions.map((one) => one.textContent)).toEqual(['Row', 'Column', 'Problem']);
                expect(captions.map((one) => one.textContent)).toEqual(
                    [...header.querySelectorAll('[role="columnheader"]')].map((one) => one.textContent),
                );
                expect(captions.every((one) => one.classList.contains('d-md-none'))).toBe(true);
                expect(captions.every((one) => !one.hasAttribute('aria-hidden'))).toBe(true);
            });

            describe('when the values of a column look like fractions', () => {
                const WARNING =
                    'These values look like fractions (0.125 for 12.5%): they will be imported as they are.';
                const DISCOUNT = { id: 'discount', name: 'Discount', type: 'percentage', options: {}, required: false };

                /** The warning under a column (the element is always there: it is hidden when there is nothing to say). */
                const warningOf = (index) => importDialog().querySelector(`#import-warning-${index}`);

                /** Opens the mapping of a file whose second column looks like fractions and goes to the percentage field. */
                async function openOnFractions() {
                    http.post.mockResolvedValue({
                        data: uploaded('i1', {
                            columns: [
                                column(0, 'Name', ['Hammer']),
                                { ...column(1, 'Rate', ['0.125', '0.5'], 'percentage'), fraction_like: true },
                                { ...column(2, 'Margin', ['12.5', '30'], 'percentage'), fraction_like: false },
                            ],
                            mapping: [
                                { column: 0, target: 'field', field: 'name' },
                                { column: 1, target: 'field', field: 'discount' },
                                { column: 2, target: 'new', type: 'percentage', name: 'Margin' },
                            ],
                        }),
                    });
                    await openDialog([], {
                        fields: [...FIELDS, { ...DISCOUNT, position: FIELDS.length, ...queryFacts('percentage') }],
                    });
                    await sendFile(csv());
                }

                it('warns under the column, and ties the warning to its controls', async () => {
                    await openOnFractions();

                    expect(visible(warningOf(1))).toBe(true);
                    expect(warningOf(1).textContent).toBe(WARNING);
                    expect(destination(1).getAttribute('aria-describedby')).toBe('import-error-1 import-warning-1');
                    expect(columnsOf()[1].contains(warningOf(1))).toBe(true);
                    // Values that are whole percentages, and a column that goes nowhere near, are left alone.
                    expect(visible(warningOf(2))).toBe(false);
                    expect(warningOf(2).textContent).toBe('');
                    expect(visible(warningOf(0))).toBe(false);
                    expect(nameInput(2).getAttribute('aria-describedby')).toBe('import-error-2');
                });

                it('follows the destination and the type of the field to create', async () => {
                    await openOnFractions();

                    await choose(destination(1), 'field:qty');

                    expect(visible(warningOf(1))).toBe(false);
                    expect(destination(1).getAttribute('aria-describedby')).toBe('import-error-1');

                    await choose(destination(1), 'new');

                    expect(typeSelect(1).value).toBe('percentage');
                    expect(visible(warningOf(1))).toBe(true);
                    expect(nameInput(1).getAttribute('aria-describedby')).toBe('import-error-1 import-warning-1');

                    await choose(typeSelect(1), 'number');

                    expect(visible(warningOf(1))).toBe(false);
                });

                it('does not hold the import back', async () => {
                    await openOnFractions();
                    http.post.mockResolvedValue({ data: record('i1', 'pending') });

                    await press('Start the import', importDialog());

                    expect(visible(stepOf('result'))).toBe(true);
                    expect(http.post).toHaveBeenLastCalledWith(
                        `${IMPORTS}/i1/start`,
                        expect.objectContaining({
                            mapping: expect.arrayContaining([{ column: 1, target: 'field', field: 'discount' }]),
                        }),
                    );
                });
            });
        });

        describe('the files and the history of an item', () => {
            const PHOTOS = {
                id: 'photos',
                name: 'Photos',
                type: 'photo',
                options: { max_files: 3 },
                required: false,
                position: 1,
                ...queryFacts('photo'),
            };
            const DOCS = {
                id: 'docs',
                name: 'Documents',
                type: 'file',
                options: { max_files: 2 },
                required: false,
                position: 2,
                ...queryFacts('file'),
            };
            const FILE_FIELDS = [FIELDS[0], PHOTOS, DOCS];
            const FRONT = attachment('a1', 'photos', { name: 'front.jpg' });
            const BACK = attachment('a2', 'photos', { name: 'back.jpg' });
            const INVOICE = attachment('d1', 'docs', {
                name: 'invoice.pdf',
                mime: 'application/pdf',
                has_thumbnail: false,
                thumbnail_url: null,
                size: 1048576,
            });
            const SHELF = {
                id: 'i5',
                values: { name: 'Shelf', photos: ['a1', 'a2'], docs: ['d1'] },
                created_at: '2026-10-15T10:00:00Z',
                updated_at: '2026-10-15T10:00:00Z',
                attachments: [FRONT, BACK, INVOICE],
            };
            const BARE = {
                id: 'i6',
                values: { name: 'Plank' },
                created_at: '2026-10-15T10:00:00Z',
                updated_at: '2026-10-15T10:00:00Z',
                attachments: [],
            };
            const REVISIONS = '/api/v1/inventories/tools/items/i5/revisions';

            /** Opens the page of an inventory whose items hold files; the revisions of `i5` are the given ones. */
            async function openFiles({ items = [SHELF], revisions = [] } = {}) {
                http.get.mockImplementation(async (url) => {
                    if (url === '/api/v1/inventories/tools') {
                        return { data: { id: 'tools', name: 'Tools', items_count: items.length, fields: FILE_FIELDS } };
                    }

                    if (url === REVISIONS) {
                        return { data: revisions, meta: { current_page: 1, last_page: 1, total: revisions.length } };
                    }

                    return itemsPage(items);
                });
                await mount('app/inventory.blade.php');
            }

            /** Opens the dialog on the first item of the table. */
            async function openDialog() {
                bodyRows()[0].querySelector('td').click();
                await flush();
            }

            const tab = (name) => itemDialog().querySelector('#item-tab-' + name);
            const panel = (name) => itemDialog().querySelector('#item-panel-' + name);
            const picker = () => itemDialog().querySelector('input[type="file"]');
            const field = (id) => itemDialog().querySelector(`[data-field="${id}"]`);

            /** Chooses files the way the file chooser of the browser hands them over. */
            async function choosing(files) {
                Object.defineProperty(picker(), 'files', { value: files, configurable: true });
                picker().dispatchEvent(new Event('change', { bubbles: true }));
                await flush();
            }

            /** A file the person picked. */
            function picked(name, type = 'image/jpeg') {
                return new File(['x'], name, { type });
            }

            describe('in the table and the gallery', () => {
                it('shows the first photo of a cell with the number of the others, and the first file name', async () => {
                    await openFiles();

                    const [, photos, docs] = cellsOf(bodyRows()[0]);
                    const image = photos.querySelector('img');

                    expect(image.getAttribute('src')).toBe('/api/v1/attachments/a1/thumbnail');
                    expect(image.getAttribute('alt')).toBe('front.jpg');
                    expect(photos.querySelectorAll('img')).toHaveLength(1);
                    expect(photos.querySelector('.badge').textContent).toBe('+1');
                    expect(docs.textContent).toContain('invoice.pdf');
                    expect(docs.querySelector('.badge')).toBeNull();
                });

                it('is no link where the whole cell is a button, nor where the row opens the item', async () => {
                    await openFiles();

                    for (const link of cellsOf(bodyRows()[0]).flatMap((cell) => [...cell.querySelectorAll('a')])) {
                        expect(link.hasAttribute('href'), link.outerHTML).toBe(false);
                    }
                });

                it('says what the cell of an item without files holds, as for any other field', async () => {
                    await openFiles({ items: [BARE] });

                    const [, photos, docs] = cellsOf(bodyRows()[0]);

                    expect(photos.querySelector('img')).toBeNull();
                    expect(photos.textContent.trim()).toBe('—');
                    expect(docs.textContent.trim()).toBe('—');
                });

                it('opens the dialog of the item, on the field, when a cell of files is pressed', async () => {
                    await openFiles();

                    cellsOf(bodyRows()[0])[1].querySelector('button').click();
                    await flush();

                    expect(showModal).toHaveBeenCalledWith(
                        itemDialog(),
                        expect.objectContaining({ focus: expect.any(Array) }),
                    );
                    expect(document.querySelector('#item-inline-editor')).toBeNull();
                    expect(field('photos')).not.toBeNull();
                });

                it('puts the first photo of an item on its card, with the file name as alternative text', async () => {
                    await openFiles();

                    await press('Gallery');

                    const card = document.querySelector('.card.app-row-link');
                    const cover = card.querySelector('img.card-img-top');

                    expect(cover.getAttribute('src')).toBe('/api/v1/attachments/a1/thumbnail');
                    expect(cover.getAttribute('alt')).toBe('front.jpg');
                    expect(card.querySelector('.badge.bg-dark').textContent).toBe('+1');
                    expect(card.textContent).toContain('invoice.pdf');
                });

                it('puts no picture on the card of an item without a photo', async () => {
                    await openFiles({ items: [BARE] });

                    await press('Gallery');

                    expect(document.querySelector('.card.app-row-link img')).toBeNull();
                });
            });

            describe('the tabs of the dialog', () => {
                it('shows the details, the attachments with their number, and the history, the details first', async () => {
                    await openFiles();
                    await openDialog();

                    expect(
                        [...itemDialog().querySelectorAll('[role="tab"]')].map((one) => one.textContent.trim()),
                    ).toEqual(['Item details', 'Attachments (3)', 'History']);
                    expect(tab('details').getAttribute('aria-selected')).toBe('true');
                    expect(tab('files').getAttribute('aria-selected')).toBe('false');
                    expect(tab('details').getAttribute('tabindex')).toBe('0');
                    expect(tab('files').getAttribute('tabindex')).toBe('-1');
                    expect(visible(panel('details'))).toBe(true);
                    expect(visible(panel('files'))).toBe(false);
                    expect(visible(panel('history'))).toBe(false);
                    expect(panel('files').getAttribute('aria-labelledby')).toBe('item-tab-files');
                });

                it('shows one panel at a time', async () => {
                    await openFiles();
                    await openDialog();

                    await press('Attachments (3)');

                    expect(tab('files').getAttribute('aria-selected')).toBe('true');
                    expect(visible(panel('files'))).toBe(true);
                    expect(visible(panel('details'))).toBe(false);
                });

                it('moves with the arrow keys', async () => {
                    await openFiles();
                    await openDialog();

                    tab('details').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
                    await flush();

                    expect(tab('files').getAttribute('aria-selected')).toBe('true');
                    expect(document.activeElement).toBe(tab('files'));

                    tab('files').dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
                    await flush();

                    expect(tab('history').getAttribute('aria-selected')).toBe('true');
                });

                it('are not there while an item is added', async () => {
                    await openFiles();

                    await press('Add item');

                    expect(visible(itemDialog().querySelector('[role="tablist"]'))).toBe(false);
                    expect(visible(field('photos'))).toBe(true);
                });

                it('are gone while the deletion of the item is asked for', async () => {
                    await openFiles();
                    await openDialog();

                    await press('Delete', itemDialog().querySelector('.modal-footer'));

                    expect(visible(itemDialog().querySelector('[role="tablist"]'))).toBe(false);
                    expect(itemDialog().querySelector('.modal-body').textContent).toContain('Delete this item?');
                });
            });

            describe('the photo and file fields of the form', () => {
                it('shows the photos as thumbnails that link to the photo, with a button to remove each', async () => {
                    await openFiles();
                    await openDialog();

                    const images = [...field('photos').querySelectorAll('img')];

                    expect(images.map((one) => one.getAttribute('alt'))).toEqual(['front.jpg', 'back.jpg']);
                    expect(images[0].getAttribute('src')).toBe('/api/v1/attachments/a1/thumbnail');
                    expect(images[0].closest('a').getAttribute('href')).toBe('/api/v1/attachments/a1?inline=1');
                    expect(images[0].closest('a').getAttribute('target')).toBe('_blank');
                    // Never "noreferrer": without a Referer the API does not treat the request of the new tab as stateful (401).
                    expect(images[0].closest('a').getAttribute('rel')).toBe('noopener');
                    expect(buttons('Remove front.jpg', field('photos'))).toHaveLength(1);
                    expect(buttons('Remove back.jpg', field('photos'))).toHaveLength(1);
                });

                it('shows the files as names with their size, with a button to remove each', async () => {
                    await openFiles();
                    await openDialog();

                    const entry = field('docs').querySelector('li');

                    expect(entry.querySelector('a').textContent).toBe('invoice.pdf');
                    expect(entry.querySelector('a').getAttribute('href')).toBe('/api/v1/attachments/d1?inline=1');
                    expect(entry.querySelector('a').getAttribute('target')).toBe('_blank');
                    expect(entry.querySelector('a').getAttribute('rel')).toBe('noopener');
                    expect(entry.textContent).toContain('1 MB');
                    expect(buttons('Remove invoice.pdf', field('docs'))).toHaveLength(1);
                });

                it('says how full each field is, and offers to add to a field that has room', async () => {
                    await openFiles();
                    await openDialog();

                    expect(field('photos').textContent).toContain('2 of 3');
                    expect(field('docs').textContent).toContain('1 of 2');
                    expect(buttons('Add a photo', field('photos'))).toHaveLength(1);
                    expect(buttons('Add a file', field('docs'))).toHaveLength(1);
                    expect(field('photos').querySelector('button[id="item-field-photos"]')).not.toBeNull();
                });

                it('offers nothing more to a field that is full', async () => {
                    const third = attachment('a3', 'photos', { name: 'side.jpg' });
                    await openFiles({
                        items: [
                            {
                                ...SHELF,
                                values: { ...SHELF.values, photos: ['a1', 'a2', 'a3'] },
                                attachments: [...SHELF.attachments, third],
                            },
                        ],
                    });
                    await openDialog();

                    expect(buttons('Add a photo', field('photos'))).toHaveLength(0);
                    expect(field('photos').textContent).toContain('3 of 3');
                });

                it('opens the file chooser, for the types the field takes', async () => {
                    await openFiles();
                    await openDialog();
                    picker().click = vi.fn();

                    await press('Add a photo');

                    expect(picker().click).toHaveBeenCalledTimes(1);
                    expect(picker().accept).toBe('image/jpeg,image/png,image/webp,image/gif');
                });

                it('sends a file as soon as it is chosen and shows the item as the server describes it', async () => {
                    await openFiles();
                    await openDialog();
                    picker().click = vi.fn();
                    const third = attachment('a3', 'photos', { name: 'side.jpg' });
                    const sending = deferred();
                    http.post.mockReturnValue(sending.promise);

                    await press('Add a photo');
                    await choosing([picked('side.jpg')]);

                    expect(http.post.mock.calls[0][0]).toBe('/api/v1/inventories/tools/items/i5/attachments');
                    expect(http.post.mock.calls[0][1].get('field')).toBe('photos');
                    expect(field('photos').querySelector('.spinner-border')).not.toBeNull();
                    expect(field('photos').textContent).toContain('side.jpg');
                    expect(visible(buttons('Save', itemDialog())[0] ?? document.body)).toBe(true);

                    sending.resolve({
                        data: {
                            ...SHELF,
                            values: { ...SHELF.values, photos: ['a1', 'a2', 'a3'] },
                            attachments: [...SHELF.attachments, third],
                        },
                    });
                    await flush();

                    expect([...field('photos').querySelectorAll('img')].map((one) => one.getAttribute('alt'))).toEqual([
                        'front.jpg',
                        'back.jpg',
                        'side.jpg',
                    ]);
                    expect(field('photos').querySelector('.spinner-border')).toBeNull();
                    expect(tab('files').textContent.trim()).toBe('Attachments (4)');
                    // The table behind the dialog shows the new photo count.
                    expect(cellsOf(bodyRows()[0])[1].querySelector('.badge').textContent).toBe('+2');
                });

                it('shows why a file was refused, beside the file, until it is dismissed', async () => {
                    await openFiles();
                    await openDialog();
                    http.post.mockRejectedValue(
                        new HttpError(422, 'Invalid', { errors: { file: ['The file must be an image.'] } }),
                    );

                    await press('Add a photo');
                    await choosing([picked('plan.jpg')]);

                    const refusal = field('photos').querySelector('[role="alert"]');

                    expect(refusal.textContent).toBe('The file must be an image.');
                    expect(visible(refusal)).toBe(true);
                    expect(field('photos').textContent).toContain('plan.jpg');

                    await press('Dismiss the error for plan.jpg', field('photos'));

                    expect(field('photos').querySelector('[role="alert"]')).toBeNull();
                });

                it('refuses a file larger than the page says the server takes, without sending it', async () => {
                    await openFiles();
                    await openDialog();
                    const huge = picked('huge.jpg');
                    Object.defineProperty(huge, 'size', { value: MAX_ATTACHMENT_SIZE + 1 });

                    await press('Add a photo');
                    await choosing([huge]);

                    expect(http.post).not.toHaveBeenCalled();
                    expect(field('photos').querySelector('[role="alert"]').textContent).toBe(
                        'This file is larger than 5 MB.',
                    );
                });

                it('asks before erasing a file, then removes it', async () => {
                    await openFiles();
                    await openDialog();
                    const without = {
                        ...SHELF,
                        values: { ...SHELF.values, photos: ['a2'] },
                        attachments: [BACK, INVOICE],
                    };
                    http.delete.mockResolvedValue({ data: without });

                    await press('Remove front.jpg');

                    expect(itemDialog().querySelector('.modal-body').textContent).toContain(
                        'Delete the file front.jpg? This cannot be undone.',
                    );
                    expect(visible(itemDialog().querySelector('[role="tablist"]'))).toBe(false);
                    expect(http.delete).not.toHaveBeenCalled();

                    await press('Delete the file');

                    expect(http.delete).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i5/attachments/a1');
                    expect(notify).toHaveBeenCalledWith('success', 'File deleted.');
                    expect([...field('photos').querySelectorAll('img')].map((one) => one.getAttribute('alt'))).toEqual([
                        'back.jpg',
                    ]);
                    expect(hideModal).not.toHaveBeenCalled();
                });

                it('keeps the file when the person gives up', async () => {
                    await openFiles();
                    await openDialog();

                    await press('Remove front.jpg');
                    await press('Cancel', itemDialog().querySelector('.modal-footer'));

                    expect(http.delete).not.toHaveBeenCalled();
                    expect(field('photos').querySelectorAll('img')).toHaveLength(2);
                    expect(visible(itemDialog().querySelector('[role="tablist"]'))).toBe(true);
                });

                it('keeps the files chosen for an item that is being added, and sends them after it was saved', async () => {
                    await openFiles({ items: [BARE] });
                    http.post.mockImplementation(async (url) =>
                        url === '/api/v1/inventories/tools/items'
                            ? { data: { ...BARE, id: 'new' } }
                            : { data: { ...BARE, id: 'new', values: { photos: ['a1'] }, attachments: [FRONT] } },
                    );

                    await press('Add item');
                    picker().click = vi.fn();
                    await press('Add a photo');
                    await choosing([picked('front.jpg')]);

                    expect(http.post).not.toHaveBeenCalled();
                    expect(field('photos').textContent).toContain('front.jpg');
                    expect(buttons('Remove front.jpg', field('photos'))).toHaveLength(1);

                    await type(control('name'), 'Shelf');
                    await submit(itemDialog().querySelector('form'));

                    expect(http.post.mock.calls.map((call) => call[0])).toEqual([
                        '/api/v1/inventories/tools/items',
                        '/api/v1/inventories/tools/items/new/attachments',
                    ]);
                    expect(http.post.mock.calls[0][1]).toEqual({ values: { name: 'Shelf' } });
                    expect(hideModal).toHaveBeenCalledWith(itemDialog());
                });

                it('takes back a file chosen for an item that is being added', async () => {
                    await openFiles({ items: [BARE] });

                    await press('Add item');
                    picker().click = vi.fn();
                    await press('Add a photo');
                    await choosing([picked('front.jpg'), picked('back.jpg')]);
                    await press('Remove front.jpg');

                    expect(field('photos').textContent).not.toContain('front.jpg');
                    expect(field('photos').textContent).toContain('back.jpg');
                });

                it('stays open on the new item, showing the file that failed, when a file could not be sent', async () => {
                    await openFiles({ items: [BARE] });
                    http.post
                        .mockResolvedValueOnce({ data: { ...BARE, id: 'new' } })
                        .mockRejectedValueOnce(new HttpError(422, 'Invalid', { errors: { file: ['Not an image.'] } }));

                    await press('Add item');
                    picker().click = vi.fn();
                    await press('Add a photo');
                    await choosing([picked('front.jpg')]);
                    await type(control('name'), 'Shelf');
                    await submit(itemDialog().querySelector('form'));

                    expect(hideModal).not.toHaveBeenCalled();
                    expect(notify).toHaveBeenCalledWith('warning', 'Item added, but 1 file could not be sent.');
                    expect(visible(itemDialog().querySelector('[role="tablist"]'))).toBe(true);
                    expect(field('photos').querySelector('[role="alert"]').textContent).toBe('Not an image.');
                });
            });

            describe('the attachments tab', () => {
                it('lists every file with its thumbnail or icon, its name, its size and the day it was added', async () => {
                    await openFiles();
                    await openDialog();

                    await press('Attachments (3)');

                    const entries = [...panel('files').querySelectorAll('li.list-group-item')];

                    expect(entries.map((entry) => entry.querySelector('a.fw-bold').textContent)).toEqual([
                        'front.jpg',
                        'back.jpg',
                        'invoice.pdf',
                    ]);
                    expect(entries[0].querySelector('img').getAttribute('src')).toBe(
                        '/api/v1/attachments/a1/thumbnail',
                    );
                    expect(entries[2].querySelector('img')).toBeNull();
                    expect(entries[2].textContent).toContain('1 MB');
                    expect(entries[2].textContent).toContain('Documents');
                });

                it('opens a file in a new tab without hiding the Referer, which the API needs to know the session', async () => {
                    await openFiles();
                    await openDialog();

                    await press('Attachments (3)');

                    const links = [...panel('files').querySelectorAll('a[href*="inline=1"]')];

                    // The thumbnail and the name of each of the three files.
                    expect(links).toHaveLength(6);

                    for (const link of links) {
                        expect(link.getAttribute('target')).toBe('_blank');
                        expect(link.getAttribute('rel')).toBe('noopener');
                    }
                });

                it('downloads a file, and deletes it after asking', async () => {
                    await openFiles();
                    await openDialog();
                    await press('Attachments (3)');
                    http.delete.mockResolvedValue({
                        data: { ...SHELF, values: { ...SHELF.values, docs: [] }, attachments: [FRONT, BACK] },
                    });

                    const download = panel('files').querySelector('a[aria-label="Download invoice.pdf"]');

                    expect(download.getAttribute('href')).toBe('/api/v1/attachments/d1');

                    await press('Remove invoice.pdf', panel('files'));

                    expect(itemDialog().querySelector('.modal-body').textContent).toContain(
                        'Delete the file invoice.pdf?',
                    );

                    await press('Delete the file');

                    expect(http.delete).toHaveBeenCalledWith('/api/v1/inventories/tools/items/i5/attachments/d1');
                    expect(panel('files').querySelectorAll('li.list-group-item')).toHaveLength(2);
                });

                it('adds a file to the item itself', async () => {
                    await openFiles();
                    await openDialog();
                    await press('Attachments (3)');
                    picker().click = vi.fn();
                    const notes = attachment('n1', null, {
                        name: 'notes.txt',
                        mime: 'text/plain',
                        has_thumbnail: false,
                    });
                    http.post.mockResolvedValue({ data: { ...SHELF, attachments: [...SHELF.attachments, notes] } });

                    await press('Add a file', panel('files'));
                    await choosing([picked('notes.txt', 'text/plain')]);

                    expect(picker().click).toHaveBeenCalledTimes(1);
                    expect(http.post.mock.calls[0][1].has('field')).toBe(false);
                    expect(panel('files').querySelectorAll('li.list-group-item')).toHaveLength(4);
                    expect(tab('files').textContent.trim()).toBe('Attachments (4)');
                });

                it('says when the item has no attachment', async () => {
                    await openFiles({ items: [BARE] });
                    await openDialog();

                    await press('Attachments (0)');

                    expect(panel('files').textContent).toContain('No attachment yet.');
                });
            });

            describe('the history tab', () => {
                const REVISION = {
                    id: ulid('r3'),
                    action: 'updated',
                    user: { name: 'Ada' },
                    created_at: '2026-10-15T10:00:00Z',
                    changes: [{ field: { id: 'name', name: 'Name', type: 'text' }, before: 'Racks', after: 'Shelf' }],
                };

                it('reads the revisions when it is shown and lists them with what changed', async () => {
                    await openFiles({
                        revisions: [
                            REVISION,
                            {
                                id: ulid('r2'),
                                action: 'attachment_added',
                                user: null,
                                created_at: '2026-10-15T09:00:00Z',
                                changes: [
                                    {
                                        attachment: { id: 'a1', name: 'front.jpg' },
                                        field: { id: 'photos', name: 'Photos', type: 'photo' },
                                    },
                                ],
                            },
                        ],
                    });
                    await openDialog();

                    expect(http.get).not.toHaveBeenCalledWith(REVISIONS, expect.anything());

                    await press('History');

                    expect(http.get).toHaveBeenCalledWith(REVISIONS, { params: { page: 1 } });

                    const entries = [...panel('history').querySelectorAll('li.list-group-item')];

                    expect(entries[0].querySelector('.fw-bold').textContent.startsWith('Ada · ')).toBe(true);
                    expect(entries[0].querySelector('li').textContent).toBe('Name: Racks → Shelf');
                    expect(entries[1].querySelector('.fw-bold').textContent.startsWith('Deleted user · ')).toBe(true);
                    expect(entries[1].querySelector('li').textContent).toBe('File added: front.jpg');
                });

                it('says when nothing was recorded', async () => {
                    await openFiles();
                    await openDialog();

                    await press('History');

                    expect(panel('history').textContent).toContain('No change recorded yet.');
                });

                it('adds the next page on request', async () => {
                    http.get.mockImplementation(async (url, request) => {
                        if (url === REVISIONS) {
                            return request.params.page === 1
                                ? { data: [REVISION], meta: { current_page: 1, last_page: 2, total: 2 } }
                                : {
                                      data: [{ ...REVISION, id: ulid('r1') }],
                                      meta: { current_page: 2, last_page: 2, total: 2 },
                                  };
                        }

                        return url === '/api/v1/inventories/tools'
                            ? { data: { id: 'tools', name: 'Tools', items_count: 1, fields: FILE_FIELDS } }
                            : itemsPage([SHELF]);
                    });
                    await mount('app/inventory.blade.php');
                    await openDialog();

                    await press('History');

                    expect(panel('history').querySelectorAll('li.list-group-item')).toHaveLength(1);

                    await press('Load more', panel('history'));

                    expect(panel('history').querySelectorAll('li.list-group-item')).toHaveLength(2);
                    expect(buttons('Load more', panel('history'))).toHaveLength(0);
                });

                it('offers to try again when it could not be read', async () => {
                    await openFiles();
                    await openDialog();
                    http.get.mockRejectedValueOnce(new HttpError(500, 'Server down'));

                    await press('History');

                    expect(visible(panel('history').querySelector('.alert-danger'))).toBe(true);
                    expect(panel('history').textContent).toContain('The history could not be loaded.');

                    await press('Try again', panel('history'));

                    expect(visible(panel('history').querySelector('.alert-danger'))).toBe(false);
                });

                it('shows what a person typed as text, never as markup', async () => {
                    await openFiles({
                        revisions: [{ ...REVISION, user: { name: '<img src=x onerror="window.pwned = true">' } }],
                    });
                    await openDialog();

                    await press('History');

                    expect(panel('history').querySelector('img')).toBeNull();
                    expect(panel('history').textContent).toContain('<img src=x onerror="window.pwned = true">');
                    expect(window.pwned).toBeUndefined();
                });
            });

            describe('what the keyboard and assistive technology find in the dialog', () => {
                it('names the group of a photo or file field by its label, which points at no control, and leaves the other labels on their control', async () => {
                    await openFiles();
                    await openDialog();

                    for (const [id, name] of [
                        ['photos', 'Photos'],
                        ['docs', 'Documents'],
                    ]) {
                        const label = field(id).querySelector('label');
                        const group = field(id).querySelector('[role="group"]');

                        expect(label.textContent.trim()).toBe(name);
                        expect(label.hasAttribute('for')).toBe(false);
                        expect(label.id).toBe('item-label-' + id);
                        expect(group.getAttribute('aria-labelledby')).toBe(label.id);
                    }

                    expect(field('name').querySelector('label').getAttribute('for')).toBe('item-field-name');
                    expect(field('name').querySelector('label').hasAttribute('id')).toBe(true);
                });

                it('makes the panels of the tabs reachable by keyboard and labelled by their tab', async () => {
                    await openFiles();
                    await openDialog();

                    for (const name of ['details', 'files', 'history']) {
                        expect(panel(name).getAttribute('role'), name).toBe('tabpanel');
                        expect(panel(name).getAttribute('tabindex'), name).toBe('0');
                        expect(panel(name).getAttribute('aria-labelledby'), name).toBe('item-tab-' + name);
                        expect(tab(name).id).toBe('item-tab-' + name);
                    }
                });

                it('has no panel of tabs while an item is added: there are no tabs then', async () => {
                    await openFiles();

                    await press('Add item');

                    expect(visible(itemDialog().querySelector('[role="tablist"]'))).toBe(false);
                    expect(panel('details').hasAttribute('role')).toBe(false);
                    expect(panel('details').hasAttribute('tabindex')).toBe(false);
                    expect(panel('details').hasAttribute('aria-labelledby')).toBe(false);
                });

                it('keeps the three tabs on one line, scrolling sideways when they do not fit', async () => {
                    await openFiles();
                    await openDialog();

                    const tabs = itemDialog().querySelector('[role="tablist"]');

                    expect(tabs.classList.contains('flex-nowrap')).toBe(true);
                    expect(tabs.classList.contains('overflow-x-auto')).toBe(true);
                });

                it('announces the uploads in a live region that is on the page from the start, not in the row of the file', async () => {
                    await openFiles({ items: [BARE] });
                    await openDialog();

                    const live = itemDialog().querySelector('.modal-body > [role="status"][aria-live="polite"]');
                    const sending = deferred();

                    expect(live).not.toBeNull();
                    expect(live.textContent).toBe('');

                    http.post.mockReturnValue(sending.promise);
                    picker().click = vi.fn();
                    await press('Add a photo');
                    await choosing([picked('side.jpg')]);

                    expect(live.textContent).toBe('Sending side.jpg…');
                    // The same element, not one added with its message; and the row of the file has no region of its own.
                    expect(itemDialog().querySelector('.modal-body > [role="status"]')).toBe(live);
                    expect(field('photos').querySelectorAll('[role="status"]')).toHaveLength(0);

                    sending.resolve({
                        data: {
                            ...BARE,
                            values: { ...BARE.values, photos: ['a9'] },
                            attachments: [attachment('a9', 'photos', { name: 'side.jpg' })],
                        },
                    });
                    await flush();

                    expect(live.textContent).toBe('File sent: side.jpg');
                });

                it('turns off the buttons that close the dialog while a change is being saved, and cancels a closing of it', async () => {
                    await openFiles();
                    await openDialog();
                    const saving = deferred();
                    http.patch.mockReturnValue(saving.promise);
                    const close = itemDialog().querySelector('.modal-header .btn-close');
                    const cancel = buttons('Cancel', itemDialog())[0];

                    expect(close.disabled).toBe(false);
                    expect(cancel.disabled).toBe(false);

                    await type(control('name'), 'Shelf 2');
                    await press('Save', itemDialog());

                    expect(close.disabled).toBe(true);
                    expect(cancel.disabled).toBe(true);

                    // Escape and a click outside ask Bootstrap to close the dialog, which asks the page first.
                    const escape = new Event('hide.bs.modal', { cancelable: true });
                    itemDialog().dispatchEvent(escape);

                    expect(escape.defaultPrevented).toBe(true);

                    saving.resolve({ data: SHELF });
                    await flush();

                    const later = new Event('hide.bs.modal', { cancelable: true });
                    itemDialog().dispatchEvent(later);

                    expect(later.defaultPrevented).toBe(false);
                    expect(close.disabled).toBe(false);
                    expect(hideModal).toHaveBeenCalledWith(itemDialog());
                });
            });

            describe('the thumbnails', () => {
                /** Whether every picture in the element is sized, loaded lazily and decoded off the main thread. */
                function expectSized(images, size) {
                    expect(images.length).toBeGreaterThan(0);

                    for (const image of images) {
                        expect(image.getAttribute('loading'), image.outerHTML).toBe('lazy');
                        expect(image.getAttribute('decoding'), image.outerHTML).toBe('async');
                        expect(image.getAttribute('width'), image.outerHTML).toBe(String(size));
                        expect(image.getAttribute('height'), image.outerHTML).toBe(String(size));
                    }
                }

                it('have their size from the start, so that the page does not move as they load', async () => {
                    await openFiles();

                    expectSized([...cellsOf(bodyRows()[0])[1].querySelectorAll('img')], 32);

                    await openDialog();

                    expectSized([...field('photos').querySelectorAll('img')], 72);
                    expectSized([...panel('files').querySelectorAll('img')], 72);
                });

                it('are decoded off the main thread on the cards of the gallery', async () => {
                    await openFiles();

                    await press('Gallery');

                    const cover = document.querySelector('.card.app-row-link img.card-img-top');

                    expect(cover.getAttribute('loading')).toBe('lazy');
                    expect(cover.getAttribute('decoding')).toBe('async');
                });
            });

            describe('the files on their way up', () => {
                /** Starts to add photos to the item that has no file, and leaves the first one on its way. */
                async function sendingPhotos(...names) {
                    await openFiles({ items: [BARE] });
                    await openDialog();
                    const first = deferred();
                    http.post.mockReturnValueOnce(first.promise).mockReturnValue(new Promise(() => {}));
                    picker().click = vi.fn();
                    await press('Add a photo');
                    await choosing(names.map((name) => picked(name)));

                    return first;
                }

                it('are shown in their field only, and not again in the attachments tab', async () => {
                    await sendingPhotos('side.jpg');
                    tab('files').click();
                    await flush();

                    expect(field('photos').textContent).toContain('side.jpg');
                    expect(panel('files').textContent).not.toContain('side.jpg');
                    // Nothing is attached to the item itself: the tab says so.
                    expect(visible(panel('files').querySelector('p.text-secondary'))).toBe(true);
                });

                it('are shown in the attachments tab when they are for the item itself', async () => {
                    await openFiles({ items: [BARE] });
                    await openDialog();
                    http.post.mockReturnValue(new Promise(() => {}));
                    picker().click = vi.fn();
                    tab('files').click();
                    await flush();
                    await press('Add a file', panel('files'));

                    await choosing([picked('notes.txt', 'text/plain')]);

                    expect(panel('files').textContent).toContain('notes.txt');
                    expect(panel('files').querySelectorAll('li.list-group-item')).toHaveLength(1);
                    expect(field('docs').textContent).not.toContain('notes.txt');
                    expect(visible(panel('files').querySelector('p.text-secondary'))).toBe(false);
                });

                it('show the files that wait for their turn as waiting', async () => {
                    await sendingPhotos('front.jpg', 'side.jpg', 'back.jpg');

                    const waiting = [...field('photos').querySelectorAll('span.small')].filter(
                        (note) => note.textContent === 'Waiting…' && visible(note),
                    );

                    expect(waiting).toHaveLength(2);
                    expect(field('photos').querySelectorAll('.spinner-border')).toHaveLength(1);
                    expect(field('photos').textContent).toContain('3 of 3');
                    expect(buttons('Add a photo', field('photos'))).toHaveLength(0);
                });

                it('cannot be removed from the field while another is going up', async () => {
                    await openFiles();
                    await openDialog();
                    http.post.mockReturnValue(new Promise(() => {}));
                    picker().click = vi.fn();
                    await press('Add a photo');
                    await choosing([picked('side.jpg')]);

                    for (const [name, root] of [
                        ['Remove front.jpg', field('photos')],
                        ['Remove back.jpg', field('photos')],
                        ['Remove invoice.pdf', field('docs')],
                    ]) {
                        const [button] = buttons(name, root);

                        expect(button.disabled, name).toBe(true);
                    }
                });

                it('can be sent again from the row that says they failed', async () => {
                    await openFiles({ items: [BARE] });
                    await openDialog();
                    http.post.mockRejectedValueOnce(new HttpError(500, 'Server down'));
                    picker().click = vi.fn();
                    await press('Add a photo');
                    const side = picked('side.jpg');

                    await choosing([side]);

                    expect(field('photos').textContent).toContain('Server down');
                    expect(buttons('Try again', field('photos'))).toHaveLength(1);
                    expect(buttons('Try again', field('photos'))[0].getAttribute('aria-label')).toBe(
                        'Try sending side.jpg again',
                    );

                    http.post.mockResolvedValue({
                        data: {
                            ...BARE,
                            values: { ...BARE.values, photos: ['a9'] },
                            attachments: [attachment('a9', 'photos', { name: 'side.jpg' })],
                        },
                    });
                    await press('Try again', field('photos'));

                    expect(http.post).toHaveBeenCalledTimes(2);
                    expect(http.post.mock.calls[1][1].get('file')).toBe(side);
                    expect([...field('photos').querySelectorAll('img')].map((one) => one.getAttribute('alt'))).toEqual([
                        'side.jpg',
                    ]);
                    expect(buttons('Try again', field('photos'))).toHaveLength(0);
                });

                it('offer nothing to send again when the browser itself refused the file', async () => {
                    await openFiles({ items: [BARE] });
                    await openDialog();
                    picker().click = vi.fn();
                    await press('Add a photo');
                    const huge = picked('huge.jpg');
                    Object.defineProperty(huge, 'size', { value: MAX_ATTACHMENT_SIZE + 1 });

                    await choosing([huge]);

                    expect(field('photos').textContent).toContain('This file is larger than 5 MB.');
                    expect(buttons('Try again', field('photos'))).toHaveLength(0);
                    expect(buttons('Dismiss the error for huge.jpg', field('photos'))).toHaveLength(1);
                });
            });

            describe('what a person who may not edit sees', () => {
                beforeEach(() => {
                    PAGE.abilities = { edit_items: false };
                });

                it('is the details with the files linked, and no control to add or remove', async () => {
                    await openFiles();
                    await openDialog();

                    expect(itemDialog().querySelectorAll('input, select, textarea')).toHaveLength(0);
                    expect(visible(itemDialog().querySelector('[role="tablist"]'))).toBe(true);

                    const links = [...panel('details').querySelectorAll('dd a')];

                    expect(links.map((link) => link.getAttribute('href'))).toEqual([
                        '/api/v1/attachments/a1?inline=1',
                        '/api/v1/attachments/a2?inline=1',
                        '/api/v1/attachments/d1?inline=1',
                    ]);
                    expect(links[0].querySelector('img').getAttribute('alt')).toBe('front.jpg');
                    expect(links.every((link) => link.getAttribute('rel') === 'noopener')).toBe(true);
                });

                it('can read the attachments and download them, but not add or delete any', async () => {
                    await openFiles();
                    await openDialog();

                    await press('Attachments (3)');

                    expect(buttons('Add a file', panel('files'))).toHaveLength(0);
                    expect(buttons('Remove front.jpg', panel('files'))).toHaveLength(0);
                    expect(panel('files').querySelector('a[aria-label="Download front.jpg"]')).not.toBeNull();
                });

                it('opens the item when a thumbnail in the table is clicked: it is an anchor with no address, not a link', async () => {
                    await openFiles();

                    cellsOf(bodyRows()[0])[1].querySelector('img').click();
                    await flush();

                    expect(showModal).toHaveBeenCalledWith(itemDialog());
                });

                it('can read the history', async () => {
                    await openFiles({
                        revisions: [
                            {
                                id: ulid('r4'),
                                action: 'created',
                                user: { name: 'Ada' },
                                created_at: '2026-10-15T08:00:00Z',
                                changes: [
                                    { field: { id: 'name', name: 'Name', type: 'text' }, before: null, after: 'Shelf' },
                                ],
                            },
                        ],
                    });
                    await openDialog();

                    await press('History');

                    expect([...panel('history').querySelectorAll('li li')].map((line) => line.textContent)).toEqual([
                        'Item created',
                        'Name: Shelf',
                    ]);
                });
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

        it('does not claim the inventory has no field when the fields could not be read, and reads them again on request', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server down'));
            await mount('app/inventory-fields.blade.php');

            const alert = document.querySelector('.card-body > .alert-danger');

            expect(visible(alert)).toBe(true);
            expect(alert.textContent).toContain('The fields could not be loaded.');
            expect(visible(document.querySelector('.empty'))).toBe(false);

            http.get.mockResolvedValue({ data: { id: 'tools', name: 'Tools', fields: FIELDS } });
            await press('Try again', alert);

            expect(visible(alert)).toBe(false);
            expect(entries()).toHaveLength(3);
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
                'File',
                'Photo',
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

        it('offers the number of files a photo or file field takes, and never a requirement', async () => {
            await open();
            await press('Add a field');
            const required = () => fieldDialog().querySelector('input[type="checkbox"]');

            expect(visible(required())).toBe(true);
            expect(input('field-max-files')).toBeNull();

            await choose(input('field-type'), 'photo');

            expect(visible(required())).toBe(false);
            expect([...input('field-max-files').options].map((option) => option.textContent)).toEqual([
                '1',
                '2',
                '3',
                '4',
                '5',
                '6',
                '7',
                '8',
                '9',
                '10',
            ]);
            expect(input('field-max-files').value).toBe('1');

            await choose(input('field-max-files'), '4');
            await type(input('field-name'), 'Photos');
            http.post.mockResolvedValue({ data: { id: 'new' } });
            await submit(fieldDialog().querySelector('form'));

            expect(http.post.mock.calls[0][1]).toEqual({
                type: 'photo',
                name: 'Photos',
                required: false,
                options: { max_files: 4 },
            });
        });

        it('does not send a requirement ticked before the type became a file field', async () => {
            await open();
            await press('Add a field');

            await tick(fieldDialog().querySelector('input[type="checkbox"]'), true);
            await choose(input('field-type'), 'file');
            await type(input('field-name'), 'Manuals');
            http.post.mockResolvedValue({ data: { id: 'new' } });
            await submit(fieldDialog().querySelector('form'));

            expect(http.post.mock.calls[0][1]).toEqual({
                type: 'file',
                name: 'Manuals',
                required: false,
                options: { max_files: 1 },
            });
        });

        it('sums up how many files a photo or file field takes', async () => {
            await open([
                FIELDS[0],
                {
                    id: 'photos',
                    name: 'Photos',
                    type: 'photo',
                    options: { max_files: 3 },
                    required: false,
                    position: 1,
                },
            ]);

            expect(entries().map((entry) => entry.querySelector('.text-secondary').textContent)).toEqual([
                'Maximum length: 120',
                'Files allowed: 3',
            ]);
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
