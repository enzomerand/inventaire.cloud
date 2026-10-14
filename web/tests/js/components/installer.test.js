import { beforeEach, describe, expect, it, vi } from 'vitest';

import installer from '../../../resources/js/components/installer.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));

/** Builds a field description as the server sends it. */
function field(name, overrides = {}) {
    return {
        name,
        label: name,
        type: 'text',
        value: '',
        required: true,
        secret: false,
        secretSet: false,
        locked: false,
        help: null,
        placeholder: null,
        options: [],
        showWhen: null,
        error: '',
        ...overrides,
    };
}

/** A small but representative wizard state. */
function wizardState(overrides = {}) {
    return {
        current: 'database',
        requirements: { passed: true, items: [{ id: 'php', label: 'PHP', ok: true, detail: null }] },
        mailPending: false,
        canFinalize: false,
        steps: [
            { id: 'requirements', title: 'Requirements', status: 'done', optional: false, fields: [] },
            {
                id: 'database',
                title: 'Database',
                status: 'pending',
                optional: false,
                fields: [
                    field('driver', { type: 'select', value: 'pgsql' }),
                    field('host', { value: '127.0.0.1' }),
                    field('port', { type: 'number', value: '5432', locked: true }),
                    field('password', { type: 'password', secret: true, secretSet: true }),
                ],
            },
            {
                id: 'mail',
                title: 'Email',
                status: 'pending',
                optional: false,
                fields: [field('host', { value: 'smtp.test' }), field('password', { type: 'password', secret: true })],
            },
            { id: 'documenso', title: 'Signature', status: 'pending', optional: true, fields: [field('url')] },
            { id: 'finalize', title: 'Installation', status: 'pending', optional: false, fields: [] },
        ],
        ...overrides,
    };
}

/** Creates a component mounted on a page carrying the given state. */
function mount(state = wizardState()) {
    document.body.innerHTML = '';
    const script = document.createElement('script');
    script.type = 'application/json';
    script.id = 'installer-state';
    script.textContent = JSON.stringify(state);
    document.body.append(script);

    const component = installer();
    // Alpine injects $root (the element carrying x-data and data-base-url).
    component.$root = { dataset: { baseUrl: '/install' } };
    component.init();

    return component;
}

const progress = (statuses, canFinalize = false) => ({ statuses, canFinalize });

