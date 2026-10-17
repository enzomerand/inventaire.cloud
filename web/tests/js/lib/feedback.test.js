import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { announce, copyValue } from '../../../resources/js/lib/feedback.js';

/**
 * A component as the helpers use it: the properties the markup reads and a `$nextTick`.
 *
 * @returns {Record<string, any>}
 */
function makeComponent() {
    return { announcement: '', status: '', copied: false, copiedTimer: null, $nextTick: (callback) => callback() };
}

describe('announce', () => {
    it('says the sentence in the live region of the component', () => {
        const component = makeComponent();

        announce(component, 'Done.');

        expect(component.announcement).toBe('Done.');
    });

    it('can speak through another property', () => {
        const component = makeComponent();

        announce(component, 'Done.', 'status');

        expect(component.status).toBe('Done.');
        expect(component.announcement).toBe('');
    });

    it('empties the region before it speaks, so that a sentence repeated is announced again', () => {
        const component = makeComponent();
        const seen = [];

        component.$nextTick = (callback) => {
            seen.push(component.announcement);
            callback();
        };

        announce(component, 'Done.');
        announce(component, 'Done.');

        expect(seen).toEqual(['', '']);
        expect(component.announcement).toBe('Done.');
    });
});

describe('copyValue', () => {
    let component;
    let select;
    let options;

    beforeEach(() => {
        vi.useFakeTimers();
        component = makeComponent();
        select = vi.fn();
        options = { select, copied: 'Copied', hint: 'Copy it by hand' };
        // jsdom has no clipboard and no `execCommand`: each test says what the browser does.
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        document.execCommand = undefined;
    });

    afterEach(() => {
        vi.useRealTimers();
        delete document.execCommand;
        delete navigator.clipboard;
    });

    it('copies the value, says so on the button and in the live region, and takes the label off after a moment', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);

        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

        await copyValue(component, 'secret', options);

        expect(writeText).toHaveBeenCalledWith('secret');
        expect(select).not.toHaveBeenCalled();
        expect(component.copied).toBe(true);
        expect(component.announcement).toBe('Copied');

        vi.advanceTimersByTime(1999);

        expect(component.copied).toBe(true);

        vi.advanceTimersByTime(1);

        expect(component.copied).toBe(false);
    });

    it('starts the delay again when it is copied twice', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: vi.fn().mockResolvedValue(undefined) },
            configurable: true,
        });

        await copyValue(component, 'secret', options);
        vi.advanceTimersByTime(1500);
        await copyValue(component, 'secret', options);
        vi.advanceTimersByTime(1500);

        expect(component.copied).toBe(true);
        expect(vi.getTimerCount()).toBe(1);

        vi.advanceTimersByTime(500);

        expect(component.copied).toBe(false);
    });

    it('selects the value and copies the selection where the clipboard is not allowed', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
            configurable: true,
        });
        document.execCommand = vi.fn().mockReturnValue(true);

        await copyValue(component, 'secret', options);

        expect(select).toHaveBeenCalledTimes(1);
        expect(document.execCommand).toHaveBeenCalledWith('copy');
        expect(component.copied).toBe(true);
        expect(component.announcement).toBe('Copied');
    });

    it('tells to copy by hand when the selection could not be copied, and keeps the button as it was', async () => {
        document.execCommand = vi.fn().mockReturnValue(false);

        await copyValue(component, 'secret', options);

        expect(select).toHaveBeenCalledTimes(1);
        expect(component.copied).toBe(false);
        expect(component.copiedTimer).toBeNull();
        expect(component.announcement).toBe('Copy it by hand');
    });

    it('tells to copy by hand when the browser refuses the command', async () => {
        document.execCommand = vi.fn().mockImplementation(() => {
            throw new Error('not allowed');
        });

        await copyValue(component, 'secret', options);

        expect(component.copied).toBe(false);
        expect(component.announcement).toBe('Copy it by hand');
    });
});
