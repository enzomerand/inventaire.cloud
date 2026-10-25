<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The choice of the owner of a workspace about the photo entry: `enabled`, a boolean.
 *
 * Who may is decided before the body is looked at, so that somebody who may not learns nothing from the validation: a stranger is told the
 * workspace does not exist (`404`), a member who is not an owner is refused (`403`), see {@see WorkspacePolicy::manage()}.
 */
final class UpdatePhotoEntryRequest extends FormRequest
{
    /**
     * Lets the owners of the workspace through.
     */
    public function authorize(): Response
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace ? Gate::inspect('manage', $workspace) : Response::denyAsNotFound();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Whether the owner wants the photo entry on.
     */
    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
