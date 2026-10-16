<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\Attachments\Attachments;
use App\Inventories\Exceptions\InvalidImportValue;
use App\Inventories\Imports\ImportCell;
use App\Inventories\Imports\ImportContext;
use App\Models\InventoryField;
use Closure;

/**
 * What the file field and the photo field share: they hold files that are **uploaded**, not values that are sent.
 *
 * The value of such a field is the list of the ULIDs of the attachments that fill it, in the order they were added; the key is
 * absent while there is none. Nobody writes that list: the upload of a file to the field appends to it, and the removal of the
 * file takes the ULID out, both under the lock of the item ({@see Attachments}), so that the list and the files never
 * disagree. Creating or changing an item with a value for such a field is refused (`422`), and the field is never required (an
 * item is saved first, its files are added after).
 *
 * The one option is `max_files`, from 1 to 10 (1 by default): how many files the field takes. Lowering it removes nothing: the
 * files already there stay, and only new uploads are refused until the field is under its maximum again. The list cannot be
 * sorted, nor filtered but on whether the field holds a file (`empty`), and adds nothing to the search.
 */
abstract class UploadedFilesType extends AbstractFieldType
{
    /** The fewest files a field may take. */
    public const MIN_FILES = 1;

    /** The most files a field may take. */
    public const MAX_FILES = 10;

    /** The number of files a field takes when it does not say. */
    public const DEFAULT_MAX_FILES = 1;

    /**
     * Whether the field takes images only (JPEG, PNG, WebP and GIF), whose thumbnail is shown in the grid and the gallery.
     */
    abstract public function imagesOnly(): bool;

    /**
     * The files are looked at as a whole: a filter can only ask whether the field holds a file.
     */
    public function queryKind(): QueryKind
    {
        return QueryKind::Files;
    }

    /**
     * The files are added by uploading them.
     */
    public function filledByUpload(): bool
    {
        return true;
    }

    /**
     * How many files the field takes.
     *
     * @param  array<string, mixed>|null  $previous
     * @return array<string, list<mixed>>
     */
    public function optionRules(?array $previous = null): array
    {
        return [
            'max_files' => ['nullable', 'integer', 'between:'.self::MIN_FILES.','.self::MAX_FILES],
        ];
    }

    /**
     * Fills in the number of files.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $options, ?array $previous): array
    {
        return ['max_files' => (int) ($options['max_files'] ?? self::DEFAULT_MAX_FILES)];
    }

    /**
     * How many files the field takes now.
     */
    public function maxFiles(InventoryField $field): int
    {
        return (int) $field->option('max_files', self::DEFAULT_MAX_FILES);
    }

    /**
     * Nothing a person sends is a value of the field: whatever the key holds, it is refused (see {@see ItemValues}, which
     * does so before the rules run; these are the safety net for code that judges a value on its own).
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return [function (string $attribute, mixed $value, Closure $fail): void {
            $fail(__('This field is filled by uploading files.'));
        }];
    }

    /**
     * The ULIDs of the files, in order. Whatever else the column holds (a stray) is not shown.
     */
    public function present(mixed $stored, InventoryField $field): mixed
    {
        if (! is_array($stored)) {
            return null;
        }

        return array_values(array_filter($stored, fn (mixed $ulid): bool => is_string($ulid) && preg_match('/^[0-9a-z]{26}$/', $ulid) === 1));
    }

    /**
     * The names of the files, in the order they were attached, joined by a comma; a file that is gone is left out.
     *
     * @param  array<string, string>  $fileNames
     */
    public function exportValue(mixed $stored, InventoryField $field, array $fileNames = []): ExportValue
    {
        $names = [];

        foreach (is_array($stored) ? $stored : [] as $ulid) {
            if (is_string($ulid) && isset($fileNames[$ulid])) {
                $names[] = $fileNames[$ulid];
            }
        }

        return ExportValue::text(implode(', ', $names));
    }

    /**
     * Never read from a cell: the files of an item are added by uploading them.
     */
    public function importValue(ImportCell $cell, InventoryField $field, ImportContext $context): mixed
    {
        throw new InvalidImportValue(__('This field is filled by uploading files.'));
    }
}