describe('installer wizard', () => {
    let wizard;

    beforeEach(() => {
        wizard = mount();
    });

    describe('state', () => {
        it('loads the state embedded in the page', () => {
            expect(wizard.steps.map((step) => step.id)).toEqual([
                'requirements',
                'database',
                'mail',
                'documenso',
                'finalize',
            ]);
            expect(wizard.current).toBe('database');
            expect(wizard.requirements.passed).toBe(true);
            expect(wizard.mailPhase).toBe('form');
            expect(wizard.steps.every((step) => step.error === '')).toBe(true);
        });

        it('tolerates a missing or broken state element', () => {
            document.body.innerHTML = '<script type="application/json" id="installer-state">{broken</script>';
            const broken = installer();
            broken.$root = { dataset: { baseUrl: '/install' } };

            broken.init();

            expect(broken.steps).toEqual([]);
        });

        it('resumes on the code box when a verification code is pending', () => {
            expect(mount(wizardState({ mailPending: true })).mailPhase).toBe('code');
        });

        it('reads the endpoint base from the markup', () => {
            expect(wizard.base).toBe('/install');
        });
    });

    describe('presentation helpers', () => {
        it('marks the current step in the progress bar', () => {
            const [requirements, database] = wizard.steps;

            expect(wizard.stepClass(database)).toBe('active');
            expect(wizard.stepClass(requirements)).toBe('');
            expect(wizard.ariaCurrent(database)).toBe('step');
            expect(wizard.ariaCurrent(requirements)).toBeNull();
        });

        it('builds ids and error classes for inputs', () => {
            const database = wizard.steps[1];
            const host = database.fields[1];

            expect(wizard.fieldId(database, host)).toBe('install-database-host');
            expect(wizard.fieldClass(host)).toBe('');

            host.error = 'Required.';

            expect(wizard.fieldClass(host)).toBe('is-invalid');
        });

        it('masks a value that comes from a connection URL', () => {
            expect(wizard.placeholder(field('host', { masked: true, locked: true }))).toBe('••••••••');
        });

        it('masks a secret that is already set', () => {
            const [, , , password] = wizard.steps[1].fields;

            expect(wizard.placeholder(password)).toBe('••••••••');
            expect(wizard.placeholder(field('x', { placeholder: 'https://example.com' }))).toBe('https://example.com');
            expect(wizard.placeholder(field('x'))).toBe('');
        });

        it('shows conditional fields only for the value they depend on', () => {
            const storage = {
                fields: [
                    field('disk', { value: 'local' }),
                    field('bucket', { showWhen: { field: 'disk', value: 's3' } }),
                ],
            };

            expect(wizard.isVisible(storage, storage.fields[1])).toBe(false);

            storage.fields[0].value = 's3';

            expect(wizard.isVisible(storage, storage.fields[1])).toBe(true);
            expect(wizard.isVisible(storage, storage.fields[0])).toBe(true);
        });

        it('chooses what the mail step shows', () => {
            const mail = wizard.steps[2];

            expect(wizard.showFields(mail)).toBe(true);
            expect(wizard.showCode(mail)).toBe(false);

            wizard.mailPhase = 'code';

            expect(wizard.showFields(mail)).toBe(false);
            expect(wizard.showCode(mail)).toBe(true);
            expect(wizard.showFields(wizard.steps[1])).toBe(true);
            expect(wizard.showFields(wizard.steps[4])).toBe(false);
        });

        it('labels statuses and requirements with the server messages', () => {
            expect(wizard.statusLabel({ status: 'done' })).toBe('install.status.done');
            expect(wizard.statusClass({ status: 'done' })).toBe('bg-green-lt');
            expect(wizard.statusClass({ status: 'skipped' })).toBe('bg-secondary-lt');
            expect(wizard.statusClass({ status: 'pending' })).toBe('bg-yellow-lt');
            expect(wizard.requirementLabel({ ok: false })).toBe('install.requirement.missing');
            expect(wizard.requirementClass({ ok: true })).toBe('bg-green-lt');
            expect(wizard.isSummaryItem(wizard.steps[1])).toBe(true);
            expect(wizard.isSummaryItem(wizard.steps[0])).toBe(false);
            expect(wizard.isSummaryItem(wizard.steps[4])).toBe(false);
        });
    });

    describe('navigation', () => {
        it('goes back one step and stops at the first', () => {
            wizard.back();
            expect(wizard.current).toBe('requirements');

            wizard.back();
            expect(wizard.current).toBe('requirements');
            expect(wizard.hasPrevious(wizard.steps[0])).toBe(false);
            expect(wizard.hasPrevious(wizard.steps[1])).toBe(true);
        });

        it('leaves the requirements only once they pass', () => {
            wizard.current = 'requirements';
            wizard.requirements.passed = false;
            wizard.continueFromRequirements(wizard.steps[0]);
            expect(wizard.current).toBe('requirements');

            wizard.requirements.passed = true;
            wizard.continueFromRequirements(wizard.steps[0]);
            expect(wizard.current).toBe('database');
        });

        it('checks the requirements again', async () => {
            http.get.mockResolvedValue({ requirements: { passed: false, items: [] } });

            await wizard.recheck();

            expect(http.get).toHaveBeenCalledWith('/install/requirements');
            expect(wizard.requirements.passed).toBe(false);
            expect(wizard.busy).toBe(false);
        });
    });

    describe('saving a step', () => {
        it('posts the unlocked fields, then moves to the next step', async () => {
            const database = wizard.steps[1];
            database.fields[1].value = 'db.internal';
            http.post.mockResolvedValue(progress({ database: 'done' }));

            await wizard.submit(database);

            // The locked port is left to the server, which uses the value the environment imposes.
            expect(http.post).toHaveBeenCalledWith('/install/database', {
                driver: 'pgsql',
                host: 'db.internal',
                password: '',
            });
            expect(database.status).toBe('done');
            expect(wizard.current).toBe('mail');
            expect(wizard.busy).toBe(false);
        });

        it('tells about non-blocking warnings by code, never with text taken from the response', async () => {
            http.post.mockResolvedValue({ ...progress({ database: 'done' }), warnings: ['documenso_insecure_url'] });

            await wizard.submit(wizard.steps[1]);

            expect(notify).toHaveBeenCalledWith('warning', 'install.warning.documenso_insecure_url');
        });

        it('empties a secret that was just saved and remembers it is set', async () => {
            const database = wizard.steps[1];
            database.fields[3].secretSet = false;
            database.fields[3].value = 'hunter2';
            http.post.mockResolvedValue(progress({ database: 'done' }));

            await wizard.submit(database);

            expect(database.fields[3]).toMatchObject({ value: '', secretSet: true });
        });

        it('shows 422 errors on their fields and in the alert, and stays on the step', async () => {
            const database = wizard.steps[1];
            http.post.mockRejectedValue(
                new HttpError(422, 'Invalid', {
                    errors: { host: ['Cannot connect.'], general: ['Something general.'], other: ['Elsewhere.'] },
                }),
            );

            await wizard.submit(database);

            expect(database.fields[1].error).toBe('Cannot connect.');
            expect(database.fields[0].error).toBe('');
            expect(database.error).toBe('Something general. Elsewhere.');
            expect(wizard.current).toBe('database');
            expect(wizard.busy).toBe(false);
        });

        it('clears the previous errors before sending again', async () => {
            const database = wizard.steps[1];
            database.error = 'Old';
            database.fields[1].error = 'Old';
            http.post.mockResolvedValue(progress({ database: 'done' }));

            await wizard.submit(database);

            expect(database.error).toBe('');
            expect(database.fields[1].error).toBe('');
        });

        it('does not repeat failures the HTTP helper already reported', async () => {
            const error = new HttpError(500, 'Boom');
            error.reported = true;
            http.post.mockRejectedValue(error);

            await wizard.submit(wizard.steps[1]);

            expect(notify).not.toHaveBeenCalled();
            expect(wizard.busy).toBe(false);
        });

        it('tells about other failures', async () => {
            http.post.mockRejectedValue(new HttpError(409, 'Conflict'));

            await wizard.submit(wizard.steps[1]);

            expect(notify).toHaveBeenCalledWith('danger', 'Conflict');
        });

        it('reloads when the setup token is no longer valid', async () => {
            http.post.mockRejectedValue(new HttpError(403, 'Forbidden', { code: 'setup_token_required' }));

            await wizard.submit(wizard.steps[1]);

            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('ignores a second submit while the first is in flight', async () => {
            http.post.mockResolvedValue(progress({ database: 'done' }));

            await Promise.all([wizard.submit(wizard.steps[1]), wizard.submit(wizard.steps[1])]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });
    });

    describe('mail step', () => {
        beforeEach(() => {
            wizard.current = 'mail';
        });

        it('shows the code box once the code was sent, without leaving the step', async () => {
            http.post.mockResolvedValue({ ...progress({}), mailPending: true });

            await wizard.submit(wizard.steps[2]);

            expect(http.post).toHaveBeenCalledWith('/install/mail', { host: 'smtp.test', password: '' });
            expect(wizard.mailPhase).toBe('code');
            expect(wizard.current).toBe('mail');
            expect(notify).toHaveBeenCalledWith('success', 'install.mail.code_sent');
        });

        it('saves and moves on when the code is right', async () => {
            wizard.mailPhase = 'code';
            wizard.code = '123456';
            http.post.mockResolvedValue({ ...progress({ mail: 'done' }), mailPending: false });

            await wizard.verifyCode();

            expect(http.post).toHaveBeenCalledWith('/install/mail/verify', { code: '123456' });
            expect(wizard.steps[2].status).toBe('done');
            expect(wizard.mailPhase).toBe('form');
            expect(wizard.current).toBe('documenso');
        });

        it('keeps the code box open on a wrong code', async () => {
            wizard.mailPhase = 'code';
            http.post.mockRejectedValue(
                new HttpError(422, 'Wrong', { errors: { code: ['Incorrect code.'] }, code: 'mail_code_wrong' }),
            );

            await wizard.verifyCode();

            expect(wizard.codeError).toBe('Incorrect code.');
            expect(wizard.codeInvalidClass).toBe('is-invalid');
            expect(wizard.mailPhase).toBe('code');
        });

        it.each(['mail_code_expired', 'mail_code_exhausted', 'mail_code_missing'])(
            'returns to the form when the code is dead (%s)',
            async (code) => {
                wizard.mailPhase = 'code';
                http.post.mockRejectedValue(
                    new HttpError(422, 'Dead', { errors: { code: ['Request a new one.'] }, code }),
                );

                await wizard.verifyCode();

                expect(wizard.mailPhase).toBe('form');
                expect(wizard.codeError).toBe('Request a new one.');
            },
        );

        it('goes back from the code box to the settings', () => {
            wizard.mailPhase = 'code';
            wizard.codeError = 'x';

            wizard.editMail();

            expect(wizard.mailPhase).toBe('form');
            expect(wizard.codeError).toBe('');
        });
    });

    describe('optional step', () => {
        it('can be skipped', async () => {
            wizard.current = 'documenso';
            http.post.mockResolvedValue(progress({ documenso: 'skipped' }, true));

            await wizard.skip(wizard.steps[3]);

            expect(http.post).toHaveBeenCalledWith('/install/documenso/skip');
            expect(wizard.steps[3].status).toBe('skipped');
            expect(wizard.canFinalize).toBe(true);
            expect(wizard.current).toBe('finalize');
        });
    });

    describe('finishing', () => {
        it('runs the installation without a timeout and goes to the home page', async () => {
            http.post.mockResolvedValue({ redirect: '/' });

            await wizard.finalize(wizard.steps[4]);

            expect(http.post).toHaveBeenCalledWith('/install/finalize', undefined, { timeout: 0 });
            expect(notify).toHaveBeenCalledWith('success', 'install.finished');
            expect(redirectTo).toHaveBeenCalledWith('/');
        });

        it('shows why the installation failed and lets the person retry', async () => {
            http.post.mockRejectedValue(
                new HttpError(422, 'Failed', { errors: { general: ['The database migration failed: boom'] } }),
            );

            await wizard.finalize(wizard.steps[4]);

            expect(wizard.steps[4].error).toBe('The database migration failed: boom');
            expect(wizard.busy).toBe(false);
            expect(redirectTo).not.toHaveBeenCalled();
        });
    });
});
