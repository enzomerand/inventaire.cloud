import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import adminSettings from '../../../resources/js/components/admin-settings.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { put: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key) =>
        ({
            'admin.settings.notice.opened': 'Sign-ups are open',
            'admin.settings.notice.closed': 'Sign-ups are closed',
            'admin.settings.select_hint': 'The address is selected: copy it with your keyboard.',
            'tokens.copy': 'Copy',
            'tokens.copied': 'Copied',
        })[key] ?? key,
}));

const URL = '/api/v1/admin/settings';

describe('administration settings page', () => {
    let component;

    /** A checkbox the way the browser hands it to the change handler. */
    const box = (checked) => ({ target: { checked } });

    beforeEach(() => {
        component = adminSettings();
        // Alpine injects $root: the element carrying x-data and the data-* attributes.
        component.$root = { dataset: { url: URL, registrationOpen: '1' } };
        component.init();
    });

    it('starts from the value the page was rendered with', () => {
        expect(component.registrationOpen).toBe(true);

        component.$root.dataset.registrationOpen = '0';
        component.init();

        expect(component.registrationOpen).toBe(false);
    });

    it('closes registration and says so', async () => {
        http.put.mockResolvedValue({ data: { registration_open: false } });
        const checkbox = box(false);

        await component.toggleRegistration(checkbox);

        expect(http.put).toHaveBeenCalledWith(URL, { registration_open: false });
        expect(component.registrationOpen).toBe(false);
        expect(checkbox.target.checked).toBe(false);
        expect(notify).toHaveBeenCalledWith('success', 'Sign-ups are closed');
        expect(component.busy).toBe(false);
    });

    it('opens it again and says so', async () => {
        component.$root.dataset.registrationOpen = '0';
        component.init();
        http.put.mockResolvedValue({ data: { registration_open: true } });

        await component.toggleRegistration(box(true));

        expect(http.put).toHaveBeenCalledWith(URL, { registration_open: true });
        expect(component.registrationOpen).toBe(true);
        expect(notify).toHaveBeenCalledWith('success', 'Sign-ups are open');
    });

    it('shows what the server answers, whatever the switch said', async () => {
        http.put.mockResolvedValue({ data: { registration_open: true } });
        const checkbox = box(false);

        await component.toggleRegistration(checkbox);

        expect(component.registrationOpen).toBe(true);
        expect(checkbox.target.checked).toBe(true);
    });

    it('puts the switch back and says why when the change could not be saved', async () => {
        http.put.mockRejectedValue(new HttpError(422, 'The registration open field is required.'));
        const checkbox = box(false);

        await component.toggleRegistration(checkbox);

        expect(component.registrationOpen).toBe(true);
        expect(checkbox.target.checked).toBe(true);
        expect(notify).toHaveBeenCalledWith('danger', 'The registration open field is required.');
        expect(component.busy).toBe(false);
    });

    it('does not repeat what the HTTP helper already told the person', async () => {
        const failure = new HttpError(0, 'Network error', { kind: 'network' });
        failure.reported = true;
        http.put.mockRejectedValue(failure);
        const checkbox = box(false);

        await component.toggleRegistration(checkbox);

        expect(notify).not.toHaveBeenCalled();
        expect(checkbox.target.checked).toBe(true);
    });

    it('ignores a change made while another is being saved, and keeps the switch where it was', async () => {
        component.busy = true;
        const checkbox = box(false);

        await component.toggleRegistration(checkbox);

        expect(http.put).not.toHaveBeenCalled();
        expect(checkbox.target.checked).toBe(true);
    });

    describe('the state of the e-signature service', () => {
        const ADDRESS = 'https://inventaire.example/webhooks/documenso';

        beforeEach(() => {
            vi.useFakeTimers();
            component.$root.dataset.documensoConfigured = '1';
            component.$root.dataset.documensoSecret = '0';
            component.$root.dataset.webhookUrl = ADDRESS;
            component.$refs = { webhook: { select: vi.fn() } };
            component.$nextTick = (callback) => callback();
            component.init();
        });

        afterEach(() => {
            vi.useRealTimers();
            Reflect.deleteProperty(navigator, 'clipboard');
            Reflect.deleteProperty(document, 'execCommand');
        });

        it('reads whether the service and the webhook secret are set, and the address of the webhook', () => {
            expect(component.documensoConfigured).toBe(true);
            expect(component.webhookSecretConfigured).toBe(false);
            expect(component.webhookUrl).toBe(ADDRESS);
        });

        it('takes a page that says nothing for an instance with no service', () => {
            component.$root.dataset = { url: URL };
            component.init();

            expect(component.documensoConfigured).toBe(false);
            expect(component.webhookSecretConfigured).toBe(false);
            expect(component.webhookUrl).toBe('');
        });

        it('copies the address, says so for a moment, and tells it in the live region', async () => {
            const writeText = vi.fn().mockResolvedValue(undefined);

            Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

            await component.copyWebhook();

            expect(writeText).toHaveBeenCalledWith(ADDRESS);
            expect(component.copied).toBe(true);
            expect(component.copyLabel).toBe('Copied');
            expect(component.announcement).toBe('Copied');

            vi.advanceTimersByTime(2000);

            expect(component.copied).toBe(false);
            expect(component.copyLabel).toBe('Copy');
        });

        it('stops the timer of the button when the page is taken apart, so that nothing fires on a component that is gone', async () => {
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: vi.fn().mockResolvedValue(undefined) },
                configurable: true,
            });

            await component.copyWebhook();

            expect(component.copiedTimer).not.toBeNull();
            expect(vi.getTimerCount()).toBe(1);

            component.destroy();

            expect(vi.getTimerCount()).toBe(0);
            expect(component.copiedTimer).toBeNull();

            vi.advanceTimersByTime(5000);

            // The timer never fired: the button still says what it said when the component went.
            expect(component.copied).toBe(true);
        });

        it('has nothing to stop when nothing was copied', () => {
            expect(() => component.destroy()).not.toThrow();
            expect(component.copiedTimer).toBeNull();
        });

        it('selects the address and copies the selection when the page may not write to the clipboard', async () => {
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
                configurable: true,
            });
            document.execCommand = vi.fn().mockReturnValue(true);

            await component.copyWebhook();

            expect(component.$refs.webhook.select).toHaveBeenCalled();
            expect(document.execCommand).toHaveBeenCalledWith('copy');
            expect(component.copied).toBe(true);
        });

        it('tells to copy by hand when nothing could be copied', async () => {
            document.execCommand = vi.fn().mockReturnValue(false);

            await component.copyWebhook();

            expect(component.copied).toBe(false);
            expect(component.announcement).toBe('The address is selected: copy it with your keyboard.');
        });

        it('tells to copy by hand when the browser refuses the command', async () => {
            document.execCommand = vi.fn().mockImplementation(() => {
                throw new Error('not allowed');
            });

            await component.copyWebhook();

            expect(component.copied).toBe(false);
            expect(component.announcement).toBe('The address is selected: copy it with your keyboard.');
        });
    });
});
