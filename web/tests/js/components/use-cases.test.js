import { describe, expect, it } from 'vitest';

import useCases from '../../../resources/js/components/use-cases.js';

/** An element carrying its rank in `data-index`, the way the buttons and the panels of the home page do. */
function element(index) {
    const node = document.createElement('div');
    node.dataset.index = String(index);

    return node;
}

/** The click event of a button carrying `data-index`. */
function click(index) {
    return { currentTarget: element(index) };
}

describe('the use cases of the home page', () => {
    it('starts on the first one', () => {
        const component = useCases();

        expect(component.index).toBe(0);
        expect(component.isCurrent(element(0))).toBe(true);
        expect(component.isOther(element(0))).toBe(false);
        expect(component.isCurrent(element(3))).toBe(false);
        expect(component.isOther(element(3))).toBe(true);
    });

    it('shows the one of the button that was clicked, and hides the others', () => {
        const component = useCases();

        component.select(click(4));

        expect(component.index).toBe(4);
        expect([0, 1, 2, 3, 4, 5].filter((index) => component.isCurrent(element(index)))).toEqual([4]);
        expect([0, 1, 2, 3, 4, 5].filter((index) => component.isOther(element(index)))).toEqual([0, 1, 2, 3, 5]);
    });

    it('can go back to the first one', () => {
        const component = useCases();

        component.select(click(2));
        component.select(click(0));

        expect(component.index).toBe(0);
    });

    it('ignores a rank that is not a whole number or is negative', () => {
        const component = useCases();

        component.select(click(2));

        for (const rank of ['', ' ', 'x', '1.5', '-1']) {
            component.select(click(rank));
        }

        // A button that does not carry its rank at all.
        component.select({ currentTarget: document.createElement('button') });

        expect(component.index).toBe(2);
    });
});
