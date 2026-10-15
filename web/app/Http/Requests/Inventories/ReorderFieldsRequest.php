<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Models\Inventory;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The new order of the fields of an inventory: the ULIDs of all of them, in the order they are to be shown.
 *
 * That the list is exactly the fields the inventory has is judged under a lock when the order is saved, not here:
 * a field added or deleted a moment ago must not slip by.
 */
final class ReorderFieldsRequest extends FormRequest
{
    /**
     * Ordering the fields is managing them (see {@see InventoryPolicy}).
     */
    public function authorize(): Response
    {
        $inventory = $this->route('inventory');

        return $inventory instanceof Inventory
            ? Gate::inspect('manageFields', $inventory)
            : Response::denyAsNotFound();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'list', 'max:1000'],
            'fields.*' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * The ULIDs, in the order given.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_values(array_map(strval(...), $this->array('fields')));
    }
}
