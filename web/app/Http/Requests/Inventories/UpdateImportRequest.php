<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Exports\CsvWriter;
use App\Inventories\Imports\ImportOptions;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * How to read the file of an import again: whether its first row is a header, the separator and the encoding of a text file. The options
 * that are not sent are kept. The decimal sign, the order of the dates and what to do with a row in error can be sent too, to be kept
 * with the import.
 *
 * @see InventoryPolicy
 */
final class UpdateImportRequest extends FormRequest
{
    /**
     * Importing is changing the items.
     */
    public function authorize(): Response
    {
        return Gate::inspect('editItems', $this->route('inventory'));
    }

    /**
     * Each option, when sent.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'has_header' => ['sometimes', 'boolean'],
            'delimiter' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(CsvWriter::DELIMITERS))],
            'encoding' => ['sometimes', 'nullable', 'string', Rule::in(ImportOptions::ENCODINGS)],
            'decimal' => ['sometimes', 'string', Rule::in(ImportOptions::DECIMALS)],
            'date_order' => ['sometimes', 'string', Rule::in(ImportOptions::DATE_ORDERS)],
            'on_error' => ['sometimes', 'string', Rule::in(ImportOptions::ON_ERRORS)],
        ];
    }

    /**
     * The options that were sent, as {@see ImportOptions::toArray()} keys them.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }
}
