<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Http\Requests\Inventories\SaveFieldRequest;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Exceptions\InvalidImportMapping;
use App\Inventories\Fields\FieldTypes;
use App\Models\InventoryField;
use App\Rules\SingleLineText;
use Illuminate\Support\Collection;

/**
 * Where each column of a file goes: ignored, into a field the inventory has, or into a field the import creates (a type and a name).
 * Stored with the import (`imports.mapping`), first as the proposal of {@see ImportAnalyzer}, then as the person chose.
 *
 * An entry is `{column, target, field, type, name}`: `column` is the position of the column in the file from 0, `target` is `ignore`,
 * `field` or `new`, `field` is the ULID of the field for `field`, and `type` and `name` are those of the field to create for `new`
 * (null otherwise). A column with no entry is ignored.
 */
final readonly class ImportMapping
{
    /** The target of a column that goes nowhere. */
    public const IGNORE = 'ignore';

    /** The target of a column that goes into a field the inventory has. */
    public const FIELD = 'field';

    /** The target of a column that creates a field. */
    public const NEW = 'new';

    /**
     * @param  list<array{column: int, target: string, field: string|null, type: string|null, name: string|null}>  $entries
     */
    private function __construct(public array $entries) {}

    /**
     * The mapping of entries as stored or as sent: each one with all its keys, a target that is none of the three read as
     * `ignore`, a field in lowercase (the case of an ULID does not matter), a name in the form fields keep ({@see SingleLineText}).
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function from(array $raw): self
    {
        $entries = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $target = is_string($entry['target'] ?? null) && in_array($entry['target'], [self::FIELD, self::NEW], true) ? $entry['target'] : self::IGNORE;

            $entries[] = [
                'column' => (int) ($entry['column'] ?? 0),
                'target' => $target,
                'field' => $target === self::FIELD && is_string($entry['field'] ?? null) ? strtolower($entry['field']) : null,
                'type' => $target === self::NEW && is_string($entry['type'] ?? null) ? $entry['type'] : null,
                'name' => $target === self::NEW && is_string($entry['name'] ?? null) ? SingleLineText::normalize($entry['name']) : null,
            ];
        }

        return new self($entries);
    }

    /**
     * The entries as they are stored and given by the API.
     *
     * @return list<array{column: int, target: string, field: string|null, type: string|null, name: string|null}>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * The entries whose column goes somewhere.
     *
     * @return list<array{column: int, target: string, field: string|null, type: string|null, name: string|null}>
     */
    public function mapped(): array
    {
        return array_values(array_filter($this->entries, fn (array $entry): bool => $entry['target'] !== self::IGNORE));
    }

    /**
     * The types a field created by an import may have: all but the ones filled by uploading files, and the multiple choice, whose
     * choices cannot be told from one cell (one column of "red, blue" is a list of tags).
     *
     * @return list<string>
     */
    public static function newFieldTypes(FieldTypes $types): array
    {
        return array_values(array_filter(
            $types->keys(),
            fn (string $key): bool => $key !== 'multiselect' && ! $types->get($key)->filledByUpload(),
        ));
    }

    /**
     * Checks the mapping against the file and the inventory, as they are now.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     * @param  int  $columns  How many columns the file has.
     * @param  bool  $canManageFields  Whether the person may create fields.
     *
     * @throws InvalidImportMapping For a column the file does not have, given twice or going to a field that does not exist, that an
     *                              import cannot fill or that another column fills; for a field to create that cannot be, or that
     *                              the person may not create; for a required field with no column; for a mapping that maps nothing.
     * @throws FieldLimitReachedException When the new fields would take the inventory past the number of fields it may have.
     */
    public function assertValid(Collection $fields, FieldTypes $types, int $columns, bool $canManageFields): void
    {
        $byUlid = $fields->keyBy('ulid');
        $columnsSeen = [];
        $fieldsUsed = [];
        $names = [];
        $new = 0;

        foreach ($fields as $field) {
            $names[SingleLineText::fold($field->name)] = true;
        }

        foreach ($this->entries as $entry) {
            $column = $entry['column'];

            if ($column < 0 || $column >= $columns) {
                throw new InvalidImportMapping(__('The file has no column :column.', ['column' => $column + 1]), InvalidImportMapping::UNKNOWN_COLUMN, ['column' => $column]);
            }

            if (isset($columnsSeen[$column])) {
                throw new InvalidImportMapping(__('The column :column is given twice.', ['column' => $column + 1]), InvalidImportMapping::DUPLICATE_COLUMN, ['column' => $column]);
            }

            $columnsSeen[$column] = true;

            if ($entry['target'] === self::FIELD) {
                $field = $byUlid[$entry['field'] ?? ''] ?? null;

                if ($field === null) {
                    throw new InvalidImportMapping(__('This field does not exist in this inventory.'), InvalidImportMapping::UNKNOWN_FIELD, ['column' => $column]);
                }

                $type = $types->of($field);

                if ($type === null || $type->filledByUpload()) {
                    throw new InvalidImportMapping(__('The field :name is filled by uploading files: an import cannot fill it.', ['name' => $field->name]), InvalidImportMapping::FIELD_NOT_IMPORTABLE, ['column' => $column, 'field' => $field->ulid]);
                }

                if (isset($fieldsUsed[$field->ulid])) {
                    throw new InvalidImportMapping(__('Two columns go to the field :name.', ['name' => $field->name]), InvalidImportMapping::DUPLICATE_FIELD, ['column' => $column, 'field' => $field->ulid]);
                }

                $fieldsUsed[$field->ulid] = true;
            }

            if ($entry['target'] === self::NEW) {
                if (! $canManageFields) {
                    throw new InvalidImportMapping(__('You are not allowed to change the fields of this inventory.'), InvalidImportMapping::CANNOT_MANAGE_FIELDS, ['column' => $column]);
                }

                if (! in_array($entry['type'], self::newFieldTypes($types), true)) {
                    throw new InvalidImportMapping(__('A field of this type cannot be created by an import.'), InvalidImportMapping::INVALID_FIELD_TYPE, ['column' => $column]);
                }

                $name = (string) $entry['name'];

                if ($name === '' || mb_strlen($name) > SaveFieldRequest::NAME_MAX_LENGTH || SingleLineText::problem($name) !== null) {
                    throw new InvalidImportMapping(__('The name of a new field must be 1 to :max characters on one line.', ['max' => SaveFieldRequest::NAME_MAX_LENGTH]), InvalidImportMapping::INVALID_FIELD_NAME, ['column' => $column]);
                }

                $folded = SingleLineText::fold($name);

                if (isset($names[$folded])) {
                    $taken = $fields->contains(fn (InventoryField $field): bool => SingleLineText::fold($field->name) === $folded);

                    throw new InvalidImportMapping(
                        $taken ? __('This inventory already has a field with this name.') : __('Two new fields have the same name.'),
                        $taken ? InvalidImportMapping::FIELD_NAME_TAKEN : InvalidImportMapping::DUPLICATE_FIELD_NAME,
                        ['column' => $column],
                    );
                }

                $names[$folded] = true;
                $new++;
            }
        }

        if ($new > 0 && $fields->count() + $new > config()->integer('inventories.max_fields')) {
            throw new FieldLimitReachedException(config()->integer('inventories.max_fields'));
        }

        if ($this->mapped() === []) {
            throw new InvalidImportMapping(__('Match at least one column with a field.'), InvalidImportMapping::NOTHING_MAPPED);
        }

        foreach ($fields as $field) {
            $type = $types->of($field);

            if ($field->required && $type !== null && ! $type->filledByUpload() && ! isset($fieldsUsed[$field->ulid])) {
                throw new InvalidImportMapping(__('The field :name is required: match a column with it.', ['name' => $field->name]), InvalidImportMapping::REQUIRED_FIELD_UNMAPPED, ['field' => $field->ulid]);
            }
        }
    }
}
