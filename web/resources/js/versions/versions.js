/**
 * What the page of the official versions knows about versions apart from the page itself: how far a version is (the statuses
 * and their badges), how a version and its signers are told in a card, what the request that freezes an inventory carries, how a
 * refusal of the API is read, and which changes of status are worth saying aloud.
 *
 * Nothing here talks to the server or to the page, so it is all plain functions of their arguments.
 */
import { formatSize } from '../fields/files.js';
import { momentText } from '../grid/rows.js';
import { firstMessages, throttledMessage } from '../lib/forms.js';
import { t, tn } from '../lib/i18n.js';

/** How long the page waits before it asks again while a version is on its way, in milliseconds. */
export const POLL_INTERVAL_MS = 2000;

/** The longest label a version takes (the API refuses more). */
export const MAX_LABEL_LENGTH = 120;

/** How many characters of the SHA-256 a card shows (the whole value is in the title and is what the copy button copies). */
const SHORT_HASH_LENGTH = 12;

/** What stands between the parts of what a card says of a version. */
const DETAIL_SEPARATOR = ' · ';

/** The statuses of a version whose PDF is not written yet. */
const GENERATING_STATUSES = ['pending', 'running'];

/** The statuses of a request for signatures that is not with the signers yet (it is queued, or being sent). */
const SIGNING_STATUSES = ['requested', 'sending'];

/** The badge colors of the statuses of a version (Tabler's light variants; a badge always says the status in words as well). */
const BADGE_CLASSES = {
    pending: 'bg-secondary-lt',
    running: 'bg-blue-lt',
    completed: 'bg-green-lt',
    failed: 'bg-red-lt',
};

/** The badge colors of the statuses of a request for signatures. */
const SIGNATURE_BADGE_CLASSES = {
    requested: 'bg-blue-lt',
    sending: 'bg-blue-lt',
    awaiting: 'bg-yellow-lt',
    completed: 'bg-green-lt',
    rejected: 'bg-orange-lt',
    cancelled: 'bg-secondary-lt',
    failed: 'bg-red-lt',
};

/** The badge colors of the statuses of a signer. */
const SIGNER_BADGE_CLASSES = {
    pending: 'bg-secondary-lt',
    signed: 'bg-green-lt',
    rejected: 'bg-red-lt',
};

/** The codes the API gives to the refusals the page words itself (the answer carries a code, never a wording to trust). */
const CODES = {
    versionLimit: 'version_limit_reached',
    storageLimit: 'storage_limit_reached',
    signatureUnavailable: 'signature_unavailable',
    notPending: 'signature_not_pending',
    notFailed: 'signature_not_failed',
};

