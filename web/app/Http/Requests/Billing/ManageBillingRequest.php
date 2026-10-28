<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A request that acts on the subscription of the workspace of its path (the customer portal, a synchronisation, a checkout): only its
 * owners may, because the person who buys is the one who pays.
 *
 * Who may is decided before the body is looked at, so that somebody who may not learns nothing from the validation: a stranger is told
 * the workspace does not exist (`404`), a member who is not an owner is refused (`403`), see {@see WorkspacePolicy::manage()}.
 */
class ManageBillingRequest extends FormRequest
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
     * Nothing is asked of the body.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
