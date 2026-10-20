import { describe, expect, it, vi } from 'vitest';

import { roleClass, roleLabel } from '../../../resources/js/members/roles.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

describe('how a role is shown', () => {
    it('names the role in words, for a member and for an invitation alike', () => {
        expect(roleLabel({ role: 'owner' })).toBe('Owner');
        expect(roleLabel({ role: 'editor' })).toBe('Editor');
        expect(roleLabel({ role: 'viewer', email: 'a@example.test' })).toBe('Viewer');
    });

    it('gives each role its own badge, and a neutral one to a role it does not know', () => {
        expect(new Set(['owner', 'editor', 'viewer'].map((role) => roleClass({ role }))).size).toBe(3);
        expect(roleClass({ role: 'something else' })).toBe('bg-secondary-lt');
    });
});