/**
 * @typedef {object} SignerRecord A person asked to sign, as `InventoryVersionResource` describes them.
 * @property {string} name
 * @property {string} email
 * @property {'pending' | 'signed' | 'rejected'} status
 * @property {string | null} signed_at
 * @property {string | null} rejection_reason
 *
 * @typedef {object} VersionRecord An official version, as `InventoryVersionResource` describes it.
 * @property {string} id
 * @property {number} number
 * @property {string | null} label
 * @property {'pending' | 'running' | 'completed' | 'failed'} status
 * @property {string | null} error Why it failed, in the language of the person; null otherwise.
 * @property {string} frozen_at
 * @property {{ name: string }} author
 * @property {number} items_count
 * @property {number} fields_count
 * @property {{ sha256: string, size: number, download_url: string | null }} snapshot The frozen data; its address is null until the version is completed.
 * @property {null | { sha256: string, size: number, pages: number, download_url: string }} pdf
 * @property {null | {
 *     status: 'requested' | 'sending' | 'awaiting' | 'completed' | 'rejected' | 'cancelled' | 'failed',
 *     error: string | null,
 *     requested_at: string | null,
 *     completed_at: string | null,
 *     signers: SignerRecord[],
 *     signed_pdf: null | { sha256: string, size: number, download_url: string },
 * }} signature
 * @property {{ delete: boolean, cancel_signature: boolean, retry_signature: boolean }} abilities
 *
 * @typedef {object} SignerRow A signer as a card shows them.
 * @property {string} key
 * @property {string} name
 * @property {string} email
 * @property {string} statusLabel
 * @property {string} badgeClass
 * @property {string} detail When they signed, or why they refused ("" while they have not answered).
 *
 * @typedef {object} VersionRow A version as the page shows it.
 * @property {string} id
 * @property {number} number
 * @property {string} titleId The id of the heading of the card, which the card is labelled by.
 * @property {string} title "Version no. 3".
 * @property {string} label What the person called it ("" when they did not).
 * @property {string} details When it was frozen, by whom, how many items and fields.
 * @property {string} statusLabel
 * @property {string} badgeClass
 * @property {string} error Why it failed ("" when it did not).
 * @property {string} sha256 The whole SHA-256 of the data that was frozen.
 * @property {string} shortHash
 * @property {string} hashLabel What the button that copies the SHA-256 is called.
 * @property {string | null} pdfUrl Where the PDF is downloaded; null until it is written.
 * @property {string | null} dataUrl Where the data (JSON) is downloaded; null until the version is complete.
 * @property {string | null} signedUrl Where the signed PDF is downloaded; null until it is stored.
 * @property {string} pdfLabel
 * @property {string} dataLabel
 * @property {string} signedLabel
 * @property {string} cancelLabel
 * @property {string} retryLabel
 * @property {string} deleteLabel
 * @property {boolean} hasSignature Whether signatures were asked for.
 * @property {string} signatureLabel
 * @property {string} signatureBadgeClass
 * @property {string} signatureSummary How many signers have signed ("" when nobody was asked or the request is not with them yet).
 * @property {string} signatureDates When the request was sent and when it was completed.
 * @property {string} signatureError Why the request failed ("" when it did not).
 * @property {SignerRow[]} signers
 * @property {boolean} canCancel
 * @property {boolean} canRetry
 * @property {boolean} canDelete
 * @property {boolean} working Whether something is being done to it (an action of the person is on its way).
 */

/**
 * Whether the PDF of a version is not written yet: it waits for a worker or is being written.
 *
 * @param {Pick<VersionRecord, 'status'>} version
 * @returns {boolean}
 */
export function isGenerating(version) {
    return GENERATING_STATUSES.includes(version.status);
}

/**
 * Whether the request for signatures of a version is not with the signers yet: it is queued or being sent to Documenso.
 *
 * @param {{ signature?: { status: string } | null }} version
 * @returns {boolean}
 */
export function isSigning(version) {
    return Boolean(version.signature) && SIGNING_STATUSES.includes(version.signature.status);
}

/**
 * Whether something is still going on for a version that the page must keep asking about: its PDF or its request.
 *
 * @param {VersionRecord} version
 * @returns {boolean}
 */
export function isActive(version) {
    return isGenerating(version) || isSigning(version);
}

/**
 * Whether any version of a list is still on its way: the page keeps asking while one is.
 *
 * @param {VersionRecord[]} versions
 * @returns {boolean}
 */
export function anyActive(versions) {
    return versions.some(isActive);
}

/**
 * The first characters of a SHA-256, for a card.
 *
 * @param {unknown} sha256 The whole value.
 * @returns {string} "" when there is none.
 */
export function shortHash(sha256) {
    if (typeof sha256 !== 'string' || sha256 === '') {
        return '';
    }

    return sha256.length > SHORT_HASH_LENGTH ? `${sha256.slice(0, SHORT_HASH_LENGTH)}…` : sha256;
}

/**
 * The name of a version: "Version no. 3".
 *
 * @param {number} number
 * @returns {string}
 */
export function versionTitle(number) {
    return t('versions.title', { number });
}

/**
 * A person asked to sign, told for a card: their status, when they signed or why they refused.
 *
 * @param {SignerRecord} signer
 * @param {number} index Their place in the list of the signers of the version.
 * @returns {SignerRow}
 */
