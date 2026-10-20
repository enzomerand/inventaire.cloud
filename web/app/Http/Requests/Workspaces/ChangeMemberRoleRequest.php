<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Workspaces\WorkspaceRole;
use Illuminate\Validation\Rule;

/**
 * The new role of a member: an editor's or a viewer's (a workspace has one owner, who hands the ownership over instead).
 */
final class ChangeMemberRoleRequest extends ManagesMembersRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in([WorkspaceRole::Editor->value, WorkspaceRole::Viewer->value])],
        ];
    }

    /**
     * The validated role.
     */
    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from($this->string('role')->toString());
    }
}
