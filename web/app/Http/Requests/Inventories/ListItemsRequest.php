<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\ItemQuery;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The page of items asked for, the words to find them by, and how to sort and filter them: every member of the workspace
 * may read them (see {@see InventoryPolicy}). The shape of `sort`, `direction` and `filter` is checked here; what they say
 * about the fields of the inventory is checked by {@see ItemQuery}.
 */
final class ListItemsRequest extends FormRequest
{
    /** Most characters of a search. */
    public const SEARCH_MAX_LENGTH = 200;

    /** Most characters of the name of what is sorted by (`created_at`, or the ULID of a field). */
    public const SORT_MAX_LENGTH = 64;

    /**
     * Seeing an inventory is seeing its items.
     */
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->route('inventory'));
    }

    /**
     * The page asked for, the search (`q`, at most 200 characters; white space only is no search), what to sort by (`sort`
     * and `direction`) and the conditions on the fields (`filter`, an object of objects).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_MAX_LENGTH],
            'sort' => ['nullable', 'string', 'max:'.self::SORT_MAX_LENGTH],
            'direction' => ['nullable', 'string', 'max:'.self::SORT_MAX_LENGTH],
            'filter' => ['nullable', 'array'],
        ];
    }
}
