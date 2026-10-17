import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import accountTokens from '../../../resources/js/components/account-tokens.js';
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
            'tokens.ability.read': 'Read',
            'tokens.ability.write': 'Write',
            'tokens.ability.manage': 'Manage',
            'tokens.created': 'Created :date',
            'tokens.last_used': 'Last used :date',
            'tokens.never_used': 'Never used',
            'tokens.expires': 'Expires :date',
            'tokens.never_expires': 'Never expires',
            'tokens.revoke_named': 'Revoke the token :name',
            'tokens.confirm_revoke': 'Revoke :name?',
            'tokens.copy': 'Copy',
            'tokens.copied': 'Copied',
            'tokens.select_hint': 'Selected: copy it with the keyboard',
            'tokens.notice.revoked': 'Token revoked.',
            'tokens.error.limit': 'At most :count tokens',
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

const URL = '/api/v1/me/tokens';

/** A token as the API describes it. */
function token(id, overrides = {}) {
    return {
        id,
        name: `Token ${id}`,
        abilities: ['read'],
        created_at: '2026-10-01T10:00:00+00:00',
        last_used_at: null,
        expires_at: '2027-01-01T10:00:00+00:00',
        ...overrides,
    };
}

/** What `POST /me/tokens` answers: the token with its value. */
function created(id, overrides = {}) {
    return { data: { ...token(id), plain_text_token: 'inv_secret', ...overrides } };
}

/** An element that remembers it was focused. */
function focusable(log, name) {
    return { focus: () => log.push(name), select: () => log.push(`${name}:select`) };
}

