import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { fillUrl, firstMessages, revealFirstRefusal, throttledMessage } from '../../../resources/js/lib/forms.js';

vi.mock('../../../resources/js/lib/i18n.js', () => ({
    t: (key, replacements = {}) =>
        Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(':' + name, value),
            {
                'error.too_many_requests': 'Slow down',
                'login.error.throttled': 'Wait :seconds seconds',
                'login.error.throttled_minutes': 'Wait :minutes minutes',
            }[key] ?? key,
        ),
}));

describe('firstMessages()', () => {
    it('keeps the first message of each field', () => {
        expect(firstMessages({ name: ['Required.', 'Too short.'], 'values.abc': ['Not a number.'] })).toEqual({
            name: 'Required.',
            'values.abc': 'Not a number.',
        });
    });

    it('drops a field without a message, and copes with nothing at all', () => {
        expect(firstMessages({ name: [], other: ['Oops.'] })).toEqual({ other: 'Oops.' });
        expect(firstMessages({})).toEqual({});
    });
});

describe('fillUrl()', () => {
    it('puts the identifier where the template has its placeholder', () => {
        expect(fillUrl('/app/w/__id__', '01HV')).toBe('/app/w/01HV');
        expect(fillUrl('/api/v1/inventories/tools/items/__id__', 'abc')).toBe('/api/v1/inventories/tools/items/abc');
    });

    it('cannot be made to leave the path by an identifier with slashes or dots', () => {
        expect(fillUrl('/app/w/__id__', '../admin')).toBe('/app/w/..%2Fadmin');
        expect(fillUrl('/app/w/__id__', 'a?b#c')).toBe('/app/w/a%3Fb%23c');
    });
});

describe('throttledMessage()', () => {
    it('tells the wait in seconds when it is short', () => {
        expect(throttledMessage(45)).toBe('Wait 45 seconds');
        expect(throttledMessage(120)).toBe('Wait 120 seconds');
    });

    it('tells the wait in minutes, rounded up, when it is long', () => {
        expect(throttledMessage(121)).toBe('Wait 3 minutes');
        expect(throttledMessage(3600)).toBe('Wait 60 minutes');
    });

    it('says to slow down when the server gave no wait', () => {
        expect(throttledMessage(null)).toBe('Slow down');
        expect(throttledMessage(0)).toBe('Slow down');
    });
});

