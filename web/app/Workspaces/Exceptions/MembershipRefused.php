<?php

declare(strict_types=1);

namespace App\Workspaces\Exceptions;

use RuntimeException;

/**
 * Raised when a change to who belongs to a workspace (an invitation, a role, a removal, the ownership) is refused by the rules.
 *
 * `reason` is the machine-readable `code` the API answers with, and {@see self::status()} the HTTP status that goes with it.
 * Two other refusals are not this exception, because the framework has a word for them: somebody who does not belong to the workspace
 * (or whom the request is about) is answered as if it was not there (`AuthorizationException` with status 404, or `ModelNotFoundException`),
 * and a role or an address the rules do not allow is a `ValidationException` (422).
 */
final class MembershipRefused extends RuntimeException
{
    /** The caller is a member of the workspace, but not its owner: only the owner decides who belongs to it. (403) */
    public const NOT_OWNER = 'not_owner';

    /** The address belongs to a member already, the owner's included. (409) */
    public const ALREADY_MEMBER = 'already_member';

    /** An invitation to that address is waiting for an answer already. (409) */
    public const ALREADY_INVITED = 'already_invited';

    /** The workspace has as many members and waiting invitations as `workspaces.max_members` allows. (409) */
    public const MEMBER_LIMIT_REACHED = 'member_limit_reached';

    /** The account already belongs to as many workspaces as `workspaces.max_per_user` allows. (409) */
    public const MEMBERSHIP_LIMIT_REACHED = 'membership_limit_reached';

    /** The role of the owner cannot be changed: ownership is handed over, which makes the old owner an editor. (409) */
    public const CANNOT_CHANGE_OWNER_ROLE = 'cannot_change_owner_role';

    /** The owner cannot leave: ownership has to be handed over first. (409) */
    public const OWNER_CANNOT_LEAVE = 'owner_cannot_leave';

    /** The member the ownership is to be handed to already is the owner. (409) */
    public const ALREADY_OWNER = 'already_owner';

    /** The invitation was sent less than `workspaces.invitation_resend_minutes` ago: `retryAfter` says how many seconds are left. (429) */
    public const RESEND_TOO_SOON = 'resend_too_soon';

    /**
     * @param  string  $reason  One of the constants above.
     * @param  int|null  $limit  For {@see self::MEMBER_LIMIT_REACHED} and {@see self::MEMBERSHIP_LIMIT_REACHED}: the limit that was reached.
     * @param  int|null  $retryAfter  For {@see self::RESEND_TOO_SOON}: the seconds to wait.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?int $limit = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct("The change is refused: {$reason}.");
    }

    /**
     * The HTTP status the API answers with: 403 for a member without the right, 429 for a resend that comes too soon, 409 for the others.
     */
    public function status(): int
    {
        return match ($this->reason) {
            self::NOT_OWNER => 403,
            self::RESEND_TOO_SOON => 429,
            default => 409,
        };
    }
}