export function signerRowOf(signer, index) {
    const status = signer.status in SIGNER_BADGE_CLASSES ? signer.status : 'pending';
    let detail = '';

    if (status === 'signed' && signer.signed_at) {
        detail = t('versions.signer.signed_on', { date: momentText(signer.signed_at) });
    } else if (status === 'rejected' && signer.rejection_reason) {
        detail = t('versions.signer.reason', { reason: signer.rejection_reason });
    }

    return {
        key: `${index}:${signer.email}`,
        name: signer.name,
        email: signer.email,
        statusLabel: t(`versions.signer.status.${status}`),
        badgeClass: SIGNER_BADGE_CLASSES[status],
        detail,
    };
}

/**
 * The words that say what a version is, for its card: when it was frozen, by whom, and what it holds.
 *
 * @param {VersionRecord} version
 * @returns {string}
 */
function detailsOf(version) {
    const parts = [
        t('versions.row.frozen', { date: momentText(version.frozen_at) }),
        version.author?.name ? t('versions.row.author', { name: version.author.name }) : '',
        tn('inventories.items', version.items_count),
        tn('inventories.fields', version.fields_count),
        version.pdf ? formatSize(version.pdf.size) : '',
    ];

    return parts.filter((part) => part !== '').join(DETAIL_SEPARATOR);
}

/**
 * The request for signatures of a version, told for a card: its status, how many have signed, its dates and what failed.
 *
 * @param {VersionRecord} version
 * @returns {Pick<VersionRow, 'hasSignature' | 'signatureLabel' | 'signatureBadgeClass' | 'signatureSummary' | 'signatureDates' | 'signatureError' | 'signers'>}
 */
function signatureOf(version) {
    const signature = version.signature;

    if (!signature) {
        return {
            hasSignature: false,
            signatureLabel: '',
            signatureBadgeClass: SIGNATURE_BADGE_CLASSES.requested,
            signatureSummary: '',
            signatureDates: '',
            signatureError: '',
            signers: [],
        };
    }

    const signers = Array.isArray(signature.signers) ? signature.signers : [];
    const status = signature.status in SIGNATURE_BADGE_CLASSES ? signature.status : 'requested';
    const reachedSigners = ['awaiting', 'completed', 'rejected'].includes(status) && signers.length > 0;

    return {
        hasSignature: true,
        signatureLabel: t(`versions.signature.status.${status}`),
        signatureBadgeClass: SIGNATURE_BADGE_CLASSES[status],
        signatureSummary: reachedSigners
            ? t('versions.signature.progress', {
                  signed: signers.filter((signer) => signer.status === 'signed').length,
                  total: signers.length,
              })
            : '',
        signatureDates: [
            signature.requested_at
                ? t('versions.signature.requested_on', { date: momentText(signature.requested_at) })
                : '',
            signature.completed_at
                ? t('versions.signature.completed_on', { date: momentText(signature.completed_at) })
                : '',
        ]
            .filter((part) => part !== '')
            .join(DETAIL_SEPARATOR),
        signatureError: status === 'failed' ? (signature.error ?? '') : '',
        signers: signers.map(signerRowOf),
    };
}

/**
 * A version told for its card.
 *
 * @param {VersionRecord} version
 * @param {{ working?: boolean }} [options] Whether an action of the person on the version is on its way.
 * @returns {VersionRow}
 */
