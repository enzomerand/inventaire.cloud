/**
 * How the pages of the members and of the invitations show a role: its name and the color of its badge.
 */
import { t } from '../lib/i18n.js';

/** The badge colors of the roles (Tabler's light variants; a badge always says the role in words as well). */
const BADGE_CLASSES = {
    owner: 'bg-primary-lt',
    editor: 'bg-azure-lt',
    viewer: 'bg-secondary-lt',
};

/**
 * The name of a role, in the language of the page.
 *
 * @param {{ role: string }} subject A member, or an invitation (the role it gives).
 * @returns {string}
 */
export function roleLabel(subject) {
    return t(`workspaces.role.${subject.role}`);
}

/**
 * The classes of the badge of a role.
 *
 * @param {{ role: string }} subject A member, or an invitation (the role it gives).
 * @returns {string}
 */
export function roleClass(subject) {
    return BADGE_CLASSES[subject.role] ?? 'bg-secondary-lt';
}
