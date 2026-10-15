<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The name of a workspace, to create one or to rename it: required, trimmed, at most 100 characters.
 *
 * Who may do it is decided before the name is looked at, so that somebody who may not rename a workspace learns
 * nothing from the validation (a stranger is told the workspace does not exist).
 */
final class WorkspaceNameRequest extends FormRequest
{
    /** Longest name, in characters. */
    public const MAX_LENGTH = 100;

    /**
     * Anybody can create a workspace; renaming one is for its owners (see {@see WorkspacePolicy}).
     */
    public function authorize(): Response
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace
            ? Gate::inspect('update', $workspace)
            : Gate::inspect('create', Workspace::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * The validated name, already trimmed.
     */
    public function name(): string
    {
        return $this->string('name')->toString();
    }

    /**
     * Trims the name before it is validated, so that a name made of spaces counts as missing and one with spaces
     * around it is stored without them. The framework trims its input too; this does not depend on it, and also
     * removes the invisible characters (non-breaking and zero-width spaces) a paste can bring along.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $name) ?? $name]);
        }
    }
}
