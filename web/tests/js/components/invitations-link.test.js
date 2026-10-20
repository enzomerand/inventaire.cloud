import { beforeEach, describe, expect, it, vi } from 'vitest';

import invitationsLink from '../../../resources/js/components/invitations-link.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

describe('the link to the invitations in the header', () => {
    let component;

    /** Mounts the component the way the server rendered it. */
    function mount(count) {
        component = invitationsLink();
        // Alpine injects the root element, which carries the count the server rendered.
        component.$root = { dataset: { count } };
        component.init();
    }

    beforeEach(() => {
        mount('3');
    });

    it('takes the count the server rendered', () => {
        expect(component.count).toBe(3);
        expect(component.visible).toBe(true);
    });

    it('says in words how many invitations wait, in the singular for one', () => {
        expect(component.label).toBe('3 invitations waiting');

        mount('1');
        expect(component.label).toBe('1 invitation waiting');
    });

    it('follows the page of invitations when the person answers one', () => {
        component.update({ detail: { count: 2 } });

        expect(component.count).toBe(2);
        expect(component.label).toBe('2 invitations waiting');
    });

    it('goes away when none is left', () => {
        component.update({ detail: { count: 0 } });

        expect(component.visible).toBe(false);
    });

    it.each([
        ['a negative number', { detail: { count: -4 } }],
        ['no count', { detail: {} }],
        ['no detail', {}],
        ['something that is not a number', { detail: { count: 'many' } }],
    ])('takes %s for none', (_name, event) => {
        component.update(event);

        expect(component.count).toBe(0);
        expect(component.visible).toBe(false);
    });

    it('takes a count it cannot read for none', () => {
        mount('lots');

        expect(component.count).toBe(0);
        expect(component.visible).toBe(false);
    });
});
