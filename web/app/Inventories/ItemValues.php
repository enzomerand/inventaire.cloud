<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Inventories\Fields\FieldTypes;
use App\Models\InventoryField;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Checks what a person sends as the values of an item against the fields of the inventory, and cleans up what is stored
 * when it is read back.
 *
 * The values are an object keyed by the ULID of the field. Writing follows the rules of the lot:
 *
 * - a key that is not a field of the inventory is refused (`values.<key>`), never ignored;
 * - an empty value (null, an empty text, an empty list) clears the field: the key is removed, nothing empty is stored;
 * - a field that is required must have a value when the item is created, and cannot be cleared when the key is sent
 *   (an update that does not mention it leaves it alone, whatever the item held);
 * - the other values go through the rules of the type of their field, which name the field in their messages, and are
 *   stored in the canonical form of the type;
 * - an update only changes the keys it sends: the rest of what the item holds is kept.
 *
 * The caller passes the fields read under the lock of the inventory, so that a field deleted at the same moment cannot
 * leave a value behind: the values are rebuilt from the fields that exist now. A key that no field owns (a stray left by
 * a field deleted by hand in the database) is dropped on the way.
 */
final class ItemValues
{
    /** Most unknown keys named in an error, so that a huge body does not make a huge answer. */
    private const MAX_UNKNOWN_REPORTED = 20;

    public function __construct(private readonly FieldTypes $types) {}

    /**
     * The values an item holds after a write.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory, as they are now.
     * @param  array<array-key, mixed>  $input  What was sent, keyed by the ULID of the field.
     * @param  array<string, mixed>  $current  What the item holds now (empty while it is created).
     * @param  bool  $creating  Whether the item is being created: then every field is judged, not only the ones sent.
     * @return array<string, mixed> The values to store, in canonical form.
     *
     * @throws ValidationException With one error per refused value, under `values.<field ULID>`.
     */
    public function apply(Collection $fields, array $input, array $current, bool $creating): array
    {
        $writable = [];

        foreach ($fields as $field) {
            if ($this->types->of($field) !== null) {
                $writable[$field->ulid] = $field;
            }
        }

        $unknown = [];

        foreach (array_keys($input) as $key) {
            if (! isset($writable[(string) $key])) {
                $unknown[] = (string) $key;
            }
        }

        $prepared = [];
        $rules = [];
        $names = [];
        $cleared = [];

        foreach ($writable as $ulid => $field) {
            $sent = array_key_exists($ulid, $input);

            if (! $sent && ! $creating) {
                continue;
            }

            $type = $this->types->get($field->type);
            $value = $sent ? $type->prepare($input[$ulid], $field) : null;

            if ($type->isEmpty($value)) {
                if ($field->required) {
                    $prepared[$ulid] = null;
                    $rules["values.{$ulid}"] = ['required'];
                    $names["values.{$ulid}"] = $field->name;
                } else {
                    $cleared[$ulid] = true;
                }

                continue;
            }

            $prepared[$ulid] = $value;
            $rules["values.{$ulid}"] = ['bail', ...$type->valueRules($field)];
            $names["values.{$ulid}"] = $field->name;
        }

        $validator = Validator::make(['values' => $prepared], $rules, [], $names);
        $validator->after(function (ValidatorInstance $validator) use ($unknown): void {
            foreach (array_slice($unknown, 0, self::MAX_UNKNOWN_REPORTED) as $key) {
                $validator->errors()->add("values.{$key}", __('This field does not exist in this inventory.'));
            }
        });
        $validator->validate();

        // What the item holds, minus the keys of fields that no longer exist, then what this write changes.
        $known = [];

        foreach ($fields as $field) {
            $known[$field->ulid] = true;
        }

        $stored = array_intersect_key($current, $known);

        foreach ($cleared as $ulid => $_) {
            unset($stored[$ulid]);
        }

        foreach ($prepared as $ulid => $value) {
            if ($value !== null) {
                $stored[$ulid] = $this->types->get($writable[$ulid]->type)->normalize($value, $writable[$ulid]);
            }
        }

        return $stored;
    }

    /**
     * The values of an item as they are given back: only the fields that exist, in their order, each one cleaned up by
     * its type (a choice that was deleted from its field is gone), and nothing empty.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory, in order.
     * @param  array<string, mixed>  $stored  What the item holds.
     * @return array<string, mixed>
     */
    public function present(Collection $fields, array $stored): array
    {
        $values = [];

        foreach ($fields as $field) {
            $type = $this->types->of($field);

            if ($type === null || ! array_key_exists($field->ulid, $stored)) {
                continue;
            }

            $value = $type->present($stored[$field->ulid], $field);

            if (! $type->isEmpty($value)) {
                $values[$field->ulid] = $value;
            }
        }

        return $values;
    }
}