describe('revealFirstRefusal()', () => {
    let scroll;

    /** Mounts a dialog on the page (the cursor only goes to what is in the document) and returns it. */
    function dialog(html) {
        document.body.innerHTML = `<div class="modal" id="dialog"><div class="modal-body">${html}</div></div>`;

        return document.getElementById('dialog');
    }

    beforeEach(() => {
        // jsdom has no layout, hence no scrollIntoView.
        scroll = vi.fn();
        Element.prototype.scrollIntoView = scroll;
    });

    afterEach(() => {
        delete Element.prototype.scrollIntoView;
        document.body.innerHTML = '';
    });

    it('scrolls to the first control that was refused and puts the cursor in it', () => {
        const root = dialog(`
            <input id="ok" class="form-control">
            <input id="first" class="form-control is-invalid">
            <div class="invalid-feedback">Taken.</div>
            <input id="second" class="form-control is-invalid">
        `);

        const revealed = revealFirstRefusal(root);

        expect(revealed).toBe(root.querySelector('#first'));
        expect(scroll).toHaveBeenCalledTimes(1);
        expect(scroll.mock.contexts[0]).toBe(root.querySelector('#first'));
        expect(scroll).toHaveBeenCalledWith({ block: 'center' });
        expect(document.activeElement).toBe(root.querySelector('#first'));
    });

    it('does not let the cursor scroll the dialog away from the refusal it just showed', () => {
        const root = dialog('<select id="kind" class="form-select is-invalid"></select>');
        const focus = vi.spyOn(root.querySelector('#kind'), 'focus');

        revealFirstRefusal(root);

        expect(focus).toHaveBeenCalledWith({ preventScroll: true });
    });

    it('goes to the first refusal in reading order, the message that opens the form included', () => {
        const root = dialog(`
            <div class="alert alert-danger" role="alert">The quota is reached.</div>
            <input id="name" class="form-control is-invalid">
        `);
        const before = document.activeElement;

        expect(revealFirstRefusal(root)).toBe(root.querySelector('.alert-danger'));
        expect(scroll.mock.contexts[0]).toBe(root.querySelector('.alert-danger'));
        // An alert cannot be typed in: the cursor stays where it was.
        expect(document.activeElement).toBe(before);
    });

    it('puts the cursor on an alert that asked for it with a tabindex', () => {
        const root = dialog('<div class="alert alert-danger" tabindex="-1">Nope.</div>');

        revealFirstRefusal(root);

        expect(document.activeElement).toBe(root.querySelector('.alert-danger'));
    });

    it('shows a message written under a control that is not marked, and puts the cursor in that control', () => {
        const root = dialog(`
            <div class="mb-3"><input id="plain" class="form-control"><span class="text-danger small"></span></div>
            <div class="mb-3">
                <button type="button" class="btn-close" aria-label="Remove"></button>
                <input id="tags" class="form-control">
                <div class="text-danger small mt-1">Too long.</div>
            </div>
        `);

        revealFirstRefusal(root);

        expect(scroll.mock.contexts[0]).toBe(root.querySelectorAll('.text-danger')[1]);
        expect(document.activeElement).toBe(root.querySelector('#tags'));
    });

    it('skips a control that cannot take the cursor when it looks for the one a message is about', () => {
        const root = dialog(`
            <div>
                <input id="locked" class="form-control" disabled>
                <input id="gone" class="form-control" style="display: none">
                <input id="file" type="file" hidden>
                <input id="open" class="form-control">
                <div class="text-danger">Duplicate.</div>
            </div>
        `);

        revealFirstRefusal(root);

        expect(document.activeElement).toBe(root.querySelector('#open'));
    });

    it('leaves the cursor alone for a message with no control under it, and for a control that is disabled', () => {
        const root = dialog(`
            <div><div class="text-danger">No control here.</div></div>
            <input id="locked" class="form-control is-invalid" disabled>
        `);
        const before = document.activeElement;

        expect(revealFirstRefusal(root)).toBe(root.querySelector('.text-danger'));
        expect(document.activeElement).toBe(before);

        root.querySelector('.text-danger').textContent = '';

        expect(revealFirstRefusal(root)).toBe(root.querySelector('#locked'));
        expect(document.activeElement).toBe(before);
    });

    it('ignores the messages that are empty or hidden, whether by x-show, by :hidden or by a hidden ancestor', () => {
        const root = dialog(`
            <div class="invalid-feedback"></div>
            <div class="text-danger">   </div>
            <div class="text-danger" style="display: none">Hidden by x-show.</div>
            <div class="text-danger" hidden>Hidden by :hidden.</div>
            <div hidden><input class="form-control is-invalid"></div>
            <div style="display: none"><div class="alert-danger">Hidden with its parent.</div></div>
        `);

        expect(revealFirstRefusal(root)).toBeNull();
        expect(scroll).not.toHaveBeenCalled();
    });

    it('does nothing when nothing was refused, or when there is no dialog', () => {
        expect(revealFirstRefusal(dialog('<input class="form-control">'))).toBeNull();
        expect(revealFirstRefusal(null)).toBeNull();
        expect(revealFirstRefusal(undefined)).toBeNull();
        expect(scroll).not.toHaveBeenCalled();
    });

    it('still puts the cursor in the control where the browser cannot scroll an element into view', () => {
        delete Element.prototype.scrollIntoView;
        const root = dialog('<input id="name" class="form-control is-invalid">');

        revealFirstRefusal(root);

        expect(document.activeElement).toBe(root.querySelector('#name'));
    });
});
