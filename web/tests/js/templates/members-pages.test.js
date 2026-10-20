/**
 * The members page and the page of invitations under the real Alpine CSP build, with the link of the header.
 *
 * `alpine-csp.test.js` scans the templates for syntax the CSP build refuses; this file goes further and runs these ones: the
 * Blade source of the pages is turned into plain HTML (the few constructs they use are supported, anything else fails loudly so
 * this helper is extended on purpose), mounted with the real components, and driven the way a person would. Only the network
 * (`http`), the toasts and the navigation are replaced.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';
import { MESSAGES } from '../support/messages.js';

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

const VIEWS = join(import.meta.dirname, '../../../resources/views');

/** The address of the API for the members of the workspace of the page. */
const MEMBERS_URL = '/api/v1/workspaces/shop/members';
const INVITATIONS_URL = '/api/v1/workspaces/shop/invitations';
const MY_INVITATIONS_URL = '/api/v1/me/invitations';

/** Where the routes the templates mention lead; `{workspace}` stands for the ULID the template gives. */
const ROUTES = {
    app: '/app',
    'app.workspace': '/app/w/{workspace}',
    'app.invitations': '/app/invitations',
    'api.v1.workspaces.members.index': '/api/v1/workspaces/{workspace}/members',
    'api.v1.workspaces.transfer': '/api/v1/workspaces/{workspace}/transfer',
    'api.v1.workspaces.invitations.index': '/api/v1/workspaces/{workspace}/invitations',
    'api.v1.me.invitations.index': '/api/v1/me/invitations',
};

/** Escapes text for an HTML attribute, like Blade's `{{ }}`. */
function escape(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;');
}

/** Fails the rendering with a message that says what to teach the helper. */
function fail(message) {
    throw new Error(`members-pages.test.js cannot render the template: ${message}`);
}

