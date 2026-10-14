/**
 * Alpine component of the install wizard.
 *
 * The server describes the whole wizard (steps, fields with their labels,
 * values, locks and errors) as JSON embedded in the page, so this component
 * only holds the interaction logic: which step is showing, sending a step to
 * its endpoint, mapping 422 errors back onto fields, the two-phase mail step
 * and the final redirect. No display text lives here: labels come from the
 * server, and the few messages scripts need are read with `t()`.
 *
 * The step endpoints are `{base}/{step id}`, `{base}/mail/verify`,
 * `{base}/documenso/skip`, `{base}/requirements` and `{base}/finalize`, where
 * `base` is the `data-base-url` of the component root.
 */
import { HttpError, http } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { redirectTo, reloadPage } from '../lib/page.js';

/** Id of the <script type="application/json"> element holding the initial state. */
const STATE_ELEMENT_ID = 'installer-state';

/** Server codes meaning the pending verification code can no longer be used. */
const DEAD_CODES = ['mail_code_missing', 'mail_code_expired', 'mail_code_exhausted'];

/**
 * @typedef {object} WizardField
 * @property {string} name
 * @property {string} type
 * @property {string | boolean} value
 * @property {boolean} secret
 * @property {boolean} secretSet
 * @property {boolean} locked
 * @property {boolean} masked Its value comes from a connection URL and is never shown.
 * @property {string | null} placeholder
 * @property {{ field: string, value: string } | null} showWhen
 * @property {string} error
 */

/**
 * @typedef {object} WizardStep
 * @property {string} id
 * @property {string} title
 * @property {string} status
 * @property {boolean} optional
 * @property {string} error
 * @property {WizardField[]} fields
 */

/**
 * Reads the JSON state the server embedded in the page.
 *
 * @returns {Record<string, any>}
 */
function readState() {
    const element = document.getElementById(STATE_ELEMENT_ID);

    try {
        return element ? JSON.parse(element.textContent ?? '{}') : {};
    } catch {
        return {};
    }
}

/**
 * @returns {Record<string, any>}
 */
