<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Workspaces\Exceptions\MembershipRefused;
use Illuminate\Http\JsonResponse;

/**
 * The answer of the API to a refusal of the rules that decide who belongs to a workspace ({@see MembershipRefused}).
 *
 * It has the shape of the other refusals (see {@see ApiError}): a `message` for people, in the language of the request, and the `code`
 * to switch on, which is the reason of the refusal. A refusal because of a quota adds the `limit` that was reached, and the one of a
 * resend that comes too soon adds `retry_after`, the seconds to wait, with the `Retry-After` header that carries the same number.
 */
final class MembershipError
{
    /**
     * The answer for a refusal: `403`, `409` or `429`, as {@see MembershipRefused::status()} says.
     */
    public static function response(MembershipRefused $refused): JsonResponse
    {
        $body = ['message' => self::message($refused), 'code' => $refused->reason];
        $headers = [];

        if ($refused->limit !== null) {
            $body['limit'] = $refused->limit;
        }

        if ($refused->retryAfter !== null) {
            $body['retry_after'] = $refused->retryAfter;
            $headers['Retry-After'] = (string) $refused->retryAfter;
        }

        return new JsonResponse($body, $refused->status(), $headers);
    }

    /**
     * What happened, for a person, in the language of the request.
     */
    private static function message(MembershipRefused $refused): string
    {
        return match ($refused->reason) {
            MembershipRefused::NOT_OWNER => __('Only an owner of this workspace can manage it.'),
            MembershipRefused::ALREADY_MEMBER => __('This person already belongs to the workspace.'),
            MembershipRefused::ALREADY_INVITED => __('An invitation to this address is already waiting for an answer.'),
            MembershipRefused::MEMBER_LIMIT_REACHED => trans_choice('A workspace cannot have more than :count member, counting the invitations waiting for an answer.|A workspace cannot have more than :count members, counting the invitations waiting for an answer.', $refused->limit ?? 0),
            MembershipRefused::MEMBERSHIP_LIMIT_REACHED => trans_choice('You cannot belong to more than :count workspace.|You cannot belong to more than :count workspaces.', $refused->limit ?? 0),
            MembershipRefused::CANNOT_CHANGE_OWNER_ROLE => __('The role of the owner cannot be changed: hand the ownership over to another member instead.'),
            MembershipRefused::CANNOT_REMOVE_OWNER => __('The owner cannot be removed from the workspace: hand the ownership over to another member first.'),
            MembershipRefused::OWNER_CANNOT_LEAVE => __('The owner cannot leave the workspace: hand the ownership over to another member first.'),
            MembershipRefused::ALREADY_OWNER => __('This member is already the owner of the workspace.'),
            MembershipRefused::RESEND_TOO_SOON => __('This invitation was sent a moment ago: wait a few minutes before sending it again.'),
            default => __('The change is refused.'),
        };
    }
}
