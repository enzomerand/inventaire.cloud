<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Exports\CsvWriter;
use App\Inventories\Exports\ExportFormat;
use App\Inventories\Exports\ExportScope;
use App\Inventories\Exports\ZipWriter;
use App\Inventories\ItemQuery;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * An export asked for: the format, which items (all, or the view of the grid), and what the format takes. Every member of the
 * workspace may ask for one, as they may read the items (see {@see InventoryPolicy}).
 *
 * The view is the request of the list of items (`q`, `sort`, `direction`, `filter`) under `query`, checked by the same rules as the
 * list ({@see ListItemsRequest::viewRules()}); what it says about the fields of the inventory is checked by the code that applies it
 * ({@see ItemQuery}), whose mistakes are reported under `query.` too.
 */
final class StoreExportRequest extends FormRequest
{
    /**
     * Seeing an inventory is exporting its items.
     */
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->route('inventory'));
    }

    /**
     * `format` (required: xlsx, ods, csv, json, pdf or zip), `scope` (`all`, the default, or `view`), `query` (only with the scope
     * `view`), `delimiter` (comma, semicolon or tab, for a CSV) and `files` (photos or all, for a zip).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(ExportFormat::values())],
            'scope' => ['nullable', 'string', Rule::in([ExportScope::All->value, ExportScope::View->value])],
            'query' => ['nullable', 'array', 'prohibited_unless:scope,'.ExportScope::View->value],
            ...ListItemsRequest::viewRules('query.'),
            'delimiter' => ['nullable', 'string', Rule::in(array_keys(CsvWriter::DELIMITERS))],
            'files' => ['nullable', 'string', Rule::in([ZipWriter::PHOTOS, ZipWriter::ALL])],
        ];
    }

    /**
     * The format asked for.
     */
    public function exportFormat(): ExportFormat
    {
        return ExportFormat::from($this->validated('format'));
    }

    /**
     * The scope asked for: all the items unless it says `view`.
     */
    public function exportScope(): ExportScope
    {
        return ExportScope::tryFrom((string) $this->validated('scope', ExportScope::All->value)) ?? ExportScope::All;
    }

    /**
     * What the export keeps of the request, for the format and the scope: the view for the scope `view`, the separator for a CSV,
     * the files for a zip. What does not apply is dropped, not stored.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $options = [];

        if ($this->exportScope() === ExportScope::View) {
            $query = $this->validated('query');

            // Only the parts of a view: the rest of the object is not the business of an export.
            $options['query'] = is_array($query) ? array_intersect_key($query, array_flip(['q', 'sort', 'direction', 'filter'])) : [];
        }

        if ($this->exportFormat() === ExportFormat::Csv && is_string($this->validated('delimiter'))) {
            $options['delimiter'] = $this->validated('delimiter');
        }

        if ($this->exportFormat() === ExportFormat::Zip) {
            $options['files'] = is_string($this->validated('files')) ? $this->validated('files') : ZipWriter::PHOTOS;
        }

        return $options;
    }
}
