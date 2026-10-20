/**
 * What the pages of the members and of the invitations say when the API refuses a change on purpose.
 *
 * The API explains such a refusal with a machine-readable `code` (and the `limit` that was reached, or the seconds to wait): the
 * words are looked up here by that code, in the language of the page, because the HTTP helper trusts the message of a response
 * only for a validation error.
 */
import { HttpError } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';

/** The refusals whose words do not depend on a figure: code to message key. */
const MESSAGE_KEYS = {
    not_owner: 'members.error.not_owner',
    already_member: 'members.error.already_member',
    already_invited: 'members.error.already_invited',
    cannot_change_owner_role: 'members.error.cannot_change_owner_role',
    owner_cannot_leave: 'members.error.owner_cannot_leave',
    already_owner: 'members.error.already_owner',
};

/**
 * Tells when an invitation can be sent again, from the seconds the API says are left (rounded up to whole minutes).
 *
 * @param {number | null} seconds The `Retry-After` of the answer, if it gave one.
 * @returns {string}
 */
export function resendWaitText(seconds) {
    if (!seconds || seconds < 1) {
        return t('members.error.resend_too_soon');
    }

    return tn('members.error.resend_wait', Math.ceil(seconds / 60));
}

/**
 * The words of a refusal the members API explains with a code.
 *
 * @param {unknown} error What the request threw.
 * @returns {string | null} The message in the language of the page; null when the error is not such a refusal (the caller then
 *   says what it says for any other failure).
 */
export function refusalText(error) {
    if (!(error instanceof HttpError) || typeof error.code !== 'string') {
        return null;
    }

    switch (error.code) {
        case 'member_limit_reached':
            return t('members.error.member_limit', { count: error.body?.limit ?? '' });
        case 'membership_limit_reached':
            return t('members.error.membership_limit', { count: error.body?.limit ?? '' });
        case 'resend_too_soon':
            return resendWaitText(error.retryAfter ?? error.body?.retry_after ?? null);
        default:
            return Object.hasOwn(MESSAGE_KEYS, error.code) ? t(MESSAGE_KEYS[error.code]) : null;
    }
}
