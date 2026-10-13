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
