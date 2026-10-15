import { beforeEach, describe, expect, it, vi } from 'vitest';

const instance = vi.hoisted(() => ({ show: vi.fn(), hide: vi.fn() }));

vi.mock('@tabler/core', () => ({
    Modal: {
        getOrCreateInstance: vi.fn(() => instance),
        getInstance: vi.fn(() => instance),
    },
}));

const { Modal } = await import('@tabler/core');
const { hideModal, onModalHidden, showModal } = await import('../../../resources/js/lib/modal.js');

/** A dialog with a name box and a note box. */
function dialog() {
    document.body.innerHTML = `
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
