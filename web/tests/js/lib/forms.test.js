import { describe, expect, it, vi } from 'vitest';

import { fillUrl, firstMessages, throttledMessage } from '../../../resources/js/lib/forms.js';

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
