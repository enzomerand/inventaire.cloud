/**
 * Guards the Alpine CSP convention (see resources/js/app.js).
 *
 * The CSP build cannot run arbitrary JavaScript from attributes, so a template
 * that drifts (an arrow function, a template literal, a value interpolated by
 * Blade) would only fail in the browser, at runtime. These tests catch it early:
 * one scans every Blade template, the other mounts the real toast markup with
 * the real CSP build.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

const ROOT = join(import.meta.dirname, '../../..');
const VIEWS = join(ROOT, 'resources/views');

/** Lists every .blade.php file below a directory. */
function bladeFiles(directory) {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry);

        return statSync(path).isDirectory() ? bladeFiles(path) : path.endsWith('.blade.php') ? [path] : [];
    });
}

/** Removes Blade comments, which may legitimately mention forbidden syntax. */
function withoutBladeComments(source) {
    return source.replace(/\{\{--[\s\S]*?--\}\}/g, '');
}

/** Extracts `[attribute, expression]` pairs of every Alpine directive in a template. */
function alpineExpressions(source) {
    const attribute = /\s((?:x-[\w:.-]+)|(?::[\w:.-]+)|(?:@[\w:.-]+))\s*=\s*"([^"]*)"/g;

    return [...withoutBladeComments(source).matchAll(attribute)].map((match) => [match[1], match[2]]);
}

