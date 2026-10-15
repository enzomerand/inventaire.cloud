import { beforeEach, describe, expect, it, vi } from 'vitest';

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
});
