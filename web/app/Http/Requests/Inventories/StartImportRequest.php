<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Imports\ImportMapping;
use App\Inventories\Imports\ImportOptions;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The start of an import: where each column goes (`mapping`, a list of `{column, target, field, type, name}`: see {@see ImportMapping})
 * and how the cells are read (`decimal`, `date_order`) and what to do with a row in error (`on_error`), each kept from the import when
 * not sent.
 *
 * The shape of the mapping is checked here; what it says about the columns of the file and the fields of the inventory is judged by
 * {@see ImportMapping::assertValid()}, whose mistakes are `422` on `mapping` with a `code`.
 *
 * @see InventoryPolicy
 */
final class StartImportRequest extends FormRequest
{
    /**
     * Importing is changing the items.
     */
    public function authorize(): Response
    {
        return Gate::inspect('editItems', $this->route('inventory'));
    }

    /**
     * The mapping, and the options.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['present', 'array', 'max:'.config()->integer('inventories.import_max_columns')],
            'mapping.*' => ['array'],
            'mapping.*.column' => ['required', 'integer', 'min:0'],
            'mapping.*.target' => ['required', 'string', Rule::in([ImportMapping::IGNORE, ImportMapping::FIELD, ImportMapping::NEW])],
            'mapping.*.field' => ['nullable', 'string', 'max:26', 'required_if:mapping.*.target,'.ImportMapping::FIELD],
            'mapping.*.type' => ['nullable', 'string', 'max:32', 'required_if:mapping.*.target,'.ImportMapping::NEW],
            'mapping.*.name' => ['nullable', 'string', 'max:500', 'required_if:mapping.*.target,'.ImportMapping::NEW],
            'decimal' => ['sometimes', 'string', Rule::in(ImportOptions::DECIMALS)],
            'date_order' => ['sometimes', 'string', Rule::in(ImportOptions::DATE_ORDERS)],
            'on_error' => ['sometimes', 'string', Rule::in(ImportOptions::ON_ERRORS)],
        ];
    }

    /**
     * The mapping as it was sent.
     *
     * @return array<array-key, mixed>
     */
    public function mapping(): array
    {
        $mapping = $this->validated('mapping');

        return is_array($mapping) ? $mapping : [];
    }

    /**
     * The options that were sent.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return array_intersect_key($this->validated(), array_flip(['decimal', 'date_order', 'on_error']));
    }
}
