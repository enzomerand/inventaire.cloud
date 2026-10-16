<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Fields\ExportValue;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\UploadedFilesType;
use App\Inventories\ItemValues;
use App\Models\InventoryField;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * The items of an inventory as rows of values, one column per field: what every format that lays the items out in columns shares.
 *
 * The columns are the identifier of the item, the fields in the order they are shown, and the dates the item was created and last
 * changed; their names are in the language of the export (the application is switched to it while the file is written). A field
 * this version does not know the type of is left out, as the API leaves its values out. A value goes through the same
 * {@see ItemValues::present()} as the API's: a choice that was deleted from its field is not in the file.
 */
final class ExportTable
{
    /** @var list<FieldType> The type of each field, by position in `fields`. */
    private array $types = [];

    /** @var list<InventoryField> The fields that are columns, in order. */
    private array $list;

    /** @var Collection<int, InventoryField> */
    private Collection $fields;

    /**
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     */
    public function __construct(Collection $fields, private readonly FieldTypes $registry, private readonly ItemValues $values)
    {
        $this->list = array_values($fields->filter(fn (InventoryField $field): bool => $registry->of($field) !== null)->all());
        $this->fields = new Collection($this->list);

        foreach ($this->list as $field) {
            $this->types[] = $registry->get($field->type);
        }
    }

    /**
     * The fields that are columns, in order.
     *
     * @return Collection<int, InventoryField>
     */
    public function fields(): Collection
    {
        return $this->fields;
    }

    /**
     * The field in the position given.
     */
    public function field(int $position): InventoryField
    {
        return $this->list[$position];
    }

    /**
     * The type of the field in the position given.
     */
    public function typeAt(int $position): FieldType
    {
        return $this->types[$position];
    }

    /**
     * Whether a field holds files: the names of the files of an item are then needed to write its value.
     */
    public function hasFileFields(): bool
    {
        return $this->fields->contains(fn (InventoryField $field): bool => $this->registry->get($field->type) instanceof UploadedFilesType);
    }

    /**
     * The name of the column of a field: its name, and its unit or currency between brackets when it has one ("Price (EUR)").
     */
    public function header(int $position): string
    {
        $unit = $this->types[$position]->exportUnit($this->list[$position]);

        return $this->list[$position]->name.($unit === null ? '' : " ({$unit})");
    }

    /**
     * The names of all the columns of a sheet or of a CSV: the identifier of the item, the fields, the dates.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $headers = [__('Identifier')];

        foreach (array_keys($this->types) as $position) {
            $headers[] = $this->header($position);
        }

        return [...$headers, __('Created on'), __('Modified on')];
    }

    /**
     * The values of an item, one for each field in the order of the columns.
     *
     * @return list<ExportValue>
     */
    public function row(Item $item): array
    {
        $presented = $this->presented($item);
        $names = $this->fileNames($item);
        $row = [];

        foreach ($this->list as $position => $field) {
            $row[] = $this->types[$position]->exportValue($presented[$field->ulid] ?? null, $field, $names);
        }

        return $row;
    }

    /**
     * The values of an item as the API gives them, keyed by the ULID of the field (what is empty is not there).
     *
     * @return array<string, mixed>
     */
    public function presented(Item $item): array
    {
        return $this->values->present($this->fields, $item->values);
    }

    /**
     * How many files an item holds in a field of files (the number a printed document shows instead of the names).
     */
    public function fileCount(Item $item, int $position): int
    {
        $stored = $this->presented($item)[$this->list[$position]->ulid] ?? [];

        return is_array($stored) ? count($stored) : 0;
    }

    /**
     * The names of the files attached to an item, by the ULID of the attachment. Empty when the files were not read with the item.
     *
     * @return array<string, string>
     */
    private function fileNames(Item $item): array
    {
        if (! $item->relationLoaded('attachments')) {
            return [];
        }

        return $item->attachments->pluck('name', 'ulid')->map(strval(...))->all();
    }
}
