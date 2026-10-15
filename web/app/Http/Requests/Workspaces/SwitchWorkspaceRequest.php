<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The workspace to work in: the ULID of one the signed-in user belongs to.
 *
 * A workspace that does not exist and one that belongs to somebody else are refused the same way, so that the
 * answer does not tell a stranger which ULIDs are real.
 */
final class SwitchWorkspaceRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'workspace' => [
                'required',
                'string',
                Rule::exists('workspaces', 'ulid')->where(fn (Builder $query): Builder => $query->whereIn(
                    'id',
                    DB::table('workspace_user')->select('workspace_id')->where('user_id', $user instanceof User ? $user->getKey() : 0),
                )),
            ],
        ];
    }

    /**
     * The ULID of the workspace.
     */
    public function ulid(): string
    {
        return $this->string('workspace')->toString();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['workspace' => __('workspace')];
    }
}
