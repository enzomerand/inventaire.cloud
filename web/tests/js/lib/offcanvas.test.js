import { beforeEach, describe, expect, it, vi } from 'vitest';

const instance = vi.hoisted(() => ({ element: null, show: vi.fn(), hide: vi.fn() }));

vi.mock('@tabler/core', () => ({
    Offcanvas: {
        getOrCreateInstance: vi.fn((element) => {
            instance.element = element;

            return instance;
        }),
        getInstance: vi.fn(() => instance),
    },
}));

const { Offcanvas } = await import('@tabler/core');
const { hideOffcanvas, showOffcanvas } = await import('../../../resources/js/lib/offcanvas.js');

/** A panel with a button that opens it. */
function panel() {
    document.body.innerHTML = `
        <button id="opener">Open</button>
        <div class="offcanvas"><input id="first"></div>`;

    return document.querySelector('.offcanvas');
}

describe('the side panels', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        // Bootstrap fires `show.bs.offcanvas` as it starts to open the panel, and nothing when it ignores the call.
        instance.show.mockImplementation(() => instance.element.dispatchEvent(new Event('show.bs.offcanvas')));
    });

    it('opens a panel', () => {
        const element = panel();

        showOffcanvas(element);

        expect(Offcanvas.getOrCreateInstance).toHaveBeenCalledWith(element);
        expect(instance.show).toHaveBeenCalledTimes(1);
    });

    it('gives the focus back to the button that opened it when it is closed, however it was', () => {
        const element = panel();
        const opener = document.querySelector('#opener');
        opener.focus();

        showOffcanvas(element);
        document.querySelector('#first').focus();
        element.dispatchEvent(new Event('hidden.bs.offcanvas'));

        expect(document.activeElement).toBe(opener);
    });

    it('does not take the focus back to the opener on a later closing: it is done once per opening', () => {
        const element = panel();
        document.querySelector('#opener').focus();
        showOffcanvas(element);
        element.dispatchEvent(new Event('hidden.bs.offcanvas'));
        document.querySelector('#first').focus();

        element.dispatchEvent(new Event('hidden.bs.offcanvas'));

        expect(document.activeElement).toBe(document.querySelector('#first'));
    });

    it('leaves the focus alone when the opener is no longer on the page', () => {
        const element = panel();
        const opener = document.querySelector('#opener');
        opener.focus();
        showOffcanvas(element);
        opener.remove();

        element.dispatchEvent(new Event('hidden.bs.offcanvas'));

        expect(document.activeElement).toBe(document.body);
    });

    it('adds no listener when Bootstrap ignores the call, as it does while the panel opens or closes', () => {
        const element = panel();
        const opener = document.querySelector('#opener');
        opener.focus();
        instance.show.mockImplementationOnce(() => {});

        showOffcanvas(element);
        document.querySelector('#first').focus();
        element.dispatchEvent(new Event('hidden.bs.offcanvas'));

        expect(document.activeElement).toBe(document.querySelector('#first'));
    });

    it('closes a panel, and does nothing for one that was never opened', () => {
        const element = panel();

        hideOffcanvas(element);

        expect(Offcanvas.getInstance).toHaveBeenCalledWith(element);
        expect(instance.hide).toHaveBeenCalledTimes(1);

        Offcanvas.getInstance.mockReturnValueOnce(null);

        expect(() => hideOffcanvas(element)).not.toThrow();
    });
});
