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
const { fieldFocusSelectors } = await import('../../../resources/js/grid/rows.js');

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

    it('puts the cursor where the caller asks, the first of its selectors that finds something', () => {
        const element = dialog();
        element.querySelector('.modal-body').insertAdjacentHTML('beforeend', '<button id="star">Star</button>');

        showModal(element, { focus: ['#missing', '#star', '#first'] });
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#star'));
    });

    it('puts the cursor in the usual place when none of the selectors the caller gives finds anything', () => {
        const element = dialog();

        showModal(element, { focus: ['#missing', '.nothing'] });
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#second'));
    });

    it('skips a control that is hidden when the caller names where the cursor goes, and takes the next that shows', () => {
        const element = dialog();
        element
            .querySelector('.modal-body')
            .insertAdjacentHTML(
                'beforeend',
                '<button id="gone" style="display: none">Gone</button><button id="star">Star</button>',
            );

        showModal(element, { focus: ['#gone', '#star'] });
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#star'));
    });

    it('skips a control that is inside something hidden (a panel, an attribute, a class)', () => {
        const element = dialog();
        element
            .querySelector('.modal-body')
            .insertAdjacentHTML(
                'beforeend',
                '<div style="display: none"><button id="in-style">A</button></div>' +
                    '<div hidden><button id="in-attribute">B</button></div>' +
                    '<div class="hidden-by-class"><button id="in-class">C</button></div>' +
                    '<button id="visible">D</button>',
            );
        document.head.insertAdjacentHTML('beforeend', '<style>.hidden-by-class { display: none; }</style>');

        showModal(element, { focus: ['#in-style', '#in-attribute', '#in-class', '#visible'] });
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#visible'));
    });

    it('falls back to the usual place when every control the caller names is hidden', () => {
        const element = dialog();
        element
            .querySelector('.modal-body')
            .insertAdjacentHTML('beforeend', '<button id="gone" style="display: none">Gone</button>');

        showModal(element, { focus: ['#gone'] });
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#second'));
    });

    it('does not put the cursor on a hidden control in the usual place either', () => {
        const element = dialog();
        element.querySelector('#second').style.display = 'none';

        showModal(element);
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#first'));
    });

    it('opens on the first control that shows of a photo field that is full: its button that adds a photo is hidden', () => {
        const element = dialog();
        // A full photo field: the button that adds a photo is hidden (x-show), the ones that remove a photo are close buttons.
        element.querySelector('.modal-body').insertAdjacentHTML(
            'beforeend',
            `<div data-field="photos">
                <button type="button" class="btn-close" aria-label="Remove front.jpg"></button>
                <button type="button" id="item-field-photos" style="display: none">Add a photo</button>
            </div>`,
        );

        showModal(element, { focus: fieldFocusSelectors('photos') });
        element.dispatchEvent(new Event('shown.bs.modal'));

        // Nothing in the field can take the cursor: the dialog opens where it usually does.
        expect(document.activeElement).toBe(document.querySelector('#second'));
    });

    it('opens on the button that adds a photo when the field has room for one', () => {
        const element = dialog();
        element.querySelector('.modal-body').insertAdjacentHTML(
            'beforeend',
            `<div data-field="photos">
                <button type="button" class="btn-close" aria-label="Remove front.jpg"></button>
                <button type="button" id="item-field-photos">Add a photo</button>
            </div>`,
        );

        showModal(element, { focus: fieldFocusSelectors('photos') });
        element.dispatchEvent(new Event('shown.bs.modal'));

        expect(document.activeElement).toBe(document.querySelector('#item-field-photos'));
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
