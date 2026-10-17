<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Models\InventoryField;
use Illuminate\Support\Collection;

/**
 * What the rows of an import were read and checked against: the columns that go somewhere, and the state of the fields of the inventory
 * at that moment. The writer checks, under the lock of the inventory, that the fields are still those.
 */
final readonly class ImportPlan
{
    /**
     * @param  list<ImportTarget>  $targets
     * @param  string  $fingerprint  {@see self::fingerprint()} of the fields the rows were checked against.
     */
    public function __construct(public array $targets, public string $fingerprint) {}

    /**
     * The columns that fill a field the inventory has.
     *
     * @return list<ImportTarget>
     */
    public function existing(): array
    {
        return array_values(array_filter($this->targets, fn (ImportTarget $target): bool => ! $target->new));
    }

    /**
     * The columns that create a field.
     *
     * @return list<ImportTarget>
     */
    public function created(): array
    {
        return array_values(array_filter($this->targets, fn (ImportTarget $target): bool => $target->new));
    }

    /**
     * The headers of the columns by the ULID of the field they fill, for the report.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->targets as $target) {
            $labels[$target->field->ulid] = $target->label;
        }

        return $labels;
    }

    /**
     * A short text that is the same for two sets of fields that judge a value the same way: what a field is checked with (its ULID,
     * its type, whether it is required, its options), whatever its name and its place. Options are compared whatever the order their
     * keys come back in (a JSON column of MySQL reorders them).
     *
     * @param  Collection<int, InventoryField>  $fields
     */
    public static function fingerprint(Collection $fields): string
    {
        $parts = $fields
            ->map(fn (InventoryField $field): array => [$field->ulid, $field->type, $field->required, self::sorted($field->options ?? [])])
            ->sortBy(fn (array $part): string => $part[0])
            ->values()
            ->all();

        return md5(json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * An array with the keys of every level in order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