export function rowOf(version, { working = false } = {}) {
    const number = version.number;
    const completed = version.status === 'completed';
    const status = version.status in BADGE_CLASSES ? version.status : 'pending';
    const abilities = version.abilities ?? { delete: false, cancel_signature: false, retry_signature: false };
    const signedUrl = version.signature?.signed_pdf?.download_url ?? null;
    const sha256 = version.snapshot?.sha256 ?? '';

    return {
        id: version.id,
        number,
        titleId: `version-${version.id}-title`,
        title: versionTitle(number),
        label: version.label ?? '',
        details: detailsOf(version),
        statusLabel: t(`versions.status.${status}`),
        badgeClass: BADGE_CLASSES[status],
        error: version.status === 'failed' ? (version.error ?? '') : '',
        sha256,
        shortHash: shortHash(sha256),
        hashLabel: t('versions.hash.copy_named', { number }),
        pdfUrl: completed ? (version.pdf?.download_url ?? null) : null,
        dataUrl: completed ? (version.snapshot?.download_url ?? null) : null,
        signedUrl,
        pdfLabel: t('versions.action.pdf_named', { number }),
        dataLabel: t('versions.action.data_named', { number }),
        signedLabel: t('versions.action.signed_named', { number }),
        cancelLabel: t('versions.action.cancel_named', { number }),
        retryLabel: t('versions.action.retry_named', { number }),
        deleteLabel: t('versions.action.delete_named', { number }),
        ...signatureOf(version),
        canCancel: abilities.cancel_signature === true,
        canRetry: abilities.retry_signature === true,
        canDelete: abilities.delete === true,
        working,
    };
}

/**
 * One empty line of the list of signers of the form. The ids are what the labels of its two inputs point to.
 *
 * @param {number} key What identifies the line (it is never reused, whatever lines come and go).
 * @returns {{ key: number, name: string, email: string, nameId: string, emailId: string }}
 */
export function blankSigner(key) {
    return { key, name: '', email: '', nameId: `version-signer-${key}-name`, emailId: `version-signer-${key}-email` };
}

/**
 * The body of the request that freezes the inventory. The label is sent only when there is one; the signers only when the
 * person asked for signatures (every line, in order: the API reports its mistakes by the place of the line).
 *
 * @param {object} choice What the person typed.
 * @param {string} choice.label
 * @param {boolean} choice.sign Whether signatures are asked for.
 * @param {{ name: string, email: string }[]} choice.signers
 * @returns {{ label?: string, signers?: { name: string, email: string }[] }}
 */
export function requestBody({ label, sign, signers }) {
    /** @type {{ label?: string, signers?: { name: string, email: string }[] }} */
    const body = {};
    const trimmed = label.trim();

    if (trimmed !== '') {
        body.label = trimmed;
    }

    if (sign) {
        body.signers = signers.map((signer) => ({ name: signer.name.trim(), email: signer.email.trim() }));
    }

    return body;
}

/**
 * The message the form of a signer shows for one of its inputs.
 *
 * @param {Record<string, string>} errors The messages of a refused form, by name of the input in the API.
 * @param {number} index The place of the signer in the list sent.
 * @param {'name' | 'email'} input
 * @returns {string} "" when the input was not refused.
 */
export function signerError(errors, index, input) {
    return errors[`signers.${index}.${input}`] ?? '';
}

/**
 * What an HTTP failure of the request that freezes the inventory means for the form: the messages to show under its inputs, and
 * the sentence to show under the buttons when the refusal is not about an input.
 *
 * - `422`: the messages of the API (it translates them) go under the inputs they are about (`label`, `signers`,
 *   `signers.N.name`, `signers.N.email`); a refusal about the inventory itself (too many items for a PDF) is the sentence.
 * - `409`: the limit of versions, the full storage and the lack of a signature service, worded here from the code.
 * - `429`: the wait, from `Retry-After`.
 * - Anything the helper already reported (network, 5xx, expired session) is not said again.
 *
 * @param {{ status: number, code?: string | number | null, errors?: Record<string, string[]>, message: string, body?: any, reported?: boolean, retryAfter?: number | null }} error
 * @returns {{ fieldErrors: Record<string, string>, message: string }}
 */
