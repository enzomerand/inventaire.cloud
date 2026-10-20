<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\Exceptions\MembershipRefused;
use App\Workspaces\Workspaces;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request that changes who belongs to the workspace of its path (an invitation, a role, the ownership): only the owner may.
 *
 * Who may is decided before the body is looked at, so that somebody who may not learns nothing from the validation: a stranger is told
 * the workspace does not exist (`404`), a member who is not the owner is refused with the code `not_owner` (`403`). The service that
 * makes the change judges the person again under its locks; this is the answer at the door.
 */
abstract class ManagesMembersRequest extends FormRequest
{
    /**
     * Lets the owner of the workspace through.
     *
     * @throws AuthorizationException With status 404 when the person does not belong to the workspace.
     * @throws MembershipRefused With `not_owner` when they belong to it without owning it.
     */
    public function authorize(Workspaces $workspaces): bool
    {
        $workspace = $this->route('workspace');
        $user = $this->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User) {
            return false;
        }

        $role = $workspaces->roleOf($user, $workspace) ?? throw (new AuthorizationException)->withStatus(404);

        if (! $role->canManage()) {
            throw new MembershipRefused(MembershipRefused::NOT_OWNER);
        }

        return true;
    }
}
