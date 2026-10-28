/**
 * What the photo entry shows of an analysis: the rows of the review (one per value Claude proposes), and the words of the failures
 * (an analysis that failed, a request that was refused).
 *
 * A proposal is only a proposal. The server has already checked its value against the rules of the field (`value` is what the field
 * stores), the browser shows it the way the table shows it, and the person decides row by row whether it goes into the form.
 * Everything in a row that Claude wrote (the evidence) is text: the template binds it with `x-text`, never as markup.
 */
import { controlOf, describeValue } from '../fields/index.js';
import { isBlank } from '../fields/types.js';
import { throttledMessage } from '../lib/forms.js';
import { t } from '../lib/i18n.js';

/** How long the dialog waits between two readings of an analysis that is on its way, in milliseconds: as for the exports. */
export { POLL_INTERVAL_MS } from '../export/exports.js';

/** How long the dialog follows one analysis before it gives up (the server gives up on its own at five minutes). */
export const MAX_WAIT_MS = 6 * 60 * 1000;

/** How many readings in a row may fail (the connection, the server) before the dialog stops following the analysis. */
export const MAX_POLL_FAILURES = 3;

/** The code the API gives to a request when the photo entry is off for the workspace (or the instance). */
const DISABLED_CODE = 'photo_entry_disabled';

/** The code the API gives to a request when the plan of the workspace does not include the photo entry (subscribing mends it). */
const NOT_IN_PLAN_CODE = 'photo_entry_not_in_plan';

/** The code the API gives to a request when the inventory has no field the photos could fill. */
const NO_FIELDS_CODE = 'no_fields_to_fill';

/** The code of the `429` that says the instance, not the person, is out of analyses for today. */
const INSTANCE_LIMIT_CODE = 'photo_analyses_instance_limit';

/** The statuses of a failure after which doing the same again cannot work: the session ended, the CSRF token is stale or the right is gone. */
export const STALE_STATUSES = [401, 403, 419];

/** The classes of the badge of each level of confidence. */
const LEVEL_CLASSES = {
    low: 'badge bg-orange-lt',
    medium: 'badge bg-yellow-lt',
    high: 'badge bg-green-lt',
};

/**
 * @typedef {import('../fields/types.js').Field} Field
 *
 * @typedef {object} Proposal A value Claude proposes for a field, as the API describes it.
 * @property {string} field The id of the field (lowercase ULID).
 * @property {any} value What the field stores.
 * @property {'low' | 'medium' | 'high'} confidence
 * @property {string} evidence Where on the photos the value was found, in a few words.
 *
 * @typedef {object} Analysis An analysis, as the API describes it.
 * @property {string} id
 * @property {'pending' | 'running' | 'completed' | 'failed'} status
 * @property {string | null} error The code of why it failed (`refused`, `truncated`, `invalid_answer`, `unavailable`, `misconfigured`, `failed`, `stale` or `disabled`), null when it did not.
 * @property {Proposal[]} proposals
 *
 * @typedef {object} Failure What the dialog says when something went wrong, and what it offers.
 * @property {string} text
 * @property {boolean} retry Whether trying again can end differently.
 * @property {boolean} resume Whether trying again is following the same analysis once more (not sending the photos again).
 */

/**
 * Whether the analysis has ended, done or failed.
 *
 * @param {Analysis} analysis
 * @returns {boolean}
 */
export function isEnded(analysis) {
    return analysis.status === 'completed' || analysis.status === 'failed';
}

/**
 * The level of confidence of a proposal, as the badge shows it. A level the interface does not know is the lowest.
 *
 * @param {string} level
 * @returns {{ level: 'low' | 'medium' | 'high', text: string, class: string }}
 */
export function confidenceOf(level) {
    const known = level === 'medium' || level === 'high' ? level : 'low';

    return { level: known, text: t(`photoEntry.confidence.${known}`), class: LEVEL_CLASSES[known] };
}

/**
 * The rows of the review: one for each proposal that is about a field of the inventory that holds a value (a field that was
 * deleted since, a photo or file field, a field proposed twice and a blank value are left out). A row is a cell, as the table
 * shows a value (see `../fields/cell.js`), with the name of the field, the evidence and the confidence added.
 *
 * @param {Proposal[]} proposals
 * @param {Field[]} fields The fields of the inventory.
 * @returns {Record<string, any>[]}
 */
