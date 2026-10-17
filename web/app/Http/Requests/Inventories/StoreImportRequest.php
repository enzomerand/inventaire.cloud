<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Exports\CsvWriter;
use App\Inventories\Imports\ImportFormat;
use App\Inventories\Imports\ImportOptions;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

/**
 * A file to import into an inventory: one multipart `file` part (a spreadsheet, a CSV, TSV or TXT), at most `inventories.import_max_size`
 * bytes, and how to read it when the person already knows: `has_header` (true unless said otherwise), `delimiter` (comma, semicolon or tab)
 * and `encoding` (utf-8, windows-1252 or utf-16), the last two for a text file; they are found out when they are not given.
 *
 * Only the shape, the name and the size are checked here. What the file holds is judged when it is read ({@see ImportFormat}), and the
 * limits that depend on what the person has under way, under their lock. Changing the items of the inventory is what an import does: the
 * policy knows which roles may ({@see InventoryPolicy}).
 */
final class StoreImportRequest extends FormRequest
{
    /**
     * Importing is changing the items.
     */
    public function authorize(): Response
    {
        return Gate::inspect('editItems', $this->route('inventory'));
    }

    /**
     * The file, present, really uploaded and not too large (its name and its content are judged when it is read, which answers with a
     * code); and the options of its reading.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // The size rule counts kilobytes.
        $kilobytes = (int) ceil(config()->integer('inventories.import_max_size') / 1024);

        return [
            'file' => ['required', 'file', 'max:'.$kilobytes],
            // A form sends "true" and "false" as texts.
            'has_header' => ['nullable', Rule::in([true, false, 1, 0, '1', '0', 'true', 'false'])],
            'delimiter' => ['nullable', 'string', Rule::in(array_keys(CsvWriter::DELIMITERS))],
            'encoding' => ['nullable', 'string', Rule::in(ImportOptions::ENCODINGS)],
        ];
    }

    /**
     * The message of the size names the limit in words a person reads.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => __('The file must not be larger than :size.', ['size' => Number::fileSize(config()->integer('inventories.import_max_size'), maxPrecision: 1)]),
        ];
    }

    /**
     * The file that was sent.
     */
    public function uploaded(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw new \LogicException('The request holds no file: validation should have refused it.');
    }

    /**
     * How the person asked for the file to be read: the options they gave, nothing for the others.
     *
     * @return array<string, mixed>
     */
    public function reading(): array
    {
        $reading = [];

        if ($this->input('has_header') !== null) {
            $reading['has_header'] = filter_var($this->input('has_header'), FILTER_VALIDATE_BOOLEAN);
        }

        foreach (['delimiter', 'encoding'] as $key) {
            if (is_string($this->input($key)) && $this->input($key) !== '') {
                $reading[$key] = $this->input($key);
            }
        }

        return $reading;
    }
}