/** The path of a route, with the parameters the template gives it. */
function route(name, parameters) {
    let path = ROUTES[name] ?? fail(`unknown route ${name}`);

    for (const [, key, value] of (parameters ?? '').matchAll(/'(\w+)'\s*=>\s*('[^']*'|\$[\w>-]+)/g)) {
        path = path.replace(`{${key}}`, value.startsWith("'") ? value.slice(1, -1) : 'shop');
    }

    return path.includes('{') ? fail(`route ${name} lacks a parameter: ${path}`) : path;
}

/**
 * Turns the Blade source of a template into plain HTML. Only the constructs these templates use are supported.
 *
 * @param {string} path Template, relative to resources/views.
 * @param {{ owner?: boolean, waiting?: number }} [page] What the controller hands to the template.
 * @returns {string}
 */
function render(path, { owner = true, waiting = 2 } = {}) {
    let html = readFileSync(join(VIEWS, path), 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@extends\([^)]*\)/g, '')
        .replace(/@section\('(?:title|heading)'[^\n]*\)\n/g, '');

    // The content section is the page; the layout around it is not under test.
    html = html.replace(/@section\('content'\)([\s\S]*)@endsection/, '$1');

    // What only the owner is offered (the blocks do not nest).
    html = html.replace(/@if \(\$isOwner\)([\s\S]*?)(?:@else([\s\S]*?))?@endif/g, (_match, yes, no = '') =>
        owner ? yes : no,
    );

    // Icons.
    html = html.replace(/<x-tabler-[\w-]+([^>]*?)\s*\/>/g, '<svg$1></svg>');

    // Echoes.
    html = html.replace(/\{\{\s*([\s\S]+?)\s*\}\}/g, (_match, expression) => {
        let found;

        if ((found = expression.match(/^__\('((?:[^'\\]|\\.)*)'(?:,\s*\[([^\]]*)\])?\)$/))) {
            return escape(found[1].replace(':name', 'Shop').replace(':count', String(waiting)));
        }

        if ((found = expression.match(/^route\('([\w.-]+)'(?:,\s*\[([^\]]*)\])?(?:,\s*absolute:\s*false)?\)$/))) {
            return route(found[1], found[2]);
        }

        const values = {
            '$workspace->name': 'Shop',
            '$workspace->ulid': 'shop',
            "$isOwner ? '1' : '0'": owner ? '1' : '0',
            $waiting: String(waiting),
        };

        if (expression.startsWith('$waiting === 1 ?')) {
            return escape(waiting === 1 ? '1 invitation waiting' : `${waiting} invitations waiting`);
        }

        return values[expression] ?? fail(`unsupported echo {{ ${expression} }}`);
    });

    expect(html, 'unsupported Blade syntax left in the template').not.toMatch(/@\w+\(|\{\{|\{!!|@end\w+|<x-/);

    return html;
}

/** A member as the API describes them. */
function member(id, name, role, overrides = {}) {
    return { id, name, email: null, role, joined_at: '2026-10-01T10:00:00+00:00', is_self: false, ...overrides };
}

/** An invitation the workspace sent. */
function sent(id, email, role = 'editor') {
    return {
        id,
        email,
        role,
        invited_by: { id: 'm-olga', name: 'Olga' },
        expires_at: '2026-11-01T10:00:00+00:00',
        created_at: '2026-10-18T10:00:00+00:00',
    };
}

/** An invitation addressed to the person. */
function received(id, name, role = 'editor') {
    return {
        id,
        workspace: { id: `ws-${id}`, name },
        role,
        inviter: { name: 'Olga' },
        expires_at: '2026-11-01T10:00:00+00:00',
    };
}

/** The members of the workspace, seen by its owner (Olga) or by an editor (Edgar). */
function team({ asOwner = true } = {}) {
    return [
        member('m-olga', 'Olga', 'owner', { email: asOwner ? 'olga@example.test' : null, is_self: asOwner }),
        member('m-edgar', 'Edgar', 'editor', { email: asOwner ? 'edgar@example.test' : null, is_self: !asOwner }),
        member('m-vera', 'Vera', 'viewer', { email: asOwner ? 'vera@example.test' : null }),
    ];
}

describe('the members and invitations pages under the real Alpine CSP build', () => {
    let Alpine;
    let answers;

    beforeAll(async () => {
        ({ default: Alpine } = await import('@alpinejs/csp'));
        const registrations = {
            invitationsLink: '../../../resources/js/components/invitations-link.js',
            myInvitations: '../../../resources/js/components/my-invitations.js',
            workspaceInvitations: '../../../resources/js/components/workspace-invitations.js',
            workspaceMembers: '../../../resources/js/components/workspace-members.js',
        };

        for (const [name, path] of Object.entries(registrations)) {
            Alpine.data(name, (await import(/* @vite-ignore */ path)).default);
        }

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
    async function mount(templates, page = {}) {
        document.documentElement.lang = 'en';
        document.body.innerHTML =
            `<script type="application/json" id="client-messages">${JSON.stringify(MESSAGES)}</script>` +
            templates.map((path) => render(path, page)).join('');
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

    /** The visible buttons whose text or accessible name is the given one. */
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

    /** Chooses an option of a select the way a person does. */
    async function choose(select, value) {
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
    }

    /** The rows of the members card. */
    const memberRows = () =>
        [...document.querySelectorAll('[x-data="workspaceMembers"] .list-group-item')].filter((row) =>
            row.querySelector('.fw-bold'),
        );

    /** The rows of the invitations that wait (the owner's card). */
    const sentRows = () =>
        [...document.querySelectorAll('[x-data="workspaceInvitations"] .list-group-item')].filter((row) =>
            row.querySelector('.fw-bold'),
        );

    /** The cards of the page of invitations. */
    const cards = () =>
        [...document.querySelectorAll('[x-data="myInvitations"] .card')].filter((card) =>
            card.querySelector('.card-title'),
        );

    /** The text of the visible badges of an element. */
    const badges = (row) =>
        [...row.querySelectorAll('.badge')].filter(visible).map((badge) => badge.textContent.trim());

    beforeEach(() => {
        vi.resetAllMocks();
        answers = {
            [MEMBERS_URL]: { data: team() },
            [INVITATIONS_URL]: { data: [sent('i-2', 'new@example.test', 'viewer'), sent('i-1', 'old@example.test')] },
            [MY_INVITATIONS_URL]: { data: [received('a', 'Warehouse'), received('b', 'Garage', 'viewer')] },
        };
        http.get.mockImplementation(async (url) => answers[url] ?? fail(`unexpected GET ${url}`));
    });

    describe('the members page, for the owner', () => {
        beforeEach(async () => {
            await mount(['app/workspace-members.blade.php']);
        });

        it('lists the members with their role, their address when it is given, and who is the person', () => {
            expect(memberRows().map((row) => row.querySelector('.fw-bold').textContent)).toEqual([
                'Olga',
                'Edgar',
                'Vera',
            ]);
            expect(memberRows().map(badges)).toEqual([['Owner', 'You'], ['Editor'], ['Viewer']]);
            expect(memberRows().map((row) => row.querySelector('.text-break:not(.fw-bold)').textContent)).toEqual([
                'olga@example.test',
                'edgar@example.test',
                'vera@example.test',
            ]);
            expect(memberRows()[1].textContent).toContain('Joined <2026-10-01T10:00:00+00:00>');
        });

        it('offers the controls on every member but the owner, and no way to leave', () => {
            const controls = memberRows().map((row) => ({
                role: [...row.querySelectorAll('select')].filter(visible).length,
                transfer: buttons(`Make owner: ${row.querySelector('.fw-bold').textContent}`, row).length,
                remove: buttons(`Remove ${row.querySelector('.fw-bold').textContent} from the workspace`, row).length,
            }));

            expect(controls).toEqual([
                { role: 0, transfer: 0, remove: 0 },
                { role: 1, transfer: 1, remove: 1 },
                { role: 1, transfer: 1, remove: 1 },
            ]);
            expect(document.querySelector('[data-focus="leave"]')).toBeNull();
            expect(
                [...document.querySelectorAll('.card-footer p')].find((hint) =>
                    hint.textContent.includes('hand its ownership over'),
                ),
            ).toBeTruthy();
        });

        it('shows the role each member has in its select', () => {
            const selects = memberRows().map((row) => [...row.querySelectorAll('select')].filter(visible)[0]);

            // The owner has none; the others show their role.
            expect(selects.map((select) => select?.value)).toEqual([undefined, 'editor', 'viewer']);
        });

        it('changes the role of a member from its select', async () => {
            http.patch.mockResolvedValue({
                data: member('m-edgar', 'Edgar', 'viewer', { email: 'edgar@example.test' }),
            });

            await choose(memberRows()[1].querySelector('select'), 'viewer');

            expect(http.patch).toHaveBeenCalledWith(`${MEMBERS_URL}/m-edgar`, { role: 'viewer' });
            expect(badges(memberRows()[1])).toEqual(['Viewer']);
            expect(memberRows()[1].querySelector('select').value).toBe('viewer');
            expect(notify).toHaveBeenCalledWith('success', 'The role of Edgar was updated.');
        });

        it('puts the select back when the change is refused', async () => {
            http.patch.mockRejectedValue(new HttpError(409, 'Nope', { code: 'cannot_change_owner_role', body: {} }));

            await choose(memberRows()[1].querySelector('select'), 'viewer');

            expect(memberRows()[1].querySelector('select').value).toBe('editor');
            expect(badges(memberRows()[1])).toEqual(['Editor']);
            expect(notify).toHaveBeenCalledWith(
                'danger',
                expect.stringContaining('The role of the owner cannot be changed'),
            );
        });

        it('asks before removing a member, then removes them', async () => {
            http.delete.mockResolvedValue(null);
            const row = memberRows()[2];

            await press('Remove Vera from the workspace', row);

            expect(http.delete).not.toHaveBeenCalled();
            expect(row.textContent).toContain('Remove Vera from the workspace? They lose access at once.');

            // The confirmation takes the place of the controls.
            expect([...row.querySelectorAll('select')].filter(visible)).toHaveLength(0);

            await press('Remove', row);

            expect(http.delete).toHaveBeenCalledWith(`${MEMBERS_URL}/m-vera`);
            expect(memberRows().map((one) => one.querySelector('.fw-bold').textContent)).toEqual(['Olga', 'Edgar']);
            expect(notify).toHaveBeenCalledWith('success', 'Vera was removed from the workspace.');
        });

        it('lets the owner think again about removing a member', async () => {
            const row = memberRows()[2];

            await press('Remove Vera from the workspace', row);
            await press('Cancel', row);

            expect(http.delete).not.toHaveBeenCalled();
            expect([...row.querySelectorAll('select')].filter(visible)).toHaveLength(1);
            expect(memberRows()).toHaveLength(3);
        });

        it('asks before handing the ownership over, then does and reloads', async () => {
            http.post.mockResolvedValue({ data: { id: 'shop', role: 'editor' } });
            const row = memberRows()[1];

            await press('Make owner: Edgar', row);

            expect(http.post).not.toHaveBeenCalled();
            expect(row.textContent).toContain('Make Edgar the owner? You become an editor');

            await press('Make owner', row);

            expect(http.post).toHaveBeenCalledWith('/api/v1/workspaces/shop/transfer', { user_id: 'm-edgar' });
            expect(notify).toHaveBeenCalledWith('success', expect.stringContaining('Edgar is now the owner'));
            scheduleNavigation.mock.calls[0][0]();
            expect(reloadPage).toHaveBeenCalledTimes(1);
        });
    });

    describe('the invitations part of the members page', () => {
        beforeEach(async () => {
            await mount(['app/workspace-members.blade.php']);
        });

        it('lists the invitations that wait, with their role and who sent them', () => {
            expect(sentRows().map((row) => row.querySelector('.fw-bold').textContent)).toEqual([
                'new@example.test',
                'old@example.test',
            ]);
            expect(sentRows().map(badges)).toEqual([['Viewer'], ['Editor']]);
            expect(sentRows()[0].textContent).toContain('Invited by Olga · Expires <2026-11-01T10:00:00+00:00>');
            expect(http.get).toHaveBeenCalledWith(INVITATIONS_URL);
        });

        it('keeps the button that sends disabled until there is an address', async () => {
            const form = document.querySelector('form');
            const submit = form.querySelector('button[type="submit"]');

            expect(submit.disabled).toBe(true);

            await type(form.querySelector('input[type="email"]'), 'guest@example.test');

            expect(submit.disabled).toBe(false);
        });

        it('invites an address with the role that was chosen, and lists the invitation first', async () => {
            http.post.mockResolvedValue({ data: sent('i-3', 'guest@example.test', 'viewer') });
            const form = document.querySelector('form');

            await type(form.querySelector('input[type="email"]'), ' guest@example.test ');
            await choose(form.querySelector('select'), 'viewer');
            form.dispatchEvent(new Event('submit', { cancelable: true }));
            await flush();

            expect(http.post).toHaveBeenCalledWith(
                INVITATIONS_URL,
                { email: 'guest@example.test', role: 'viewer' },
                { silent: true },
            );
            expect(sentRows().map((row) => row.querySelector('.fw-bold').textContent)).toEqual([
                'guest@example.test',
                'new@example.test',
                'old@example.test',
            ]);
            expect(form.querySelector('input[type="email"]').value).toBe('');
        });

        it('shows what the server refused under the address', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid', {
                    errors: { email: ['The email field must be a valid email address.'] },
                }),
            );
            const form = document.querySelector('form');

            await type(form.querySelector('input[type="email"]'), 'nonsense');
            form.dispatchEvent(new Event('submit', { cancelable: true }));
            await flush();

            const input = form.querySelector('input[type="email"]');

            expect(input.classList.contains('is-invalid')).toBe(true);
            expect(input.getAttribute('aria-invalid')).toBe('true');
            expect(form.querySelector('.invalid-feedback').textContent).toBe(
                'The email field must be a valid email address.',
            );
        });

        it('tells in the form why a rule refused it', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Nope', { code: 'already_member', body: {} }));
            const form = document.querySelector('form');

            await type(form.querySelector('input[type="email"]'), 'edgar@example.test');
            form.dispatchEvent(new Event('submit', { cancelable: true }));
            await flush();

            const alert = form.querySelector('.alert-danger');

            expect(visible(alert)).toBe(true);
            expect(alert.textContent).toBe('This person already belongs to the workspace.');
        });

        it('sends an invitation again and says how long to wait when it comes too soon', async () => {
            http.post.mockResolvedValue({ data: sent('i-2', 'new@example.test', 'viewer') });

            await press('Send again: new@example.test', sentRows()[0]);

            expect(http.post).toHaveBeenCalledWith(`${INVITATIONS_URL}/i-2/resend`, undefined, { silent: true });
            expect(notify).toHaveBeenCalledWith('success', 'Invitation sent to new@example.test again.');

            http.post.mockRejectedValue(
                new HttpError(429, 'Slow', { code: 'resend_too_soon', retryAfter: 480, body: {} }),
            );
            await press('Send again: old@example.test', sentRows()[1]);

            expect(notify).toHaveBeenCalledWith(
                'danger',
                'This invitation was sent a moment ago: wait 8 minutes before sending it again.',
            );
        });

        it('asks before withdrawing an invitation, then withdraws it', async () => {
            http.delete.mockResolvedValue(null);
            const row = sentRows()[1];

            await press('Withdraw the invitation sent to old@example.test', row);

            expect(http.delete).not.toHaveBeenCalled();
            expect(row.textContent).toContain('Withdraw the invitation sent to old@example.test?');

            await press('Withdraw', row);

            expect(http.delete).toHaveBeenCalledWith(`${INVITATIONS_URL}/i-1`, { silent: true });
            expect(sentRows().map((one) => one.querySelector('.fw-bold').textContent)).toEqual(['new@example.test']);
        });

        it('says when no invitation waits', async () => {
            answers[INVITATIONS_URL] = { data: [] };
            await mount(['app/workspace-members.blade.php']);

            const empty = [...document.querySelectorAll('.list-group-item')].find((item) =>
                item.textContent.includes('No invitation is waiting for an answer.'),
            );

            expect(visible(empty)).toBe(true);
            expect(sentRows()).toHaveLength(0);
        });
    });

    describe('the members page, for another member', () => {
        beforeEach(async () => {
            answers[MEMBERS_URL] = { data: team({ asOwner: false }) };
            await mount(['app/workspace-members.blade.php'], { owner: false });
        });

        it('lists the members, with an address for nobody but the person', () => {
            expect(memberRows().map(badges)).toEqual([['Owner'], ['Editor', 'You'], ['Viewer']]);
            expect(memberRows().map((row) => visible(row.querySelector('.text-break:not(.fw-bold)')))).toEqual([
                false,
                false,
                false,
            ]);
        });

        it('offers no control of the owner, and no invitation', () => {
            expect(document.querySelector('select')).toBeNull();
            expect(document.querySelector('[x-data="workspaceInvitations"]')).toBeNull();
            expect(document.querySelector('form')).toBeNull();
            expect(http.get).not.toHaveBeenCalledWith(INVITATIONS_URL);
        });

        it('asks before leaving, then leaves and goes back to the space of the person', async () => {
            http.delete.mockResolvedValue(null);

            await press('Leave this workspace');

            expect(http.delete).not.toHaveBeenCalled();
            expect(document.body.textContent).toContain('Leave Shop? You lose access to its inventories');

            await press('Leave');

            expect(http.delete).toHaveBeenCalledWith(`${MEMBERS_URL}/m-edgar`);
            expect(notify).toHaveBeenCalledWith('success', 'You left Shop.');
            scheduleNavigation.mock.calls[0][0]();
            expect(redirectTo).toHaveBeenCalledWith('/app');
        });

        it('lets the person think again about leaving', async () => {
            await press('Leave this workspace');
            await press('Cancel');

            expect(buttons('Leave this workspace')).toHaveLength(1);
            expect(buttons('Leave')).toHaveLength(0);
            expect(http.delete).not.toHaveBeenCalled();
        });
    });

    describe('the page of invitations and the link of the header', () => {
        /** The link of the header: its element, and whether it is shown. */
        const link = () => document.querySelector('[x-data="invitationsLink"]');

        beforeEach(async () => {
            await mount(['components/invitations-link.blade.php', 'app/invitations.blade.php'], { waiting: 2 });
        });

        it('lists the invitations with the workspace, the role, who invited and until when', () => {
            expect(cards().map((card) => card.querySelector('.card-title').textContent)).toEqual([
                'Warehouse',
                'Garage',
            ]);
            expect(cards().map(badges)).toEqual([['Editor'], ['Viewer']]);
            expect(cards()[0].textContent).toContain('Invited by Olga');
            expect(cards()[0].textContent).toContain('Expires <2026-11-01T10:00:00+00:00>');
            expect(http.get).toHaveBeenCalledWith(MY_INVITATIONS_URL);
        });

        it('shows the link of the header with how many invitations wait', () => {
            expect(visible(link())).toBe(true);
            expect(link().querySelector('.badge').textContent).toBe('2');
            expect(link().querySelector('a').getAttribute('href')).toBe('/app/invitations');
            expect(link().querySelector('a').getAttribute('aria-label')).toBe('2 invitations waiting');
        });

        it('does not show the empty state while some invitations wait', () => {
            const empty = document.querySelector('.empty').closest('.card');

            expect(visible(empty)).toBe(false);
        });

        it('accepts an invitation and takes the person to the workspace', async () => {
            http.post.mockResolvedValue({ data: { id: 'ws-a', name: 'Warehouse', role: 'editor' } });

            await press('Accept the invitation to Warehouse');

            expect(http.post).toHaveBeenCalledWith(`${MY_INVITATIONS_URL}/a/accept`);
            expect(notify).toHaveBeenCalledWith('success', 'You joined Warehouse.');
            scheduleNavigation.mock.calls[0][0]();
            expect(redirectTo).toHaveBeenCalledWith('/app/w/ws-a');
            // Nothing else can be pressed meanwhile.
            expect(
                cards()
                    .flatMap((card) => [...card.querySelectorAll('button')])
                    .every((button) => button.disabled),
            ).toBe(true);
        });

        it('asks before declining, then declines and the link of the header follows', async () => {
            http.delete.mockResolvedValue(null);
            const card = cards()[1];

            await press('Decline the invitation to Garage', card);

            expect(http.delete).not.toHaveBeenCalled();
            expect(card.textContent).toContain('Decline the invitation to Garage?');

            await press('Decline', card);

            expect(http.delete).toHaveBeenCalledWith(`${MY_INVITATIONS_URL}/b`);
            expect(cards().map((one) => one.querySelector('.card-title').textContent)).toEqual(['Warehouse']);
            expect(link().querySelector('.badge').textContent).toBe('1');
            expect(link().querySelector('a').getAttribute('aria-label')).toBe('1 invitation waiting');
            expect(visible(link())).toBe(true);
        });

        it('shows the empty state and hides the link when the last invitation is declined', async () => {
            http.delete.mockResolvedValue(null);

            for (const name of ['Warehouse', 'Garage']) {
                const card = cards().find((one) => one.querySelector('.card-title').textContent === name);

                await press(`Decline the invitation to ${name}`, card);
                await press('Decline', card);
            }

            expect(cards()).toHaveLength(0);
            expect(visible(document.querySelector('.empty').closest('.card'))).toBe(true);
            expect(visible(link())).toBe(false);
        });

        it('lets the person think again about declining', async () => {
            const card = cards()[0];

            await press('Decline the invitation to Warehouse', card);
            await press('Cancel', card);

            expect(http.delete).not.toHaveBeenCalled();
            expect(buttons('Accept the invitation to Warehouse', card)).toHaveLength(1);
        });

        it('says it could not read the invitations, and tries again', async () => {
            answers[MY_INVITATIONS_URL] = { data: [] };
            http.get.mockImplementationOnce(async () => {
                throw new HttpError(500, 'Server error');
            });
            await mount(['app/invitations.blade.php']);

            const alert = document.querySelector('[role="alert"]');

            expect(visible(alert)).toBe(true);
            expect(visible(document.querySelector('.empty').closest('.card'))).toBe(false);

            await press('Try again');

            expect(visible(alert)).toBe(false);
            expect(visible(document.querySelector('.empty').closest('.card'))).toBe(true);
        });
    });
});
