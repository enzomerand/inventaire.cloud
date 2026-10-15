<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Models\Inventory;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The page of items asked for: every member of the workspace may read them (see {@see InventoryPolicy}).
 */
final class ListItemsRequest extends FormRequest
{
    /**
     * Seeing an inventory is seeing its items.
     */
    public function authorize(): Response
    {
        $inventory = $this->route('inventory');

        return $inventory instanceof Inventory
            ? Gate::inspect('view', $inventory)
            : Response::denyAsNotFound();
    }

    /**
     * The page asked for.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