describe('API tokens card of the account page', () => {
    let component;
    let log;
    let rowButtons;

    beforeEach(() => {
        log = [];
        rowButtons = [];
        component = accountTokens();
        // Alpine injects these: the root element (with the data-* attributes and the elements the cursor goes to), $nextTick.
        component.$root = {
            dataset: { url: URL },
            querySelector: (selector) => {
                const [, name] = selector.match(/data-focus="(\w+)"/);

                return focusable(log, name);
            },
            querySelectorAll: (selector) => rowButtons.filter((button) => selector.includes(button.action)),
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
        it('is read when the card opens, the newest first as the API gives it', async () => {
            http.get.mockResolvedValue({ data: [token(2), token(1)] });
            expect(component.loading).toBe(true);
            expect(component.empty).toBe(false);

            await component.init();

            expect(http.get).toHaveBeenCalledWith(URL);
            expect(component.tokens.map((one) => one.id)).toEqual([2, 1]);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);
        });

        it('says it is empty when the person has no token', async () => {
            http.get.mockResolvedValue({ data: [] });

            await component.init();

            expect(component.tokens).toEqual([]);
            expect(component.empty).toBe(true);
        });

        it('says it could not be read, without claiming that it is empty, and reads it again on demand', async () => {
            http.get.mockRejectedValueOnce(new HttpError(500, 'Server error'));
            await component.init();

            expect(component.loadFailed).toBe(true);
            expect(component.empty).toBe(false);
            expect(component.loading).toBe(false);

            http.get.mockResolvedValue({ data: [token(1)] });
            await component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.tokens).toHaveLength(1);
        });

        it('stays quiet when the request was cancelled', async () => {
            http.get.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.init();

            expect(component.loadFailed).toBe(false);
            expect(component.loading).toBe(false);
        });

        it('says everything about a token in words: permissions, dates, and never when there is none', () => {
            const used = token(1, {
                abilities: ['read', 'manage'],
                last_used_at: '2026-10-05T08:00:00+00:00',
                expires_at: null,
            });

            expect(used.abilities.map((ability) => component.abilityLabel(ability))).toEqual(['Read', 'Manage']);
            expect(component.abilityClass('read')).not.toBe(component.abilityClass('manage'));
            expect(component.createdText(used)).toBe('Created <2026-10-01T10:00:00+00:00>');
            expect(component.lastUsedText(used)).toBe('Last used <2026-10-05T08:00:00+00:00>');
            expect(component.expiresText(used)).toBe('Never expires');
            expect(component.lastUsedText(token(2))).toBe('Never used');
            expect(component.expiresText(token(2))).toBe('Expires <2027-01-01T10:00:00+00:00>');
            expect(component.revokeLabel(used)).toBe('Revoke the token Token 1');
            expect(component.confirmText(used)).toBe('Revoke Token 1?');
        });
    });

    describe('the creation form', () => {
        it('opens on the name, with the usual lifetime and the reading permission', () => {
            expect(component.canOpen).toBe(true);

            component.open();

            expect(component.creating).toBe(true);
            expect(component.canOpen).toBe(false);
            expect(log).toEqual(['name']);
            expect(component.form).toEqual({ name: '', abilities: ['read'], expires: '90' });
        });

        it('can be given up: it closes, forgets what was typed and gives the cursor back to its button', () => {
            component.open();
            component.form.name = 'Stock sync';
            component.form.abilities = ['write'];
            component.errors = { name: 'Oops' };
            component.formError = 'Oops';
            log.length = 0;

            component.cancel();

            expect(component.creating).toBe(false);
            expect(component.form).toEqual({ name: '', abilities: ['read'], expires: '90' });
            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
            expect(log).toEqual(['open']);
        });

        it('needs a name and a permission, and nothing in flight', () => {
            expect(component.canCreate).toBe(false);

            component.form.name = '   ';
            expect(component.canCreate).toBe(false);

            component.form.name = 'Stock sync';
            expect(component.canCreate).toBe(true);

            component.form.abilities = [];
            expect(component.canCreate).toBe(false);

            component.form.abilities = ['write'];
            component.busy = true;
            expect(component.canCreate).toBe(false);
        });

        it('sends nothing when it cannot be submitted', async () => {
            component.form.name = '';

            await component.create();

            expect(http.post).not.toHaveBeenCalled();
        });

        it('makes the token, shows its value once, puts it first in the list and puts the cursor on it', async () => {
            http.get.mockResolvedValue({ data: [token(1)] });
            await component.init();
            http.post.mockResolvedValue(created(2, { name: 'Stock sync', abilities: ['read', 'write'] }));
            component.open();
            component.form.name = '  Stock sync ';
            component.form.abilities = ['read', 'write'];
            component.form.expires = '30';
            log.length = 0;

            await component.create();

            expect(http.post).toHaveBeenCalledWith(
                URL,
                { name: 'Stock sync', abilities: ['read', 'write'], expires_in_days: 30 },
                { silent: true },
            );
            expect(component.revealed).toBe('inv_secret');
            expect(component.hasRevealed).toBe(true);
            expect(component.creating).toBe(false);
            expect(component.canOpen).toBe(false);
            expect(component.tokens.map((one) => one.id)).toEqual([2, 1]);
            expect(component.tokens[0].name).toBe('Stock sync');
            expect(component.tokens[0]).not.toHaveProperty('plain_text_token');
            expect(JSON.stringify(component.tokens)).not.toContain('inv_secret');
            expect(component.form).toEqual({ name: '', abilities: ['read'], expires: '90' });
            expect(component.busy).toBe(false);
            expect(log).toEqual(['token', 'token:select']);
        });

        it('sends null for a token that never expires, and the usual lifetime when it is not touched', async () => {
            http.post.mockResolvedValue(created(1));
            component.form.name = 'A';
            await component.create();
            expect(http.post.mock.calls[0][1].expires_in_days).toBe(90);

            component.dismiss();
            component.form.name = 'B';
            component.form.expires = '';
            await component.create();
            expect(http.post.mock.calls[1][1].expires_in_days).toBeNull();
        });

        it('ignores a second submit while the request is in flight', async () => {
            http.post.mockResolvedValue(created(1));
            component.form.name = 'A';

            await Promise.all([component.create(), component.create()]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('gives the cursor back to the button that opens the form once the value is hidden, and forgets the value', async () => {
            http.post.mockResolvedValue(created(1));
            component.form.name = 'A';
            await component.create();
            log.length = 0;

            component.dismiss();

            expect(component.revealed).toBe('');
            expect(component.hasRevealed).toBe(false);
            expect(component.canOpen).toBe(true);
            expect(log).toEqual(['open']);
        });
    });

    describe('when the creation is refused', () => {
        beforeEach(() => {
            component.open();
            component.form.name = 'Stock sync';
            log.length = 0;
        });

        it('shows the validation messages next to their fields and puts the cursor on the name', async () => {
            const error = new HttpError(422, 'Invalid', {
                errors: {
                    name: ['The name is too long.'],
                    'abilities.1': ['This permission is not valid.'],
                    expires_in_days: ['The lifetime is not valid.'],
                },
            });
            http.post.mockRejectedValue(error);

            await component.create();

            expect(component.errors).toEqual({
                name: 'The name is too long.',
                abilities: 'This permission is not valid.',
                expires: 'The lifetime is not valid.',
            });
            expect(component.isInvalid('name')).toBe(true);
            expect(component.isInvalid('expires')).toBe(true);
            expect(component.formError).toBe('');
            expect(component.creating).toBe(true);
            expect(component.form.name).toBe('Stock sync');
            expect(component.revealed).toBe('');
            expect(component.busy).toBe(false);
            expect(log).toEqual(['name']);
        });

        it('takes the message of the abilities field itself before those of its entries', async () => {
            http.post.mockRejectedValue(new HttpError(422, 'Invalid', { errors: { abilities: ['Choose one.'] } }));

            await component.create();

            expect(component.errors.abilities).toBe('Choose one.');
            expect(component.isInvalid('abilities')).toBe(true);
            expect(component.isInvalid('name')).toBe(false);
            expect(log).toEqual([]);
        });

        it('shows the message of the server in the alert when no field is named', async () => {
            http.post.mockRejectedValue(new HttpError(422, 'The data was invalid.'));

            await component.create();

            expect(component.formError).toBe('The data was invalid.');
        });

        it('forgets the previous messages when the form is sent again', async () => {
            http.post.mockRejectedValueOnce(new HttpError(422, 'Invalid', { errors: { name: ['Oops.'] } }));
            await component.create();
            http.post.mockResolvedValue(created(1));

            await component.create();

            expect(component.errors).toEqual({});
            expect(component.revealed).toBe('inv_secret');
        });

        it('says how many tokens there can be when the limit is reached', async () => {
            http.post.mockRejectedValue(
                new HttpError(409, 'Conflict', { code: 'token_limit_reached', body: { limit: 20 } }),
            );

            await component.create();

            expect(component.formError).toBe('At most 20 tokens');
            expect(component.creating).toBe(true);
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
            http.post.mockRejectedValue(new HttpError(500, 'The server hit an error.'));

            await component.create();

            expect(component.formError).toBe('The server hit an error.');
        });

        it('stays quiet when the request was cancelled', async () => {
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.create();

            expect(component.formError).toBe('');
            expect(component.errors).toEqual({});
            expect(component.busy).toBe(false);
        });
    });

    describe('copying the value', () => {
        beforeEach(async () => {
            http.post.mockResolvedValue(created(1));
            component.form.name = 'A';
            await component.create();
            // The live region announces in a later tick, which the stub above makes immediate.
            log.length = 0;
        });

        it('copies it, tells so on the button and in the live region, and takes the label off after a moment', async () => {
            vi.useFakeTimers();
            const writeText = vi.fn().mockResolvedValue(undefined);
            Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
            expect(component.copyLabel).toBe('Copy');

            await component.copy();

            expect(writeText).toHaveBeenCalledWith('inv_secret');
            expect(component.copied).toBe(true);
            expect(component.copyLabel).toBe('Copied');
            expect(component.announcement).toBe('Copied');

            vi.advanceTimersByTime(2000);

            expect(component.copied).toBe(false);
            expect(component.copyLabel).toBe('Copy');
        });

        it('selects the value and copies the selection where the clipboard is not available', async () => {
            document.execCommand = vi.fn(() => true);

            await component.copy();

            expect(log).toEqual(['token:select']);
            expect(document.execCommand).toHaveBeenCalledWith('copy');
            expect(component.copied).toBe(true);
            expect(component.announcement).toBe('Copied');
        });

        it('selects the value and says to copy it by hand when nothing else works', async () => {
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: vi.fn().mockRejectedValue(new DOMException('Denied', 'NotAllowedError')) },
                configurable: true,
            });
            document.execCommand = vi.fn(() => {
                throw new Error('Not supported');
            });

            await component.copy();

            expect(log).toEqual(['token:select']);
            expect(component.copied).toBe(false);
            expect(component.announcement).toBe('Selected: copy it with the keyboard');
        });

        it('says it is selected too when the browser does not know how to copy a selection', async () => {
            await component.copy();

            expect(component.copied).toBe(false);
            expect(component.announcement).toBe('Selected: copy it with the keyboard');
        });

        it('selects the whole value when it is clicked or focused', () => {
            component.select();

            expect(log).toEqual(['token:select']);
        });
    });

    describe('revoking a token', () => {
        beforeEach(async () => {
            http.get.mockResolvedValue({ data: [token(3), token(2), token(1)] });
            await component.init();
            rowButtons = [
                { action: 'confirm', dataset: { tokenId: '2' }, focus: () => log.push('confirm-2') },
                { action: 'revoke', dataset: { tokenId: '2' }, focus: () => log.push('revoke-2') },
                { action: 'revoke', dataset: { tokenId: '3' }, focus: () => log.push('revoke-3') },
            ];
        });

        it('asks for a confirmation in the row first, and puts the cursor on its button', () => {
            component.askRevoke(component.tokens[1]);

            expect(http.delete).not.toHaveBeenCalled();
            expect(component.isConfirming(component.tokens[1])).toBe(true);
            expect(component.isConfirming(component.tokens[0])).toBe(false);
            expect(log).toEqual(['confirm-2']);
        });

        it('can be given up, which gives the cursor back to the button of the row', () => {
            component.askRevoke(component.tokens[1]);
            log.length = 0;

            component.cancelRevoke();

            expect(component.confirmingId).toBeNull();
            expect(log).toEqual(['revoke-2']);
        });

        it('revokes it, takes it off the list, says so and puts the cursor on the list', async () => {
            http.delete.mockResolvedValue(null);
            component.askRevoke(component.tokens[1]);
            log.length = 0;

            await component.revoke(component.tokens[1]);

            expect(http.delete).toHaveBeenCalledWith(`${URL}/2`);
            expect(component.tokens.map((one) => one.id)).toEqual([3, 1]);
            expect(component.confirmingId).toBeNull();
            expect(component.revokingId).toBeNull();
            expect(component.announcement).toBe('Token revoked.');
            expect(log).toEqual(['heading']);
        });

        it('ignores a second click while the request is in flight', async () => {
            http.delete.mockResolvedValue(null);

            await Promise.all([component.revoke(component.tokens[1]), component.revoke(component.tokens[1])]);

            expect(http.delete).toHaveBeenCalledTimes(1);
        });

        it('keeps the token and its confirmation, and says why, when it failed', async () => {
            http.delete.mockRejectedValue(new HttpError(422, 'Cannot do it.'));
            component.askRevoke(component.tokens[1]);

            await component.revoke(component.tokens[1]);

            expect(notify).toHaveBeenCalledWith('danger', 'Cannot do it.');
            expect(component.tokens).toHaveLength(3);
            expect(component.confirmingId).toBe(2);
            expect(component.revokingId).toBeNull();
            expect(component.announcement).toBe('');
        });

        it('does not repeat what the HTTP helper already told', async () => {
            const error = new HttpError(500, 'The server hit an error.');
            error.reported = true;
            http.delete.mockRejectedValue(error);

            await component.revoke(component.tokens[1]);

            expect(notify).not.toHaveBeenCalled();
            expect(component.tokens).toHaveLength(3);
        });

        it('takes a token that is already gone off the list', async () => {
            const error = new HttpError(404, 'This item no longer exists.');
            error.reported = true;
            http.delete.mockRejectedValue(error);

            await component.revoke(component.tokens[0]);

            expect(component.tokens.map((one) => one.id)).toEqual([2, 1]);
            expect(notify).not.toHaveBeenCalled();
            expect(component.announcement).toBe('');
        });

        it('stays quiet when the request was cancelled', async () => {
            http.delete.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

            await component.revoke(component.tokens[1]);

            expect(notify).not.toHaveBeenCalled();
            expect(component.tokens).toHaveLength(3);
            expect(component.revokingId).toBeNull();
        });

        it('tells which token a row is revoking while the request is in flight', async () => {
            let finish;
            http.delete.mockReturnValue(new Promise((resolve) => (finish = resolve)));

            const pending = component.revoke(component.tokens[1]);

            expect(component.isRevoking(component.tokens[1])).toBe(true);
            expect(component.isRevoking(component.tokens[0])).toBe(false);

            finish(null);
            await pending;
            expect(component.isRevoking({ id: 2 })).toBe(false);
        });
    });
});
