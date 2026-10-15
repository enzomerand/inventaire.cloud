<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Models\Inventory;
use App\Models\Workspace;
use App\Policies\InventoryPolicy;
use App\Rules\MultiLineText;
use App\Rules\SingleLineText;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The name and the description of an inventory, to create one in a workspace or to change one.
 *
 * Who may do it is decided before anything is looked at (see {@see InventoryPolicy}), so that somebody who may not
 * learns nothing from the validation: a stranger is told the workspace or the inventory does not exist. Creating needs
 * a name; changing takes whichever of the two is sent, and a name that is sent cannot be empty.
 */
final class SaveInventoryRequest extends FormRequest
{
    /** Longest name, in characters. */
    public const NAME_MAX_LENGTH = 120;

    /** Longest description, in characters. */
    public const DESCRIPTION_MAX_LENGTH = 2000;

    /**
     * Adding to a workspace or changing an inventory: the policy knows which roles may.
     */
    public function authorize(): Response
    {
        $inventory = $this->route('inventory');
        $workspace = $this->route('workspace');

        return match (true) {
            $inventory instanceof Inventory => Gate::inspect('update', $inventory),
            $workspace instanceof Workspace => Gate::inspect('create', [Inventory::class, $workspace]),
            default => Response::denyAsNotFound(),
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $creating = ! $this->route('inventory') instanceof Inventory;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:'.self::NAME_MAX_LENGTH, new SingleLineText],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH, new MultiLineText],
        ];
    }

    /**
     * The attributes that were sent, validated and trimmed: a description that was left out is not in the list (it keeps
     * its value), one sent as null or empty is (it is cleared).
     *
     * @return array{name?: string, description?: string|null}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->toString();
        }

        if ($this->has('description')) {
            $description = $this->input('description');
            $changes['description'] = is_string($description) && $description !== '' ? $description : null;
        }

        return $changes;
    }

    /**
     * Trims the texts before they are validated, so that a name made of spaces counts as missing and a description
     * made of spaces as none, whatever the framework's own trimming does.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $description = $this->input('description');

        $this->merge([
            ...is_string($name) ? ['name' => SingleLineText::trim($name)] : [],
            ...is_string($description) ? ['description' => MultiLineText::normalize($description)] : [],
        ]);
    }
}
