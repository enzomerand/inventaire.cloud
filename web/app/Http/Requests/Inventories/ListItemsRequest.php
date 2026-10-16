<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\ItemQuery;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The page of items asked for, the words to find them by, how to sort and filter them and which totals to give: every member
 * of the workspace may read them (see {@see InventoryPolicy}). The shape of `sort`, `direction`, `filter` and `totals` is
 * checked here; what they say about the fields of the inventory is checked by {@see ItemQuery}.
 */
final class ListItemsRequest extends FormRequest
{
    /** Most characters of a search. */
    public const SEARCH_MAX_LENGTH = 200;

    /** Most characters of the name of what is sorted by (`created_at`, or the ULID of a field), and of the id of a field to total. */
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
     * and `direction`), the conditions on the fields (`filter`, an object of objects: see {@see self::viewRules()}) and the fields
     * to add up (`totals`, a list of at most as many ids as an inventory has fields).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            ...self::viewRules(),
            'totals' => ['nullable', 'array', 'max:'.config()->integer('inventories.max_fields')],
            'totals.*' => ['string', 'max:'.self::SORT_MAX_LENGTH],
        ];
    }

    /**
     * The rules of what makes a view of the items: the search (`q`), what to sort by (`sort` and `direction`) and the conditions
     * on the fields (`filter`). The page and the totals are not part of a view. An export of the current view takes the same rules,
     * under the `query` of its request.
     *
     * @param  string  $prefix  What the names are under, with its dot (`query.`), or nothing.
     * @return array<string, list<mixed>>
     */
    public static function viewRules(string $prefix = ''): array
    {
        return [
            $prefix.'q' => ['nullable', 'string', 'max:'.self::SEARCH_MAX_LENGTH],
            $prefix.'sort' => ['nullable', 'string', 'max:'.self::SORT_MAX_LENGTH],
            $prefix.'direction' => ['nullable', 'string', 'max:'.self::SORT_MAX_LENGTH],
            $prefix.'filter' => ['nullable', 'array'],
        ];
    }
}
