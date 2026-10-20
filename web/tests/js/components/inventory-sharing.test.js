import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventorySharing from '../../../resources/js/components/inventory-sharing.js';
import { HttpError, http, scheduleNavigation } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), delete: vi.fn() }, scheduleNavigation: vi.fn() };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));
vi.mock('../../../resources/js/grid/rows.js', () => ({ momentText: (value) => (value ? `<${value}>` : '') }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key, replacements = {}) => {
        const messages = {
            'sharing.untitled': 'Untitled link',
            'sharing.state.active': 'Active',
            'sharing.state.expired': 'Expired',
            'sharing.files.included': 'Photos and files shown',
            'sharing.files.excluded': 'Photos and files hidden',
            'sharing.copy_named': 'Copy the address of the link :name',
            'sharing.revoke_named': 'Revoke the link :name',
            'sharing.confirm_revoke': 'Revoke the link :name?',
            'admin.settings.select_hint': 'Selected: copy it with the keyboard',
            'sharing.notice.created': 'Link created.',
            'sharing.notice.revoked': 'Link revoked.',
            'sharing.error.limit': 'At most :count links',
            'sharing.error.disabled': 'Sharing is off',
            'tokens.created': 'Created :date',
            'tokens.last_used': 'Last used :date',
            'tokens.never_used': 'Never used',
            'tokens.expires': 'Expires :date',
            'tokens.never_expires': 'Never expires',
            'tokens.copy': 'Copy',
            'tokens.copied': 'Copied',
            'error.too_many_requests': 'Slow down',
            'login.error.throttled': 'Wait :seconds seconds',
            'login.error.throttled_minutes': 'Wait :minutes minutes',
        };

        return Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(':' + name, value),
            messages[key] ?? key,
        );
    },
}));

const URL = '/api/v1/inventories/inv1/share-links';

/** A share link as the API describes it. */
function link(id, overrides = {}) {
    return {
        id,
        label: `Link ${id}`,
        url: `https://inventaire.example/s/token-${id}`,
        include_files: true,
        expires_at: '2999-01-01T10:00:00+00:00',
        last_used_at: null,
        created_at: '2026-10-01T10:00:00+00:00',
        created_by: { id: 'u1', name: 'Ada', is_owner: true },
        ...overrides,
    };
}

/** An element that remembers it was focused or selected. */
function focusable(log, name) {
    return { focus: () => log.push(name), select: () => log.push(`${name}:select`) };
}

