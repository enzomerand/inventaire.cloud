import { describe, expect, it, vi } from 'vitest';

import flashNotice from '../../../resources/js/components/flash-notice.js';
import { notify } from '../../../resources/js/lib/notify.js';

vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));

/** A component mounted on a root carrying the given data-* attributes. */
function mounted(dataset) {
    const component = flashNotice();
    component.$root = { dataset };

    return component;
}

describe('flash notice', () => {
    it('shows the message the server left, once, as a toast of the given type', () => {
        mounted({ type: 'success', message: 'Your account has been deleted.' }).init();

        expect(notify).toHaveBeenCalledTimes(1);
        expect(notify).toHaveBeenCalledWith('success', 'Your account has been deleted.');
    });

    it('is an info toast when the markup gives no type', () => {
        mounted({ message: 'Hello' }).init();

        expect(notify).toHaveBeenCalledWith('info', 'Hello');
    });

    it('shows nothing when the markup holds no message', () => {
        mounted({ type: 'success' }).init();
        mounted({ type: 'success', message: '' }).init();

        expect(notify).not.toHaveBeenCalled();
    });
});