export default function installer() {
    return {
        /** @type {WizardStep[]} */
        steps: [],

        /** Id of the step being shown. */
        current: 'requirements',

        /** @type {{ passed: boolean, items: Array<Record<string, any>> }} */
        requirements: { passed: false, items: [] },

        /** True while a request is in flight; blocks double submits. */
        busy: false,

        /** "form" while the mail settings are edited, "code" once a code was sent and awaits confirmation. */
        mailPhase: 'form',

        /** What was typed in the verification code box. */
        code: '',

        /** Validation message of the code box. */
        codeError: '',

        /** Whether every required step is done, i.e. the installation can run. */
        canFinalize: false,

        /** Bootstrap class flagging the code box, bound with `:class`. */
        get codeInvalidClass() {
            return this.codeError === '' ? '' : 'is-invalid';
        },

        /** Reads the state the server embedded in the page. */
        init() {
            this.applyState(readState());
        },

        /** Base URL of the wizard endpoints, from `data-base-url` on the component root. */
        get base() {
            return this.$root.dataset.baseUrl;
        },

        /**
         * Replaces the wizard with a state sent by the server.
         *
         * @param {Record<string, any>} state
         * @returns {void}
         */
        applyState(state) {
            this.steps = (state.steps ?? []).map((step) => ({ ...step, error: '' }));
            this.requirements = state.requirements ?? this.requirements;
            this.current = state.current ?? this.current;
            this.mailPhase = state.mailPending ? 'code' : 'form';
            this.canFinalize = Boolean(state.canFinalize);
        },

        /**
         * Updates the status of every step after a request.
         *
         * @param {{ statuses?: Record<string, string>, canFinalize?: boolean }} progress
         * @returns {void}
         */
        applyProgress(progress) {
            for (const step of this.steps) {
                step.status = progress.statuses?.[step.id] ?? step.status;
            }

            this.canFinalize = Boolean(progress.canFinalize);
        },

        /**
         * @param {WizardStep} step
         * @returns {boolean}
         */
        isCurrent(step) {
            return step.id === this.current;
        },

        /**
         * Tabler class of a step in the progress bar.
         *
         * @param {WizardStep} step
         * @returns {string}
         */
        stepClass(step) {
            return this.isCurrent(step) ? 'active' : '';
        },

        /**
         * Value of aria-current for a step in the progress bar (null removes the attribute).
         *
         * @param {WizardStep} step
         * @returns {string | null}
         */
        ariaCurrent(step) {
            return this.isCurrent(step) ? 'step' : null;
        },

        /**
         * @param {WizardStep} step
         * @returns {boolean}
         */
        hasFields(step) {
            return step.fields.length > 0;
        },

        /** Whether the form of a step is showing (the mail step swaps it for the code box). */
        showFields(step) {
            return this.hasFields(step) && !(step.id === 'mail' && this.mailPhase === 'code');
        },

        /**
         * @param {WizardStep} step
         * @returns {boolean}
         */
        showCode(step) {
            return step.id === 'mail' && this.mailPhase === 'code';
        },

        /**
         * Whether a field is displayed: some fields only matter for one value of another.
         *
         * @param {WizardStep} step
         * @param {WizardField} field
         * @returns {boolean}
         */
        isVisible(step, field) {
            if (!field.showWhen) {
                return true;
            }

            return step.fields.find((other) => other.name === field.showWhen.field)?.value === field.showWhen.value;
        },

        /**
         * Unique id tying a label to its input.
         *
         * @param {WizardStep} step
         * @param {WizardField} field
         * @returns {string}
         */
        fieldId(step, field) {
            return 'install-' + step.id + '-' + field.name;
        },

        /**
         * Bootstrap class flagging an input that has an error.
         *
         * @param {WizardField} field
         * @returns {string}
         */
        fieldClass(field) {
            return field.error === '' ? '' : 'is-invalid';
        },

        /**
         * Placeholder of an input: a mask for a secret that is already set.
         *
         * @param {WizardField} field
         * @returns {string}
         */
        placeholder(field) {
            return field.secretSet || field.masked ? '••••••••' : (field.placeholder ?? '');
        },

        /**
         * @param {{ status: string }} step
         * @returns {string}
         */
        statusLabel(step) {
            return t('install.status.' + step.status);
        },

        /**
         * Badge color of a step status in the summary.
         *
         * @param {{ status: string }} step
         * @returns {string}
         */
        statusClass(step) {
            return step.status === 'done'
                ? 'bg-green-lt'
                : step.status === 'skipped'
                  ? 'bg-secondary-lt'
                  : 'bg-yellow-lt';
        },

        /**
         * Whether a step appears in the summary of the last screen.
         *
         * @param {WizardStep} step
         * @returns {boolean}
         */
        isSummaryItem(step) {
            return step.id !== 'requirements' && step.id !== 'finalize';
        },

        /**
         * @param {{ ok: boolean }} item A requirement.
         * @returns {string}
         */
        requirementLabel(item) {
            return t(item.ok ? 'install.requirement.ok' : 'install.requirement.missing');
        },

        /**
         * @param {{ ok: boolean }} item A requirement.
         * @returns {string}
         */
        requirementClass(item) {
            return item.ok ? 'bg-green-lt' : 'bg-red-lt';
        },

        /**
         * Whether there is a step before the current one to go back to.
         *
         * @param {WizardStep} step
         * @returns {boolean}
         */
        hasPrevious(step) {
            return this.indexOf(step) > 0;
        },

        /**
         * Position of a step, found by id (the step objects the markup hands over are reactive proxies).
         *
         * @param {{ id: string }} step
         * @returns {number}
         */
        indexOf(step) {
            return this.steps.findIndex((candidate) => candidate.id === step.id);
        },

        /**
         * Goes back one step (the values stay as they were typed).
         *
         * @returns {void}
         */
        back() {
            const index = this.steps.findIndex((step) => step.id === this.current);

            if (index > 0) {
                this.current = this.steps[index - 1].id;
            }
        },

        /**
         * Moves to the step after the given one.
         *
         * @param {WizardStep} step
         * @returns {void}
         */
        advance(step) {
            const next = this.steps[this.indexOf(step) + 1];

            if (next) {
                this.current = next.id;
            }
        },

        /**
         * Re-runs the requirements check, e.g. after fixing a permission.
         *
         * @returns {Promise<void>}
         */
        async recheck() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const response = await http.get(this.base + '/requirements');

                this.requirements = response.requirements;
            } catch (error) {
                this.fail(null, error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Leaves the requirements screen once they are all met.
         *
         * @param {WizardStep} step
         * @returns {void}
         */
        continueFromRequirements(step) {
            if (this.requirements.passed) {
                this.advance(step);
            }
        },

        /**
         * Sends a step's form to its endpoint and moves on when it works.
         *
         * Locked fields are not sent: the server uses the value the environment
         * imposes. For the mail step success means "code sent", not "saved".
         *
         * @param {WizardStep} step
         * @returns {Promise<void>}
         */
        async submit(step) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.clearErrors(step);

            const payload = {};

            for (const field of step.fields) {
                if (!field.locked) {
                    payload[field.name] = field.value;
                }
            }

            try {
                const response = await http.post(this.base + '/' + step.id, payload);

                this.applyProgress(response);

                if (step.id === 'mail') {
                    this.mailPhase = 'code';
                    this.code = '';
                    this.codeError = '';
                    notify('success', t('install.mail.code_sent'));
                } else {
                    this.markSecretsSet(step);
                    this.advance(step);
                }

                // Non-blocking remarks arrive as codes and are translated here, never taken from the response.
                for (const code of response.warnings ?? []) {
                    notify('warning', t('install.warning.' + code));
                }
            } catch (error) {
                this.fail(step, error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Sends the verification code typed by the administrator.
         *
         * @returns {Promise<void>}
         */
        async verifyCode() {
            const step = this.steps.find((candidate) => candidate.id === 'mail');

            if (this.busy || !step) {
                return;
            }

            this.busy = true;
            this.codeError = '';

            try {
                const response = await http.post(this.base + '/mail/verify', { code: this.code });

                this.applyProgress(response);
                this.mailPhase = 'form';
                this.code = '';
                this.markSecretsSet(step);
                this.advance(step);
            } catch (error) {
                if (error instanceof HttpError && error.status === 422) {
                    this.codeError = error.errors.code?.[0] ?? error.message;

                    // A dead code cannot be retried: go back to the form to ask for another.
                    if (DEAD_CODES.includes(error.code)) {
                        this.mailPhase = 'form';
                    }
                } else {
                    this.fail(null, error);
                }
            } finally {
                this.busy = false;
            }
        },

        /**
         * Goes back from the code box to the mail settings.
         *
         * @returns {void}
         */
        editMail() {
            this.mailPhase = 'form';
            this.codeError = '';
        },

        /**
         * Skips the optional Documenso step.
         *
         * @param {WizardStep} step
         * @returns {Promise<void>}
         */
        async skip(step) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                this.applyProgress(await http.post(this.base + '/' + step.id + '/skip'));
                this.advance(step);
            } catch (error) {
                this.fail(step, error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Runs the installation, then leaves the wizard for the home page.
         *
         * @param {WizardStep} step The last screen.
         * @returns {Promise<void>}
         */
        async finalize(step) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            step.error = '';

            try {
                const response = await http.post(this.base + '/finalize', undefined, { timeout: 0 });

                notify('success', t('install.finished'));
                redirectTo(response.redirect);
            } catch (error) {
                this.busy = false;
                this.fail(step, error);
            }
        },

        /**
         * Clears the errors shown on a step.
         *
         * @param {WizardStep} step
         * @returns {void}
         */
        clearErrors(step) {
            step.error = '';

            for (const field of step.fields) {
                field.error = '';
            }
        },

        /**
         * Once a secret was saved, the box is emptied and shows it is set.
         *
         * @param {WizardStep} step
         * @returns {void}
         */
        markSecretsSet(step) {
            for (const field of step.fields) {
                if (field.secret && (field.secretSet || field.value !== '')) {
                    field.secretSet = true;
                    field.value = '';
                }
            }
        },

        /**
         * Shows a failed request: field errors inline, everything else as the
         * step's alert or a toast. Failures the HTTP helper already reported
         * are not repeated.
         *
         * @param {WizardStep | null} step The step the request belonged to, if any.
         * @param {unknown} error
         * @returns {void}
         */
        fail(step, error) {
            if (!(error instanceof HttpError)) {
                return;
            }

            // The session no longer proves the setup token (it was replaced or deleted): start over.
            if (error.status === 403 && error.code === 'setup_token_required') {
                reloadPage();

                return;
            }

            if (error.status === 422 && step) {
                this.showErrors(step, error.errors);

                return;
            }

            if (!error.reported) {
                notify('danger', error.message);
            }
        },

        /**
         * Maps a 422 `errors` object onto the step: one message per field, and
         * anything that is not a field of the step into its alert.
         *
         * @param {WizardStep} step
         * @param {Record<string, string[]>} errors
         * @returns {void}
         */
        showErrors(step, errors) {
            const names = step.fields.map((field) => field.name);
            const general = [];

            for (const field of step.fields) {
                field.error = errors[field.name]?.[0] ?? '';
            }

            for (const [name, messages] of Object.entries(errors)) {
                if (!names.includes(name)) {
                    general.push(messages[0]);
                }
            }

            step.error = general.join(' ');
        },
    };
}
