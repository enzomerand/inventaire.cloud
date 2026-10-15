import { beforeEach, describe, expect, it, vi } from 'vitest';

import { reloadPage } from '../../../resources/js/lib/page.js';
import { reloadWhenRestored } from '../../../resources/js/lib/restore.js';

vi.mock('../../../resources/js/lib/page.js', () => ({ reloadPage: vi.fn() }));

/** Fires `pageshow` the way the browser does, for a page it just loaded or one it brought back from its cache. */
function pageshow(persisted) {
    const event = new Event('pageshow');
    Object.defineProperty(event, 'persisted', { value: persisted });
    window.dispatchEvent(event);
}

describe('a page brought back from the back/forward cache', () => {
    beforeEach(() => {
        reloadWhenRestored();
    });

    it('is reloaded, since the cache gives it back as it was left: busy buttons, dead menus, stale tables', () => {
        pageshow(true);

        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('is left alone when the page was just loaded', () => {
        pageshow(false);

        expect(reloadPage).not.toHaveBeenCalled();
    });
});
