import { beforeEach, describe, expect, it, vi } from 'vitest';

import installerToken from '../../../resources/js/components/installer-token.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { post: vi.fn() } };
});
vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));

describe('setup token form', () => {
    let component;

    beforeEach(() => {
        component = installerToken();
        // Alpine injects $root (the element carrying x-data and data-url).
        component.$root = { dataset: { url: '/install/token' } };
        component.token = 'abc123';
    });

    it('posts the token then reloads so the server renders the wizard', async () => {
        http.post.mockResolvedValue({ authorized: true });

        await component.submit();

        expect(http.post).toHaveBeenCalledWith('/install/token', { token: 'abc123' });
        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('shows the validation message of a wrong token on the field and stays on the page', async () => {
        http.post.mockRejectedValue(new HttpError(422, 'Invalid.', { errors: { token: ['Ce jeton est invalide.'] } }));

        await component.submit();

        expect(component.error).toBe('Ce jeton est invalide.');
        expect(component.invalidClass).toBe('is-invalid');
        expect(component.busy).toBe(false);
        expect(reloadPage).not.toHaveBeenCalled();
    });

    it('clears the previous error when submitting again', async () => {
        component.error = 'Old error';
        http.post.mockResolvedValue(null);

        await component.submit();

        expect(component.error).toBe('');
        expect(component.invalidClass).toBe('');
    });

    it('leaves throttling and server failures to the HTTP helper', async () => {
        http.post.mockRejectedValue(new HttpError(429, 'Too many attempts.'));

        await component.submit();

        expect(component.error).toBe('');
        expect(component.busy).toBe(false);
        expect(reloadPage).not.toHaveBeenCalled();
    });

    it('ignores a second submit while the first is in flight', async () => {
        http.post.mockResolvedValue(null);

        await Promise.all([component.submit(), component.submit()]);

        expect(http.post).toHaveBeenCalledTimes(1);
    });
});
