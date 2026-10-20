<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Auth\EmailAddress;
use App\Workspaces\WorkspaceRole;
use Illuminate\Validation\Rule;

/**
 * The invitation of an address to a workspace: the address, and the role it will hold, an editor's or a viewer's.
 */
final class InviteMemberRequest extends ManagesMembersRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => EmailAddress::rules(),
            'role' => ['required', 'string', Rule::in([WorkspaceRole::Editor->value, WorkspaceRole::Viewer->value])],
        ];
    }

    /**
     * The address in its canonical form ({@see EmailAddress::normalize()}), which was applied once, before the validation.
     */
    public function email(): string
    {
        return $this->string('email')->toString();
    }

    /**
     * The role the invitation gives.
     */
    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from($this->string('role')->toString());
    }

    /**
     * Normalizes the address before it is validated, so that what is checked is what is used.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailAddress::normalize($this->input('email'))]);
        }
    }
}
