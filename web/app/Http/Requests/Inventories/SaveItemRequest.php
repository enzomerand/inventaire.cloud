<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Models\Inventory;
use App\Models\Item;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The values of an item, to add one or to change one: an object keyed by the ULID of the field.
 *
 * Only the shape is checked here. What the values must be depends on the fields of the inventory *at the moment of the
 * write*, which only the lock of the inventory fixes: `App\Inventories\ItemValues` judges them there, and answers with
 * the same `422` and one error per value.
 */
final class SaveItemRequest extends FormRequest
{
    /**
     * Adding and changing items: the policy knows which roles may (see {@see InventoryPolicy}).
     */
    public function authorize(): Response
    {
        $inventory = $this->route('inventory');

        return $inventory instanceof Inventory
            ? Gate::inspect('editItems', $inventory)
            : Response::denyAsNotFound();
    }

    /**
     * The values: an object.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // An item with no value at all is fine to add (unless a field is required). Changing one must say what to change,
            // even if that is nothing (an empty object: a form saved without edits): `present`, not `required`, which
            // refuses an empty list.
            'values' => $this->route('item') instanceof Item ? ['present', 'array'] : ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * What was sent, keyed by the ULID of the field.
     *
     * @return array<array-key, mixed>
     */
    public function values(): array
    {
        return $this->array('values');
    }
}
