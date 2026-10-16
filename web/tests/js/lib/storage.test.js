import { beforeEach, describe, expect, it, vi } from 'vitest';

import { readPreference, writePreference } from '../../../resources/js/lib/storage.js';
import { useMemoryStorage, useRefusingStorage } from '../support/storage.js';

describe('the preferences kept in the browser', () => {
    beforeEach(() => {
        useMemoryStorage();
    });

    it('keeps what it is given and gives it back', () => {
        writePreference('theme', 'dark');

        expect(readPreference('theme')).toBe('dark');
    });

    it('has nothing for what was never kept', () => {
        expect(readPreference('missing')).toBeNull();
    });

    it('does not fail when the browser refuses to read or to write', () => {
        useRefusingStorage();

        expect(() => writePreference('theme', 'dark')).not.toThrow();
        expect(readPreference('theme')).toBeNull();
    });

    it('does not fail when the browser has no storage at all', () => {
        vi.stubGlobal('localStorage', undefined);

        expect(() => writePreference('theme', 'dark')).not.toThrow();
        expect(readPreference('theme')).toBeNull();
    });
});
