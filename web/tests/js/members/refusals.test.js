import { describe, expect, it, vi } from 'vitest';

import { HttpError } from '../../../resources/js/lib/http.js';
import { refusalText, resendWaitText } from '../../../resources/js/members/refusals.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

/** A refusal of the rules, as the API words it. */
function refusal(status, code, body = {}, retryAfter = null) {
    return new HttpError(status, 'Something went wrong.', { code, body: { code, ...body }, retryAfter });
}

describe('the words of the refusals of the members API', () => {
    it.each([
        ['not_owner', 403, 'Only an owner of this workspace can manage it.'],
        ['already_member', 409, 'This person already belongs to the workspace.'],
        ['already_invited', 409, 'An invitation to this address is already waiting for an answer.'],
        [
            'cannot_change_owner_role',
            409,
            'The role of the owner cannot be changed: hand the ownership over to another member instead.',
        ],
        [
            'cannot_remove_owner',
            409,
            'The owner cannot be removed from the workspace: hand the ownership over to another member first.',
        ],
        [
            'owner_cannot_leave',
            409,
            'The owner cannot leave the workspace: hand the ownership over to another member first.',
        ],
        ['already_owner', 409, 'This member is already the owner of the workspace.'],
    ])('tells "%s"', (code, status, message) => {
        expect(refusalText(refusal(status, code))).toBe(message);
    });

    it('says which limit was reached', () => {
        expect(refusalText(refusal(409, 'member_limit_reached', { limit: 50 }))).toBe(
            'Member limit reached (50 per workspace, invitations waiting for an answer included).',
        );
        expect(refusalText(refusal(409, 'membership_limit_reached', { limit: 100 }))).toBe(
            'Workspace limit reached (100 per account).',
        );
    });

    it('says how long to wait to send an invitation again, from the header or from the body', () => {
        expect(refusalText(refusal(429, 'resend_too_soon', {}, 540))).toBe(
            'This invitation was sent a moment ago: wait 9 minutes before sending it again.',
        );
        expect(refusalText(refusal(429, 'resend_too_soon', { retry_after: 61 }))).toBe(
            'This invitation was sent a moment ago: wait 2 minutes before sending it again.',
        );
    });

    it('rounds the wait up to whole minutes, and says what it can when it does not know', () => {
        expect(resendWaitText(1)).toBe('This invitation was sent a moment ago: wait 1 minute before sending it again.');
        expect(resendWaitText(600)).toBe(
            'This invitation was sent a moment ago: wait 10 minutes before sending it again.',
        );
        expect(resendWaitText(null)).toBe(
            'This invitation was sent a moment ago: wait a few minutes before sending it again.',
        );
        expect(resendWaitText(0)).toBe(
            'This invitation was sent a moment ago: wait a few minutes before sending it again.',
        );
    });

    it('says nothing of a failure that is not such a refusal, so that the caller says what it says for any other', () => {
        expect(refusalText(refusal(409, 'something_new'))).toBeNull();
        expect(refusalText(new HttpError(500, 'Server error'))).toBeNull();
        expect(refusalText(new HttpError(422, 'Invalid', { errors: { email: ['Bad'] } }))).toBeNull();
        expect(refusalText(new DOMException('Aborted', 'AbortError'))).toBeNull();
        expect(refusalText(new Error('boom'))).toBeNull();
        expect(refusalText(null)).toBeNull();
    });

    it('does not mistake a code of the prototype for one of the table', () => {
        expect(refusalText(refusal(409, 'constructor'))).toBeNull();
        expect(refusalText(refusal(409, 'toString'))).toBeNull();
    });
});