export function reviewRows(proposals, fields) {
    /** @type {Record<string, any>[]} */
    const rows = [];

    for (const proposal of Array.isArray(proposals) ? proposals : []) {
        const field = fields.find((one) => one.id.toLowerCase() === String(proposal.field).toLowerCase());

        if (
            field === undefined ||
            controlOf(field) === 'files' ||
            isBlank(proposal.value) ||
            rows.some((row) => row.key === field.id)
        ) {
            continue;
        }

        const level = confidenceOf(proposal.confidence);

        rows.push({
            key: field.id,
            checkId: `photo-check-${field.id}`,
            nameId: `photo-name-${field.id}`,
            valueId: `photo-value-${field.id}`,
            levelId: `photo-level-${field.id}`,
            evidenceId: `photo-evidence-${field.id}`,
            // What the checkbox is called (the field and the value it would put there) and described by (how sure Claude is, and where
            // it read it): without the value, "Weight" would be all a screen reader says, and the person would tick a number unheard.
            labelledBy: `photo-name-${field.id} photo-value-${field.id}`,
            describedBy: `photo-level-${field.id} photo-evidence-${field.id}`,
            name: field.name,
            value: proposal.value,
            evidence: typeof proposal.evidence === 'string' ? proposal.evidence : '',
            level: level.level,
            levelText: level.text,
            levelClass: level.class,
            // A value is shown, not followed: nobody has checked the address Claude read yet.
            plain: true,
            ...describeValue(field, proposal.value, { full: true }),
        });
    }

    return rows;
}

/**
 * What each code the API gives as the `error` of a failed analysis (`App\PhotoEntry\PhotoAnalysisError`) says, and whether trying again
 * (sending the photos again) can end differently:
 *
 * - tried again: `truncated` (fewer photos may fit), `invalid_answer`, `unavailable`, `failed` and `stale` (the worker was lost, a new
 *   analysis has its own);
 * - never: `refused` (the same photos are declined again), `misconfigured` (only the administrator can mend it) and `disabled` (the
 *   feature was turned off, the next request is refused with `photo_entry_disabled`).
 *
 * A code that is not here (a later version of the server) is told as `failed`, with a retry.
 */
const ANALYSIS_FAILURES = {
    refused: ['photoEntry.error.refused', false],
    truncated: ['photoEntry.error.truncated', true],
    invalid_answer: ['photoEntry.error.invalid_answer', true],
    unavailable: ['photoEntry.error.unavailable', true],
    misconfigured: ['photoEntry.error.misconfigured', false],
    failed: ['photoEntry.error.failed', true],
    stale: ['photoEntry.error.stale', true],
    disabled: ['photoEntry.error.turned_off', false],
};

/**
 * What to say of an analysis that failed.
 *
 * @param {Analysis} analysis
 * @returns {Failure}
 */
export function failureOf(analysis) {
    const code = analysis.error ?? 'failed';
    // `hasOwn`: a code is read from the server, and `constructor` or `__proto__` are not entries of the table.
    const [key, retry] = Object.hasOwn(ANALYSIS_FAILURES, code) ? ANALYSIS_FAILURES[code] : ANALYSIS_FAILURES.failed;

    return { text: t(key), retry, resume: false };
}

/**
 * What to say of a request that was refused or that never arrived.
 *
 * @param {{ status: number, code?: string | number | null, message: string, errors?: Record<string, string[]>, retryAfter?: number | null }} error
 * @returns {Failure}
 */
export function requestFailure(error) {
    if (error.status === 409 && error.code === DISABLED_CODE) {
        return { text: t('photoEntry.error.disabled'), retry: false, resume: false };
    }

    if (error.status === 409 && error.code === NOT_IN_PLAN_CODE) {
        return { text: t('photoEntry.error.not_in_plan'), retry: false, resume: false };
    }

    if (error.status === 409 && error.code === NO_FIELDS_CODE) {
        return { text: t('photoEntry.error.no_fields'), retry: false, resume: false };
    }

    if (error.status === 429 && error.code === INSTANCE_LIMIT_CODE) {
        // Trying again now cannot work: it is the whole instance that has run its analyses of the day.
        return { text: t('photoEntry.error.instance_limit'), retry: false, resume: false };
    }

    if (error.status === 429) {
        return { text: throttledMessage(error.retryAfter ?? null), retry: true, resume: false };
    }

    if (error.status === 422) {
        const messages = Object.values(error.errors ?? {}).flat();

        return { text: messages[0] ?? error.message, retry: false, resume: false };
    }

    return { text: error.message, retry: true, resume: false };
}
