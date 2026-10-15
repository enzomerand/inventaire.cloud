<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The page of items asked for, and the words to find them by: every member of the workspace may read them (see
 * {@see InventoryPolicy}).
 */
final class ListItemsRequest extends FormRequest
{
    /** Most characters of a search. */
    public const SEARCH_MAX_LENGTH = 200;

    /**
     * Seeing an inventory is seeing its items.
     */
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->route('inventory'));
    }

    /**
     * The page asked for, and the search (`q`, at most 200 characters; white space only is no search).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_MAX_LENGTH],
        ];
    }
}
