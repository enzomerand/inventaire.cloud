import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { saveFile } from '../../../resources/js/lib/page.js';

describe('saveFile', () => {
    let clicked;

    beforeEach(() => {
        vi.useFakeTimers();
        clicked = [];
        // jsdom has no object URLs and does not navigate: stand in for both.
        URL.createObjectURL = vi.fn(() => 'blob:fake');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
            clicked.push({ href: this.href, download: this.download, attached: document.body.contains(this) });
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        delete URL.createObjectURL;
        delete URL.revokeObjectURL;
    });

    it('hands the content to the browser as a download with the given name', () => {
        const file = new Blob(['{}'], { type: 'application/json' });

        saveFile(file, 'export.json');

        expect(URL.createObjectURL).toHaveBeenCalledWith(file);
        expect(clicked).toEqual([{ href: 'blob:fake', download: 'export.json', attached: true }]);
    });

    it('leaves nothing behind: the link is removed and the object URL released', () => {
        saveFile(new Blob(['{}']), 'export.json');

        expect(document.querySelector('a[download]')).toBeNull();
        expect(URL.revokeObjectURL).not.toHaveBeenCalled();

        vi.runAllTimers();

        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:fake');
    });
});