describe('share links page of an inventory', () => {
    let component;
    let log;
    let rowElements;

    beforeEach(() => {
        log = [];
        rowElements = [];
        component = inventorySharing();
        // Alpine injects these: the root element (with the data-* attributes and the elements the cursor goes to), $nextTick.
        component.$root = {
            dataset: { url: URL, enabled: '1', limit: '3' },
            querySelector: (selector) => {
                const [, name] = selector.match(/data-focus="(\w+)"/);

                return focusable(log, name);
            },
            querySelectorAll: (selector) => rowElements.filter((element) => selector.includes(`"${element.action}"`)),
        };
        component.$nextTick = (callback) => callback();
        component.rootElement = component.$root;
    });

    afterEach(() => {
        vi.useRealTimers();
        Reflect.deleteProperty(navigator, 'clipboard');
        Reflect.deleteProperty(document, 'execCommand');
    });

    describe('the list', () => {
        it('is read when the page opens, with what the page was rendered with', async () => {
            http.get.mockResolvedValue({ data: [link('b'), link('a')] });
            expect(component.loading).toBe(true);
            expect(component.empty).toBe(false);

            await component.init();

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.links.map((one) => one.id)).toEqual(['b', 'a']);
            expect(component.enabled).toBe(true);
            expect(component.limit).toBe(3);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);
        });

        it('knows sharing is turned off, and takes a page that says nothing for a limit of none', async () => {
            http.get.mockResolvedValue({ data: [] });
            component.$root.dataset = { url: URL, enabled: '0' };

            await component.init();

            expect(component.enabled).toBe(false);
            expect(component.limit).toBe(0);
            expect(component.atLimit).toBe(false);
        });

        it('says it is empty when the inventory has no link', async () => {
            http.get.mockResolvedValue({ data: [] });

            await component.init();

            expect(component.links).toEqual([]);
            expect(component.empty).toBe(true);
        });

        it('says it could not be read, without claiming that it is empty, and reads it again on demand', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server error'));
            await component.init();

            expect(component.loadFailed).toBe(true);
            expect(component.empty).toBe(false);
            expect(component.loading).toBe(false);

            http.get.mockResolvedValue({ data: [link('a')] });
            await component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.links).toHaveLength(1);
        });

        it('stays quiet when the request was cancelled', async () => {
            http.get.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.init();

            expect(component.loadFailed).toBe(false);
            expect(component.loading).toBe(false);
        });

        it('says everything about a link in words: its name, its state, the files, the dates', () => {
            const used = link('a', {
                last_used_at: '2026-10-05T08:00:00+00:00',
                expires_at: null,
                include_files: false,
            });

            expect(component.linkName(used)).toBe('Link a');
            expect(component.linkName(link('b', { label: null }))).toBe('Untitled link');
            expect(component.stateLabel(used)).toBe('Active');
            expect(component.stateClass(used)).toBe('bg-green-lt');
            expect(component.filesLabel(used)).toBe('Photos and files hidden');
            expect(component.filesLabel(link('a'))).toBe('Photos and files shown');
            expect(component.createdText(used)).toBe('Created <2026-10-01T10:00:00+00:00>');
            expect(component.lastUsedText(used)).toBe('Last used <2026-10-05T08:00:00+00:00>');
            expect(component.expiresText(used)).toBe('Never expires');
            expect(component.lastUsedText(link('b'))).toBe('Never used');
            expect(component.expiresText(link('b'))).toBe('Expires <2999-01-01T10:00:00+00:00>');
            expect(component.revokeLabel(used)).toBe('Revoke the link Link a');
            expect(component.copyNamed(used)).toBe('Copy the address of the link Link a');
            expect(component.confirmText(used)).toBe('Revoke the link Link a?');
        });

        it('tells a link that expired from one that works, and shows the address of the ones that work only', () => {
            const expired = link('a', { expires_at: '2000-01-01T00:00:00+00:00' });
            const unreadable = link('b', { url: null });
            const never = link('c', { expires_at: null });

            expect(component.isExpired(expired)).toBe(true);
            expect(component.isExpired(never)).toBe(false);
            expect(component.isExpired(link('d'))).toBe(false);
            expect(component.stateLabel(expired)).toBe('Expired');
            expect(component.stateClass(expired)).toBe('bg-secondary-lt');

            expect(component.showsUrl(link('d'))).toBe(true);
            expect(component.showsUrl(expired)).toBe(false);
            expect(component.showsUrl(unreadable)).toBe(false);
            // A link that works but whose address cannot be read back (the application key changed) is told apart from an expired one.
            expect(component.lacksUrl(unreadable)).toBe(true);
            expect(component.lacksUrl(expired)).toBe(false);
            expect(component.lacksUrl(link('d'))).toBe(false);
        });

        it('shows no address while the administrator has sharing turned off: it would answer "not found"', () => {
            component.enabled = false;

            expect(component.showsUrl(link('a'))).toBe(false);
            expect(component.lacksUrl(link('b', { url: null }))).toBe(false);
        });

        it('has all the links it may when the list is as long as the limit', async () => {
            http.get.mockResolvedValue({ data: [link('c'), link('b'), link('a')] });
            await component.init();

            expect(component.atLimit).toBe(true);
            expect(component.canCreate).toBe(false);
            expect(component.limitText).toBe('At most 3 links');

            component.links = component.links.slice(1);

            expect(component.atLimit).toBe(false);
            expect(component.canCreate).toBe(true);
            expect(component.limitText).toBe('');
        });
    });

    describe('the links that outlive their author', () => {
        it('tells a link made by somebody who is no longer an owner, or whose account is gone, from the others', () => {
            const owner = link('a');
            const demoted = link('b', { created_by: { id: 'u2', name: 'Bob', is_owner: false } });
            const deleted = link('c', { created_by: null });

            expect(component.byFormerMember(owner)).toBe(false);
            expect(component.byFormerMember(demoted)).toBe(true);
            expect(component.byFormerMember(deleted)).toBe(true);
            // An answer that does not say is not taken for an owner.
            expect(component.byFormerMember(link('d', { created_by: { id: 'u3', name: 'Cy' } }))).toBe(true);
        });

        it('does not count the links that expired towards the limit', async () => {
            const expired = (id) => link(id, { expires_at: '2000-01-01T00:00:00+00:00' });
            http.get.mockResolvedValue({ data: [expired('e'), expired('d'), link('c'), link('b'), expired('a')] });
            await component.init();

            expect(component.links).toHaveLength(5);
            expect(component.atLimit).toBe(false);
            expect(component.canCreate).toBe(true);
            expect(component.limitText).toBe('');

            component.links = [link('f'), ...component.links];

            expect(component.atLimit).toBe(true);
            expect(component.canCreate).toBe(false);
            expect(component.limitText).toBe('At most 3 links');
        });
    });

    describe('the creation form', () => {
        it('opens on the label, with the usual lifetime and the files shown', () => {
            component.open();

            expect(component.creating).toBe(true);
            expect(log).toEqual(['label']);
            expect(component.form).toEqual({ label: '', includeFiles: true, expires: '30' });
        });

        it('can be given up: it closes, forgets what was typed and gives the cursor back to its button', () => {
            component.open();
            component.form.label = 'Insurer';
            component.form.includeFiles = false;
            component.errors = { label: 'Oops' };
            component.formError = 'Oops';
            log.length = 0;

            component.cancel();

            expect(component.creating).toBe(false);
            expect(component.form).toEqual({ label: '', includeFiles: true, expires: '30' });
            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
            expect(log).toEqual(['open']);
        });

        it('makes the link, puts it first in the list, says so and selects its address', async () => {
            http.get.mockResolvedValue({ data: [link('a')] });
            await component.init();
            http.post.mockResolvedValue({ data: link('b', { label: 'Insurer', include_files: false }) });
            rowElements = [{ action: 'url', dataset: { linkId: 'b' }, ...focusable(log, 'url-b') }];
            component.open();
            component.form.label = '  Insurer ';
            component.form.includeFiles = false;
            component.form.expires = '90';
            log.length = 0;

            await component.create();

            expect(http.post).toHaveBeenCalledWith(
                URL,
                { label: 'Insurer', include_files: false, expires_in_days: 90 },
                { silent: true },
            );
            expect(component.links.map((one) => one.id)).toEqual(['b', 'a']);
            expect(component.creating).toBe(false);
            expect(component.form).toEqual({ label: '', includeFiles: true, expires: '30' });
            expect(component.announcement).toBe('Link created.');
            expect(component.busy).toBe(false);
            expect(log).toEqual(['url-b', 'url-b:select']);
        });

        it('sends no label when it is empty, and null for a link that never expires', async () => {
            http.post.mockResolvedValue({ data: link('a') });
            component.form.label = '   ';
            component.form.expires = '';

            await component.create();

            expect(http.post).toHaveBeenCalledWith(
                URL,
                { include_files: true, expires_in_days: null },
                { silent: true },
            );
        });

        it('sends the usual lifetime when the select is not touched', async () => {
            http.post.mockResolvedValue({ data: link('a') });

            await component.create();

            expect(http.post.mock.calls[0][1].expires_in_days).toBe(30);
        });

        it('sends nothing when the inventory has all the links it may', async () => {
            http.get.mockResolvedValue({ data: [link('c'), link('b'), link('a')] });
            await component.init();

            await component.create();

            expect(http.post).not.toHaveBeenCalled();
        });

        it('ignores a second submit while the request is in flight', async () => {
            http.post.mockResolvedValue({ data: link('a') });

            await Promise.all([component.create(), component.create()]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });
    });

    describe('when the creation is refused', () => {
        beforeEach(() => {
            component.open();
            component.form.label = 'Insurer';
            log.length = 0;
        });

        it('shows the validation messages next to their fields and puts the cursor on the label', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid', {
                    errors: { label: ['The label is too long.'], expires_in_days: ['The lifetime is not valid.'] },
                }),
            );

            await component.create();

            expect(component.errors).toEqual({
                label: 'The label is too long.',
                expires: 'The lifetime is not valid.',
            });
            expect(component.isInvalid('label')).toBe(true);
            expect(component.isInvalid('expires')).toBe(true);
            expect(component.formError).toBe('');
            expect(component.creating).toBe(true);
            expect(log).toEqual(['label']);
        });

        it('shows the message of the server in the alert when no field is named', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'The request is not valid.', { errors: { other: ['No.'] } }),
            );

            await component.create();

            expect(component.formError).toBe('The request is not valid.');
        });

        it('forgets the previous messages when the form is sent again', async () => {
            http.post.mockRejectedValueOnce(new HttpError(422, 'Invalid', { errors: { label: ['Too long.'] } }));
            await component.create();
            http.post.mockResolvedValue({ data: link('a') });

            await component.create();

            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
        });

        it('says how many links there can be when the limit is reached', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'share_link_limit_reached', body: { limit: 20 } }),
            );

            await component.create();

            expect(component.formError).toBe('At most 20 links');
            expect(component.creating).toBe(true);
        });

        it('closes the form and says so when the administrator turned sharing off meanwhile', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Conflict', { code: 'share_links_disabled' }));

            await component.create();

            expect(component.enabled).toBe(false);
            expect(component.creating).toBe(false);
            expect(component.form.label).toBe('');
            expect(notify).toHaveBeenCalledWith('warning', 'Sharing is off');
        });

        it('says how long to wait when creations come too fast, in minutes when it is long', async () => {
            http.post.mockRejectedValueOnce(new HttpError(429, 'Too many', { retryAfter: 90 }));
            await component.create();
            expect(component.formError).toBe('Wait 90 seconds');

            http.post.mockRejectedValueOnce(new HttpError(429, 'Too many', { retryAfter: 1800 }));
            await component.create();
            expect(component.formError).toBe('Wait 30 minutes');

            http.post.mockRejectedValueOnce(new HttpError(429, 'Too many'));
            await component.create();
            expect(component.formError).toBe('Slow down');
        });

        it('says the page is about to reload when the session is stale', async () => {
            http.post.mockRejectedValue(new HttpError(419, 'Your session has expired.'));

            await component.create();

            expect(component.formError).toBe('Your session has expired.');
            expect(scheduleNavigation).toHaveBeenCalledWith(reloadPage);
        });

        it('shows any other failure in the alert', async () => {
            http.post.mockRejectedValue(new HttpError(403, 'Only an owner of this workspace can share an inventory.'));

            await component.create();

            expect(component.formError).toBe('Only an owner of this workspace can share an inventory.');
        });

        it('stays quiet when the request was cancelled', async () => {
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.create();

            expect(component.formError).toBe('');
            expect(component.errors).toEqual({});
            expect(component.busy).toBe(false);
        });
    });

    describe('copying the address', () => {
        const address = link('a').url;

        beforeEach(() => {
            rowElements = [{ action: 'url', dataset: { linkId: 'a' }, ...focusable(log, 'url-a') }];
        });

        it('copies it, tells so on the button of that link and in the live region, and takes the label off after a moment', async () => {
            vi.useFakeTimers();
            const writeText = vi.fn().mockResolvedValue(undefined);
            Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
            expect(component.copyLabel(link('a'))).toBe('Copy');

            await component.copy(link('a'));

            expect(writeText).toHaveBeenCalledWith(address);
            expect(component.copyLabel(link('a'))).toBe('Copied');
            // Another link's button still says Copy.
            expect(component.copyLabel(link('b'))).toBe('Copy');
            expect(component.announcement).toBe('Copied');

            vi.advanceTimersByTime(2000);

            expect(component.copyLabel(link('a'))).toBe('Copy');
        });

        it('selects the address and copies the selection where the clipboard is not available', async () => {
            document.execCommand = vi.fn(() => true);

            await component.copy(link('a'));

            expect(log).toEqual(['url-a', 'url-a:select']);
            expect(document.execCommand).toHaveBeenCalledWith('copy');
            expect(component.announcement).toBe('Copied');
        });

        it('selects the address and says to copy it by hand when nothing else works', async () => {
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: vi.fn().mockRejectedValue(new DOMException('Denied', 'NotAllowedError')) },
                configurable: true,
            });
            document.execCommand = vi.fn(() => {
                throw new Error('Not supported');
            });

            await component.copy(link('a'));

            expect(log).toEqual(['url-a', 'url-a:select']);
            expect(component.copyLabel(link('a'))).toBe('Copy');
            expect(component.announcement).toBe('Selected: copy it with the keyboard');
        });

        it('selects the whole address when its box is clicked or focused', () => {
            const select = vi.fn();

            component.selectUrl({ target: { select } });

            expect(select).toHaveBeenCalled();
        });

        it('stops the timer of the button when the page is taken apart', async () => {
            vi.useFakeTimers();
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: vi.fn().mockResolvedValue(undefined) },
                configurable: true,
            });
            await component.copy(link('a'));

            expect(vi.getTimerCount()).toBe(1);

            component.destroy();

            expect(vi.getTimerCount()).toBe(0);
            expect(component.copiedTimer).toBeNull();
        });

        it('has nothing to stop when nothing was copied', () => {
            expect(() => component.destroy()).not.toThrow();
        });
    });

    describe('revoking a link', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: [link('c'), link('b'), link('a')] });
            await component.init();
            rowElements = [
                { action: 'confirm', dataset: { linkId: 'b' }, focus: () => log.push('confirm-b') },
                { action: 'revoke', dataset: { linkId: 'b' }, focus: () => log.push('revoke-b') },
                { action: 'revoke', dataset: { linkId: 'c' }, focus: () => log.push('revoke-c') },
            ];
        });

        it('asks for a confirmation in the row first, and puts the cursor on its button', () => {
            component.askRevoke(component.links[1]);

            expect(http.delete).not.toHaveBeenCalled();
            expect(component.isConfirming(component.links[1])).toBe(true);
            expect(component.isConfirming(component.links[0])).toBe(false);
            expect(log).toEqual(['confirm-b']);
        });

        it('can be given up, which gives the cursor back to the button of the row', () => {
            component.askRevoke(component.links[1]);
            log.length = 0;

            component.cancelRevoke();

            expect(component.confirmingId).toBeNull();
            expect(log).toEqual(['revoke-b']);
        });

        it('revokes it, takes it off the list, says so and puts the cursor on the heading', async () => {
            http.delete.mockResolvedValue(null);
            component.askRevoke(component.links[1]);
            log.length = 0;

            await component.revoke(component.links[1]);

            expect(http.delete).toHaveBeenCalledWith(`${URL}/b`);
            expect(component.links.map((one) => one.id)).toEqual(['c', 'a']);
            expect(component.confirmingId).toBeNull();
            expect(component.revokingId).toBeNull();
            expect(component.announcement).toBe('Link revoked.');
            expect(log).toEqual(['heading']);
        });

        it('ignores a second click while the request is in flight', async () => {
            http.delete.mockResolvedValue(null);

            await Promise.all([component.revoke(component.links[1]), component.revoke(component.links[1])]);

            expect(http.delete).toHaveBeenCalledTimes(1);
        });

        it('keeps the link and its confirmation, and says why, when it failed', async () => {
            http.delete.mockRejectedValue(new HttpError(403, 'Only an owner can do it.'));
            component.askRevoke(component.links[1]);

            await component.revoke(component.links[1]);

            expect(component.links).toHaveLength(3);
            expect(component.confirmingId).toBe('b');
            expect(component.revokingId).toBeNull();
            expect(notify).toHaveBeenCalledWith('danger', 'Only an owner can do it.');
        });

        it('does not repeat what the HTTP helper already told', async () => {
            const failure = new HttpError(0, 'Network error', { kind: 'network' });
            failure.reported = true;
            http.delete.mockRejectedValue(failure);
            notify.mockClear();

            await component.revoke(component.links[1]);

            expect(notify).not.toHaveBeenCalled();
            expect(component.links).toHaveLength(3);
        });

        it('takes a link that is already gone off the list', async () => {
            http.delete.mockRejectedValue(new HttpError(404, 'Not found'));

            await component.revoke(component.links[1]);

            expect(component.links.map((one) => one.id)).toEqual(['c', 'a']);
            expect(log).toEqual(['heading']);
        });

        it('stays quiet when the request was cancelled', async () => {
            http.delete.mockRejectedValue(new DOMException('Aborted', 'AbortError'));
            notify.mockClear();

            await component.revoke(component.links[1]);

            expect(component.links).toHaveLength(3);
            expect(notify).not.toHaveBeenCalled();
            expect(component.revokingId).toBeNull();
        });

        it('tells which link a row is revoking while the request is in flight', async () => {
            let finish;
            http.delete.mockReturnValue(new Promise((resolve) => (finish = resolve)));

            const pending = component.revoke(component.links[1]);

            expect(component.isRevoking(component.links[1])).toBe(true);
            expect(component.isRevoking(component.links[0])).toBe(false);

            finish(null);
            await pending;

            expect(component.isRevoking({ id: 'b' })).toBe(false);
        });
    });
});
