import { beforeEach, describe, expect, it, vi } from 'vitest';

const instance = vi.hoisted(() => ({ element: null, show: vi.fn(), hide: vi.fn() }));

vi.mock('@tabler/core', () => ({
    Modal: {
        getOrCreateInstance: vi.fn((element) => {
            instance.element = element;

            return instance;
        }),
        getInstance: vi.fn(() => instance),
    },
}));

const { Modal } = await import('@tabler/core');
const { hideModal, onModalHidden, showModal } = await import('../../../resources/js/lib/modal.js');

/** A dialog with a name box and a note box. */
function dialog() {
    document.body.innerHTML = `
        <button id="opener">Open</button>
        <div class="modal">
            <div class="modal-body">
                <input type="hidden" name="token">
                <input id="first">
                <textarea id="second" data-autofocus></textarea>
            </div>
        </div>`;

    return document.querySelector('.modal');
}

describe('the dialogs', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        // Bootstrap fires `show.bs.modal` as it starts to open the dialog, and nothing when it ignores the call.
        instance.show.mockImplementation(() => instance.element.dispatchEvent(new Event('show.bs.modal')));
    });

    it('opens a dialog', () => {
        const element = dialog();

        showModal(element);

        expect(Modal.getOrCreateInstance).toHaveBeenCalledWith(element);
        expect(instance.show).toHaveBeenCalledTimes(1);
    });

    it('puts the cursor in the input marked for it once the dialog is shown', () => {
        const element = dialog();

        showModal(element);
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#second'));
    });

    it('puts the cursor in the first input that is not hidden when none is marked', () => {
        const element = dialog();
        element.querySelector('#second').removeAttribute('data-autofocus');

        showModal(element);
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#first'));
    });

    it('gives the focus back to the button that opened it when it is closed, however it was', () => {
        const element = dialog();
        const opener = document.querySelector('#opener');
        opener.focus();

        showModal(element);
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).not.toBe(opener);

        element.dispatchEvent(new Event('hidden.bs.modal'));

        expect(document.activeElement).toBe(opener);
    });

    it('does not take the focus back to the opener on a later closing: it is done once per opening', () => {
        const element = dialog();
        const opener = document.querySelector('#opener');
        opener.focus();
        showModal(element);
        element.dispatchEvent(new Event('hidden.bs.modal'));
        document.querySelector('#first').focus();

        element.dispatchEvent(new Event('hidden.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#first'));
    });

    it('leaves the focus alone when the opener is no longer on the page', () => {
        const element = dialog();
        const opener = document.querySelector('#opener');
        opener.focus();
        showModal(element);
        opener.remove();

        element.dispatchEvent(new Event('hidden.bs.modal'));

        expect(document.activeElement).toBe(document.body);
    });

    it('has nothing to give the focus back to when it was opened from nothing in particular', () => {
        const element = dialog();

        showModal(element);

        expect(() => element.dispatchEvent(new Event('hidden.bs.modal'))).not.toThrow();
        expect(document.activeElement).toBe(document.body);
    });

    it('adds no listener when Bootstrap ignores the call, as it does while the dialog opens or closes', () => {
        const element = dialog();
        const opener = document.querySelector('#opener');
        opener.focus();
        instance.show.mockImplementationOnce(() => {});

        showModal(element);
        element.dispatchEvent(new Event('shown.bs.modal'));
        element.dispatchEvent(new Event('hidden.bs.modal'));

        // Nothing moved the cursor, and the listeners that would have waited for an opening that never came are not there:
        // the next, real opening is not met by the focus of a call that was ignored.
        expect(document.activeElement).toBe(opener);

        document.querySelector('#first').focus();
        showModal(element);
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#second'));
    });

    it('closes a dialog', () => {
        const element = dialog();

        hideModal(element);

        expect(instance.hide).toHaveBeenCalledTimes(1);
    });

    it('closes nothing that was never opened', () => {
        Modal.getInstance.mockReturnValueOnce(null);

        expect(() => hideModal(dialog())).not.toThrow();
        expect(instance.hide).not.toHaveBeenCalled();
    });

    it('tells when a dialog has been closed, however it was', () => {
        const element = dialog();
        const callback = vi.fn();

        onModalHidden(element, callback);
        element.dispatchEvent(new Event('hidden.bs.modal'));
        element.dispatchEvent(new Event('hidden.bs.modal'));

        expect(callback).toHaveBeenCalledTimes(2);
    });
});
