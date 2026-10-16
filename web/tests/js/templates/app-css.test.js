/**
 * Guards the rules of resources/css/app.css that a layout test cannot see (jsdom computes no layout): the stylesheet is
 * read as text, and the declarations the interface depends on are checked.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

const STYLESHEET = readFileSync(join(import.meta.dirname, '../../../resources/css/app.css'), 'utf8');

/** What a rem is worth in CSS pixels (the root font size is the browser default; the app does not change it). */
const REM = 16;

/** The smallest side of a target that WCAG 2.5.8 accepts, in CSS pixels. */
const MINIMUM_TARGET = 24;

/**
 * The declarations of the rule of a selector, as a map from property to value.
 *
 * @param {string} selector
 * @returns {Record<string, string>}
 */
function declarationsOf(selector) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const rule = STYLESHEET.match(new RegExp(`(?:^|\\n)${escaped}\\s*\\{([^}]*)\\}`));

    expect(rule, `the rule of ${selector}`).not.toBeNull();

    return Object.fromEntries(
        rule[1]
            .split(';')
            .map((declaration) => declaration.split(/:(.*)/s).map((part) => part.trim()))
            .filter(([property, value]) => property !== '' && value !== undefined),
    );
}

/**
 * A length in CSS pixels, from the `px` or `rem` it is written in.
 *
 * @param {string | undefined} length
 * @returns {number}
 */
function pixels(length) {
    const match = /^(\d*\.?\d+)(px|rem)$/.exec(length ?? '');

    return match === null ? 0 : Number(match[1]) * (match[2] === 'rem' ? REM : 1);
}

describe('the remove button of a photo tile', () => {
    it('takes a click on a box of at least 24 by 24 CSS pixels, whatever the mark it draws', () => {
        const rule = declarationsOf('.app-tile-remove');

        // .btn-close is border-box with a 1 em side: only a width and a height widen it, and a padding wider than the side.
        expect(pixels(rule.width)).toBeGreaterThanOrEqual(MINIMUM_TARGET);
        expect(pixels(rule.height)).toBeGreaterThanOrEqual(MINIMUM_TARGET);
    });

    it('sits in the corner of its tile without widening it or spilling out of it', () => {
        const rule = declarationsOf('.app-tile-remove');

        expect(rule.position).toBe('absolute');
        // No negative offset: the box would stick out of the tile, and out of the dialog on a narrow screen.
        expect(pixels(rule.top)).toBeGreaterThanOrEqual(0);
        expect(pixels(rule['inset-inline-end'])).toBeGreaterThanOrEqual(0);
        expect(rule.top).not.toMatch(/^-/);
        expect(rule['inset-inline-end']).not.toMatch(/^-/);
    });
});
