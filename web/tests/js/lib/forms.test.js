import { describe, expect, it } from 'vitest';

import { fillUrl, firstMessages } from '../../../resources/js/lib/forms.js';

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
