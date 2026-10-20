<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Workspaces\MemberIds;

/**
 * The member the ownership of the workspace is handed over to: the identifier `GET /workspaces/{workspace}/members` gives them.
 *
 * Whether it is one of the members is for the controller to find out (it looks the identifier up among them, see
 * {@see MemberIds}); this checks that there is one.
 */
final class TransferOwnershipRequest extends ManagesMembersRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * The identifier of the member, as it was sent.
     */
    public function memberId(): string
    {
        return $this->string('user_id')->toString();
    }
}