const FORBIDDEN = [
    [/=>/, 'arrow functions'],
    [/`/, 'template literals'],
    [/\bfunction\b/, 'function expressions'],
    [/\bnew\s/, 'constructors'],
    [/\btypeof\b/, 'typeof'],
    [/\bawait\b/, 'await'],
    [/\{\{|\{!!|@js|@json/, 'Blade interpolation inside an expression'],
    [/;/, 'statements'],
];

describe('Blade templates follow the Alpine CSP convention', () => {
    const templates = bladeFiles(VIEWS).map((file) => [relative(ROOT, file), readFileSync(file, 'utf8')]);

    it.each(templates)('%s only uses expressions the CSP build supports', (_file, source) => {
        for (const [attribute, expression] of alpineExpressions(source)) {
            for (const [pattern, label] of FORBIDDEN) {
                expect(expression, `${attribute}="${expression}" uses ${label}`).not.toMatch(pattern);
            }

            if (attribute === 'x-data') {
                expect(expression, 'x-data must name a component registered with Alpine.data()').toMatch(/^\w+$/);
            }
        }
    });

    it('finds the directives it is supposed to check', () => {
        const all = templates.flatMap(([, source]) => alpineExpressions(source));

        expect(all.length).toBeGreaterThan(5);
        expect(all.map(([attribute]) => attribute)).toContain('x-data');
    });
});

/**
 * Bootstrap and Tabler display utilities (`d-flex`, `d-md-block`, `d-none`...). Every one of them is declared
 * `!important`, so it beats the inline `display: none` that Alpine's `x-show` sets: the element would never hide.
 * The print variants (`d-print-*`) only apply to printed pages and are fine.
 */
const DISPLAY_UTILITY = /^d-(?!print-)[a-z-]+$/;

/**
 * Lists the opening tags of a template that carry `x-show` together with a display utility class.
 *
 * Tags are read with their attributes quoted-aware, so a tag spread over several lines, or holding a `>` inside an
 * expression (`x-show="items.length > 0"`), is read whole.
 *
 * @param {string} source Blade source of a template.
 * @returns {string[]} The offending tags, as written, each with the utilities it carries.
 */
function showWithDisplayUtility(source) {
    const tag = /<([a-zA-Z][\w:.-]*)((?:"[^"]*"|'[^']*'|[^>"'])*)>/g;
    const offenders = [];

    for (const [opening, name, attributes] of withoutBladeComments(source).matchAll(tag)) {
        const classes = attributes.match(/\sclass\s*=\s*"([^"]*)"/)?.[1].split(/\s+/) ?? [];
        const utilities = classes.filter((token) => DISPLAY_UTILITY.test(token));

        if (/\sx-show\s*=/.test(attributes) && utilities.length > 0) {
            offenders.push(`<${name}> with ${utilities.join(' ')}: ${opening.replace(/\s+/g, ' ').slice(0, 120)}`);
        }
    }

    return offenders;
}

describe('x-show is not defeated by a display utility', () => {
    const templates = bladeFiles(VIEWS).map((file) => [relative(ROOT, file), readFileSync(file, 'utf8')]);

    it.each(templates)(
        '%s never puts x-show and a d-* class on the same element (wrap the content in an inner element instead)',
        (_file, source) => {
            expect(showWithDisplayUtility(source)).toEqual([]);
        },
    );

    it('spots the combination, on one line or several, and leaves the rest alone', () => {
        const bad = `<form class="d-flex gap-2" x-show="isEditing(item)">`;
        const spread = `<div\n    class="card d-md-block"\n    x-show="items.length > 0"\n>`;
        const fine = [
            `<div class="d-flex gap-2"><span x-show="busy"></span></div>`,
            `<div class="d-print-none" x-show="open"></div>`,
            `<div class="btn" :class="{ 'd-flex': open }" x-show="open"></div>`,
            `<div class="d-flex"></div>`,
        ];

        expect(showWithDisplayUtility(bad)).toHaveLength(1);
        expect(showWithDisplayUtility(spread)).toHaveLength(1);

        for (const html of fine) {
            expect(showWithDisplayUtility(html), html).toEqual([]);
        }
    });
});

/**
 * Lists the `<legend>`s of a template that float (Bootstrap and Tabler declare `legend { float: left; width: 100% }`) and are
 * followed by a `<template>`.
 *
 * Their reboot only clears the element right after the legend (`legend + * { clear: left }`). A `<template>` is that element, and
 * what Alpine renders from it comes after: the first rendered element then floats beside the legend instead of under it.
 * `float-none` takes the legend out of that.
 *
 * @param {string} source Blade source of a template.
 * @returns {string[]} The offending legends, as written.
 */
function floatingLegendBeforeTemplate(source) {
    const legend = /<legend\b((?:"[^"]*"|'[^']*'|[^>"'])*)>(?:(?!<\/legend>)[\s\S])*<\/legend>\s*<template\b/g;
    const offenders = [];

    for (const [found, attributes] of withoutBladeComments(source).matchAll(legend)) {
        const classes = attributes.match(/\sclass\s*=\s*"([^"]*)"/)?.[1].split(/\s+/) ?? [];

        if (!classes.includes('float-none')) {
            offenders.push(found.replace(/\s+/g, ' ').slice(0, 120));
        }
    }

    return offenders;
}

describe('a legend followed by a template does not float', () => {
    const templates = bladeFiles(VIEWS).map((file) => [relative(ROOT, file), readFileSync(file, 'utf8')]);

    it.each(templates)(
        '%s never puts a floating <legend> right before a <template> (add float-none to the legend)',
        (_file, source) => {
            expect(floatingLegendBeforeTemplate(source)).toEqual([]);
        },
    );

    it('spots a legend without float-none before a template, whatever lies between them, and leaves the rest alone', () => {
        const bad = `<legend class="form-label" x-text="field.name"></legend>\n<template x-if="open"><div></div></template>`;
        const comment = `<legend class="form-label"></legend>\n{{-- the choices --}}\n<template x-for="one in all"></template>`;
        const fine = [
            `<legend class="form-label float-none" x-text="field.name"></legend>\n<template x-if="open"></template>`,
            `<legend class="form-label">Format</legend>\n<div class="form-check"></div>`,
            `<legend class="form-label">Format</legend><div><template x-if="open"></template></div>`,
        ];

        expect(floatingLegendBeforeTemplate(bad)).toHaveLength(1);
        expect(floatingLegendBeforeTemplate(comment)).toHaveLength(1);

        for (const html of fine) {
            expect(floatingLegendBeforeTemplate(html), html).toEqual([]);
        }
    });
});

/**
 * Turns the Blade source of the toast stack into plain HTML. Only the few
 * constructs it uses are supported; anything else fails loudly so this helper
 * is extended on purpose rather than silently mis-rendering.
 */
function renderToastsMarkup() {
    const read = (path) => withoutBladeComments(readFileSync(join(VIEWS, path), 'utf8'));
    const partial = read('partials/toast.blade.php').replace(/\{\{\s*__\('Close'\)\s*\}\}/g, 'Close');
    const html = read('components/toasts.blade.php').replace(/@include\('partials\.toast'\)/g, partial);

    expect(html, 'unsupported Blade syntax left in the toast templates').not.toMatch(/@\w+\(|\{\{|\{!!/);

    return html;
}

describe('toast stack under the real Alpine CSP build', () => {
    let Alpine;
    let notify;

    beforeAll(async () => {
        ({ default: Alpine } = await import('@alpinejs/csp'));
        const { default: toasts } = await import('../../../resources/js/components/toasts.js');
        const { createToastsStore } = await import('../../../resources/js/stores/toasts.js');
        ({ notify } = await import('../../../resources/js/lib/notify.js'));

        window.Alpine = Alpine;
        Alpine.store('toasts', createToastsStore());
        Alpine.data('toasts', toasts);
        Alpine.start();
    });

    afterAll(() => {
        Alpine.stopObservingMutations();
    });

    beforeEach(async () => {
        Alpine.store('toasts').items = [];
        document.body.innerHTML = renderToastsMarkup();
        await Alpine.nextTick();
    });

    /** Waits for Alpine to flush its DOM updates. */
    async function flush() {
        await Alpine.nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    it('renders polite toasts in the status region and urgent ones in the alert region', async () => {
        notify('success', 'Saved', { timeout: 0 });
        notify('danger', 'Broken', { timeout: 0 });
        await flush();

        const status = document.querySelector('[role="status"]');
        const alert = document.querySelector('[role="alert"]');

        expect(status.textContent).toContain('Saved');
        expect(status.textContent).not.toContain('Broken');
        expect(alert.textContent).toContain('Broken');
        expect(alert.querySelector('.toast').classList.contains('border-danger')).toBe(true);
        expect(status.querySelector('.toast').classList.contains('border-success')).toBe(true);
    });

    it('labels and wires the close button', async () => {
        notify('info', 'Hello', { timeout: 0 });
        await flush();

        const button = document.querySelector('.toast .btn-close');
        expect(button.getAttribute('aria-label')).toBe('Close');

        button.click();
        await flush();

        expect(document.querySelectorAll('.toast')).toHaveLength(0);
        expect(Alpine.store('toasts').items).toHaveLength(0);
    });
});
