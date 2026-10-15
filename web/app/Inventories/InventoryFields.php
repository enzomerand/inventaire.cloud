<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Database\DeadlockRetries;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Rules\SingleLineText;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

/**
 * Creating, changing, deleting and ordering the fields of an inventory.
 *
 * Every write starts by locking the inventory (see {@see Locks}), so the fields of one inventory are changed one
 * request after the other. That is what lets this judge, on the fields as they are, what a database constraint could
 * not: how many there are (`inventories.max_fields`), whether a name is already taken (case and accents not counted:
 * no portable unique index does that), and, when a field is deleted, that its values are gone from every item before
 * any other write to the inventory can see the field missing. Each write is one transaction, retried when the database
 * picks it as the loser of a deadlock.
 */
final class InventoryFields
{
    /** Items read at a time when a field's values are removed from them. */
    private const PURGE_CHUNK = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly FieldTypes $types,
    ) {}

    /**
     * Adds a field at the end of the inventory.
     *
     * @param  string  $type  A key of the registry.
     * @param  string  $name  Already validated and trimmed.
     * @param  array<string, mixed>  $options  As sent: the type fills in the defaults and puts them in canonical form.
     *
     * @throws FieldLimitReachedException When the inventory already has as many fields as allowed.
     * @throws ValidationException When the name is taken or the options do not fit together.
     * @throws ModelNotFoundException When the inventory was deleted in the meantime.
     */
    public function create(Inventory $inventory, string $type, string $name, array $options = [], bool $required = false): InventoryField
    {
        $handler = $this->types->get($type);

        return DeadlockRetries::run(fn (): InventoryField => $this->db->transaction(function () use ($inventory, $type, $name, $options, $required, $handler): InventoryField {
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $this->locks->fields($locked->getKey());

            $limit = config()->integer('inventories.max_fields');

            if ($fields->count() >= $limit) {
                throw new FieldLimitReachedException($limit);
            }

            $this->assertNameFree($fields, $name, null);

            $normalized = $handler->normalizeOptions($options, null);
            $field = new InventoryField(['name' => $name, 'type' => $type, 'options' => $normalized === [] ? null : $normalized, 'required' => $required]);
            $field->inventory_id = $locked->getKey();
            $field->position = $fields->isEmpty() ? 0 : (int) $fields->max('position') + 1;
            $field->save();

            return $field;
        }));
    }

    /**
     * Changes a field: its name, whether it is required, its options. The type cannot change.
     *
     * The options sent are merged over the ones the field has (an option sent as null goes back to its default), then
     * judged together by the type.
     *
     * @param  array{name?: string, required?: bool, options?: array<string, mixed>}  $changes  Already validated.
     *
     * @throws ValidationException When the name is taken or the options do not fit together.
     * @throws ModelNotFoundException When the inventory or the field was deleted in the meantime.
     */
    public function update(Inventory $inventory, InventoryField $field, array $changes): InventoryField
    {
        return DeadlockRetries::run(fn (): InventoryField => $this->db->transaction(function () use ($inventory, $field, $changes): InventoryField {
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $this->locks->fields($locked->getKey());
            $current = $this->find($fields, $field);

            if (isset($changes['name'])) {
                $this->assertNameFree($fields, $changes['name'], $current);
                $current->name = $changes['name'];
            }

            if (isset($changes['required'])) {
                $current->required = $changes['required'];
            }

            if (isset($changes['options'])) {
                $merged = array_replace($current->options ?? [], $changes['options']);
                $normalized = $this->types->get($current->type)->normalizeOptions($merged, $current->options);
                $current->options = $normalized === [] ? null : $normalized;
            }

            $current->save();

            return $current;
        }));
    }

    /**
     * Deletes a field, and its values from every item of the inventory, in the same transaction.
     *
     * The values are removed with the query builder, so the items keep their `updated_at`: the items did not change,
     * the field they had a value for is gone. The positions of the fields left are closed up.
     *
     * @throws ModelNotFoundException When the inventory or the field was deleted in the meantime.
     */
    public function delete(Inventory $inventory, InventoryField $field): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($inventory, $field): void {
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $this->locks->fields($locked->getKey());
            $current = $this->find($fields, $field);

            $this->purgeValues($locked, $current->ulid);
            $current->delete();

            $this->renumber($fields->reject(fn (InventoryField $other): bool => $other->is($current))->values());
        }));
    }

    /**
     * Puts the fields in the order given.
     *
     * @param  list<string>  $ulids  The ULIDs of the fields, every field of the inventory once.
     * @return Collection<int, InventoryField> The fields in their new order.
     *
     * @throws ValidationException When the list is not exactly the fields the inventory has now (one was added or
     *                             deleted since the person looked, or an ULID is repeated or foreign).
     * @throws ModelNotFoundException When the inventory was deleted in the meantime.
     */
    public function reorder(Inventory $inventory, array $ulids): Collection
    {
        return DeadlockRetries::run(fn (): Collection => $this->db->transaction(function () use ($inventory, $ulids): Collection {
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $this->locks->fields($locked->getKey());

            $wanted = array_map(strtolower(...), $ulids);
            $existing = $fields->pluck('ulid')->all();

            if (count($wanted) !== count($existing) || array_diff($wanted, $existing) !== [] || count(array_unique($wanted)) !== count($wanted)) {
                throw ValidationException::withMessages(['fields' => [__('The list must contain every field of the inventory, each one once.')]]);
            }

            $byUlid = $fields->keyBy('ulid')->all();

            return $this->renumber(collect($wanted)->map(fn (string $ulid): InventoryField => $byUlid[$ulid])->values());
        }));
    }

    /**
     * The field among the locked ones, or the failure of a route binding when it was deleted meanwhile.
     *
     * @param  Collection<int, InventoryField>  $fields
     *
     * @throws ModelNotFoundException
     */
    private function find(Collection $fields, InventoryField $field): InventoryField
    {
        return $fields->first(fn (InventoryField $other): bool => $other->is($field))
            ?? throw (new ModelNotFoundException)->setModel(InventoryField::class, [$field->getKey()]);
    }

    /**
     * Refuses a name another field of the inventory already has, capitals and accents not counted.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     * @param  InventoryField|null  $except  The field being renamed, which may keep its own name.
     *
     * @throws ValidationException
     */
    private function assertNameFree(Collection $fields, string $name, ?InventoryField $except): void
    {
        $folded = SingleLineText::fold($name);

        foreach ($fields as $other) {
            if (($except === null || ! $other->is($except)) && SingleLineText::fold($other->name) === $folded) {
                throw ValidationException::withMessages(['name' => [__('This inventory already has a field with this name.')]]);
            }
        }
    }

    /**
     * Numbers the fields 0, 1, 2... in the order given, writing only the ones whose position changes.
     *
     * @param  Collection<int, InventoryField>  $ordered
     * @return Collection<int, InventoryField>
     */
    private function renumber(Collection $ordered): Collection
    {
        foreach ($ordered as $position => $field) {
            if ($field->position !== $position) {
                $field->position = $position;
                $field->save();
            }
        }

        return $ordered;
    }

    /**
     * Removes the key of a field from the values of every item of the inventory.
     *
     * Through the query builder and by chunks of the primary key: the items are not loaded as models (their
     * `updated_at` and their events are not the business of a field's deletion), and nothing is held in memory but a
     * chunk. The JSON is read in PHP, since reading and writing a key of a JSON object is not portable SQL.
     *
     * @throws JsonException
     */
    private function purgeValues(Inventory $inventory, string $ulid): void
    {
        $this->db->table('items')
            ->where('inventory_id', $inventory->getKey())
            ->chunkById(self::PURGE_CHUNK, function (Collection $rows) use ($ulid): void {
                foreach ($rows as $row) {
                    $json = (string) $row->values;

                    // Most items never held the field: a cheap look before decoding.
                    if (! str_contains($json, $ulid)) {
                        continue;
                    }

                    $values = json_decode($json, true);

                    if (! is_array($values) || ! array_key_exists($ulid, $values)) {
                        continue;
                    }

                    unset($values[$ulid]);

                    $this->db->table('items')->where('id', $row->id)->update([
                        'values' => json_encode($values === [] ? new stdClass : $values, JSON_THROW_ON_ERROR),
                    ]);
                }
            }, 'id');
    }
}
