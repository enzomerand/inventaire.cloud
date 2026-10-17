<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Versions\OfficialVersions;
use App\Models\InventoryVersion;
use App\Policies\InventoryVersionPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An inventory frozen as an official version: an optional label, and optionally the people to ask a signature from. Editors and owners
 * only (see {@see InventoryVersionPolicy}).
 *
 * Only the shape of the request is checked here. What the label and the people are is checked by the code that freezes the inventory
 * ({@see OfficialVersions::create()}), after the names are cleaned up and the addresses brought to their canonical form, and its
 * mistakes are reported under `label`, `signers`, `signers.N.name` and `signers.N.email`.
 */
final class StoreInventoryVersionRequest extends FormRequest
{
    /**
     * Freezing an inventory is for the roles that may change it.
     */
    public function authorize(): Response
    {
        return Gate::inspect('create', [InventoryVersion::class, $this->route('inventory')]);
    }

    /**
     * `label` (optional text) and `signers` (optional, at least one person when it is given).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string'],
            'signers' => ['nullable', 'array', 'min:1'],
        ];
    }

    /**
     * What the person calls the version, as sent: none when it is absent or null.
     */
    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) ? $label : null;
    }

    /**
     * The people to ask a signature from, as sent (`name` and `email` of each, not yet checked): none when the list is absent or null.
     *
     * @return array<array-key, mixed>
     */
    public function signers(): array
    {
        $signers = $this->validated('signers');

        return is_array($signers) ? $signers : [];
    }
}