export function creationFailure(error) {
    if (error.status === 422) {
        const fieldErrors = firstMessages(error.errors ?? {});
        const { inventory, ...inputs } = fieldErrors;

        // A refusal that names no input of the form would be invisible: it is said under the buttons.
        const named = Object.keys(inputs).some((name) => name === 'label' || name.startsWith('signers'));

        return { fieldErrors: inputs, message: inventory ?? (named ? '' : error.message) };
    }

    if (error.status === 409 && error.code === CODES.versionLimit) {
        return { fieldErrors: {}, message: tn('versions.error.limit', Number(error.body?.limit ?? 0)) };
    }

    if (error.status === 409 && error.code === CODES.storageLimit) {
        return { fieldErrors: {}, message: t('versions.error.storage_full') };
    }

    if (error.status === 409 && error.code === CODES.signatureUnavailable) {
        return { fieldErrors: {}, message: t('versions.error.signature_unavailable') };
    }

    if (error.status === 429) {
        return { fieldErrors: {}, message: throttledMessage(error.retryAfter ?? null) };
    }

    return { fieldErrors: {}, message: error.reported ? '' : error.message };
}

/**
 * What an HTTP failure of an action on one version (cancel the request, send it again, delete) means for the page.
 *
 * - `gone`: the version no longer exists (deleted meanwhile): it leaves the list (the helper already said so).
 * - `refresh`: the version is not what the page thought (the request was not waiting, did not fail): read it again.
 * - `message`: what to tell the person, "" when the helper already did.
 *
 * @param {{ status: number, code?: string | number | null, message: string, reported?: boolean }} error
 * @returns {{ gone: boolean, refresh: boolean, message: string }}
 */
export function actionFailure(error) {
    if (error.status === 404) {
        return { gone: true, refresh: false, message: '' };
    }

    if (error.status === 409 && error.code === CODES.notPending) {
        return { gone: false, refresh: true, message: t('versions.error.not_pending') };
    }

    if (error.status === 409 && error.code === CODES.notFailed) {
        return { gone: false, refresh: true, message: t('versions.error.not_failed') };
    }

    if (error.status === 409 && error.code === CODES.signatureUnavailable) {
        return { gone: false, refresh: false, message: t('versions.error.signature_unavailable') };
    }

    return { gone: false, refresh: false, message: error.reported ? '' : error.message };
}

/**
 * Puts a version where it stands in a list: it replaces the one with the same id, or goes at the top when the list does not hold it.
 *
 * @param {VersionRecord[]} versions
 * @param {VersionRecord} version
 * @returns {VersionRecord[]} A new list.
 */
export function withVersion(versions, version) {
    return versions.some((one) => one.id === version.id)
        ? versions.map((one) => (one.id === version.id ? version : one))
        : [version, ...versions];
}

/**
 * Adds a page of versions to the end of the list, leaving out the ones it already holds (a version created since the first page was
 * read moves the others down: the next page then starts with one that was already listed).
 *
 * @param {VersionRecord[]} versions
 * @param {VersionRecord[]} page
 * @returns {VersionRecord[]} A new list.
 */
export function appendPage(versions, page) {
    const known = new Set(versions.map((one) => one.id));

    return [...versions, ...page.filter((one) => !known.has(one.id))];
}

/**
 * The sentences worth saying aloud for what changed between two readings of the versions: a PDF that is written or that failed, a
 * request that reached the signers or that failed. Only a version that was on its way and is no longer in that step is told.
 *
 * @param {VersionRecord[]} before The versions as they were listed.
 * @param {VersionRecord[]} after The same versions as the last reading gives them.
 * @returns {string[]}
 */
export function changeNotices(before, after) {
    const known = new Map(before.map((one) => [one.id, one]));
    const notices = [];

    for (const version of after) {
        const previous = known.get(version.id);

        if (previous === undefined) {
            continue;
        }

        if (isGenerating(previous) && !isGenerating(version)) {
            notices.push(
                t(version.status === 'completed' ? 'versions.notice.ready' : 'versions.notice.failed', {
                    number: version.number,
                }),
            );
        }

        if (isSigning(previous) && !isSigning(version) && version.signature) {
            if (version.signature.status === 'awaiting') {
                notices.push(t('versions.notice.signature_sent', { number: version.number }));
            } else if (version.signature.status === 'failed') {
                notices.push(t('versions.notice.signature_failed', { number: version.number }));
            }
        }
    }

    return notices;
}
