import { describe, expect, it } from 'vitest';

import { compose } from '../../../resources/js/lib/compose.js';

describe('composing a component out of parts', () => {
    it('merges the state and the methods of the parts into one object that shares its this', () => {
        const component = compose(
            {
                count: 2,
                double() {
                    return this.count * 2;
                },
            },
            {
                label: 'x',
                describe() {
                    return this.label + this.double();
                },
            },
        );

        expect(component.describe()).toBe('x4');
    });

    it('keeps a getter computed instead of freezing the value it answered when the parts were merged', () => {
        const component = compose(
            { n: 1 },
            {
                get next() {
                    return this.n + 1;
                },
            },
        );

        expect(component.next).toBe(2);

        component.n = 10;

        expect(component.next).toBe(11);
        expect(Object.getOwnPropertyDescriptor(component, 'next').get).toBeTypeOf('function');
    });

    it('lets a later part replace what an earlier one defines', () => {
        const component = compose(
            {
                name: 'first',
                get title() {
                    return 'a';
                },
            },
            {
                name: 'second',
                get title() {
                    return 'b';
                },
            },
        );

        expect(component.name).toBe('second');
        expect(component.title).toBe('b');
    });

    it('does not change the parts', () => {
        const part = { n: 1 };

        compose(part, { m: 2 }).n = 5;

        expect(part).toEqual({ n: 1 });
    });
});
