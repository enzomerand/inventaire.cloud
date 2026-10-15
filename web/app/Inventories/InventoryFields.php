<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Database\DeadlockRetries;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Rules\SingleLineText;
use App\Workspaces\Locks;
use Closure;
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
 * request after the other, and reads the fields once it holds the lock, with a plain read: the fields are protected by
 * the lock of the inventory, not by locks of their own (which would also lock the neighbouring inventory's). That is what lets this judge, on the fields as they are, what a database constraint could
 * not: how many there are (`inventories.max_fields`), whether a name is already taken (the case is not counted, and
 * neither is the way an accented letter is written; the accents themselves are: no portable unique index does that),
 * and, when a field is deleted or its options change, that the values that no longer fit are gone from every item
 * before any other write to the inventory can see the field changed. Each write is one transaction, retried when the
 * database picks it as the loser of a deadlock.
 *
 * Names are kept in Unicode normalization form C ({@see SingleLineText::normalize()}), whatever the caller sends.
 */
final class InventoryFields
{
    /** Items read at a time when a field's values are removed from them. */
    private const PURGE_CHUNK = 500;

    /**
     * Gets what the rules of fields need: the database, the locks, the registry of types and the search text of items.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly FieldTypes $types,
        private readonly ItemSearchText $searchText,
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
        $name = SingleLineText::normalize($name);

        return DeadlockRetries::run(fn (): InventoryField => $this->db->transaction(function () use ($inventory, $type, $name, $options, $required, $handler): InventoryField {
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $locked->fields()->get();

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
     * What the new options do to the values the items already hold depends on the type
     * ({@see FieldType::mayInvalidateValues()}):
     *
     * - choices removed from a select or a multiple choice: their ids are removed from every item;
     * - a lower maximum of a rating: the grades above it are removed from every item;
     * - any other change (the decimals of a number or a percentage, the minimum, the maximum or the unit of a number, the
     *   currency of an amount, the length of a text, the format of a barcode, the color or the label of a choice, a new
     *   choice...): the stored values are left exactly as they are. They were valid when they were written and are not
     *   judged again until somebody changes them.
     *
     * The values are removed in the same transaction as the options, with the query builder, so the items keep their
     * `updated_at`. The same goes for the text the items are searched by ({@see ItemSearchText}): when a choice is
     * renamed or deleted ({@see FieldType::mayChangeSearchText()}), the items that hold it are found by its new label,
     * and no longer by the old one.
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
            $fields = $locked->fields()->get();
            $current = $this->find($fields, $field);

            if (isset($changes['name'])) {
                $name = SingleLineText::normalize($changes['name']);
                $this->assertNameFree($fields, $name, $current);
                $current->name = $name;
            }

            if (isset($changes['required'])) {
                $current->required = $changes['required'];
            }

            $rewrite = false;

            if (isset($changes['options'])) {
                $type = $this->types->get($current->type);
                $before = $current->options ?? [];
                $normalized = $type->normalizeOptions(array_replace($before, $changes['options']), $current->options);
                $rewrite = $type->mayInvalidateValues($before, $normalized) || $type->mayChangeSearchText($before, $normalized);
                $current->options = $normalized === [] ? null : $normalized;
            }

            $current->save();

            if ($rewrite) {
                // $current is the instance in $fields: the fields the search text is built from have the new options.
                $this->dropValuesThatNoLongerFit($locked, $current, $fields);
            }

            return $current;
        }));
    }

    /**
     * Deletes a field, and its values from every item of the inventory, in the same transaction.
     *
     * The values are removed with the query builder, so the items keep their `updated_at`: the items did not change,
     * the field they had a value for is gone. The text the items are searched by loses what the field said in the same
     * pass. The positions of the fields left are closed up.
     *
     * @throws ModelNotFoundException When the inventory or the field was deleted in the meantime.
     */
    public function delete(Inventory $inventory, InventoryField $field): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($inventory, $field): void {
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $locked->fields()->get();
            $current = $this->find($fields, $field);

            $remaining = $fields->reject(fn (InventoryField $other): bool => $other->is($current))->values();

            $this->rewriteValues($locked, $current->ulid, fn (array $values): array => array_diff_key($values, [$current->ulid => true]), $remaining);
            $current->delete();

            $this->renumber($remaining);
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
            $fields = $locked->fields()->get();

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
     * Refuses a name another field of the inventory already has: the case is not counted ("Straße" is "STRASSE"), nor
     * is the way an accented letter is written (one character or a letter and a combining accent), but the accents are
     * ("Prénom" is not "Prenom").
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
     * Cleans the values of a field in every item of the inventory, after its options changed: what the type no longer
     * accepts (see {@see FieldType::mayInvalidateValues()}) is removed, through the same {@see FieldType::present()} that
     * hides it when it is read, so that what is stored and what is shown cannot disagree. A value the type still shows
     * is left exactly as it is. The text the items are searched by is rebuilt in the same pass, for the items that hold a
     * value of the field: that is what makes a renamed choice found by its new label.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory as they are now (the options of `$field` changed).
     *
     * @throws JsonException
     */
    private function dropValuesThatNoLongerFit(Inventory $inventory, InventoryField $field, Collection $fields): void
    {
        $type = $this->types->get($field->type);

        $this->rewriteValues($inventory, $field->ulid, function (array $values) use ($type, $field): array {
            $fitting = $type->present($values[$field->ulid], $field);

            if ($fitting === $values[$field->ulid]) {
                return $values;
            }

            if ($type->isEmpty($fitting)) {
                unset($values[$field->ulid]);
            } else {
                $values[$field->ulid] = $fitting;
            }

            return $values;
        }, $fields);
    }

    /**
     * Rewrites the values of the items of the inventory that hold a key, one chunk at a time.
     *
     * Through the query builder and by chunks of the primary key: the items are not loaded as models (their
     * `updated_at` and their events are not the business of a change to a field), and nothing is held in memory but a
     * chunk. The JSON is read in PHP, since reading and writing a key of a JSON object is not portable SQL. Only the rows
     * that hold the key are decoded, and a row is written only when the change alters its values or the text it is
     * searched by (see {@see ItemSearchText}), which is rebuilt from what the values become.
     *
     * @param  string  $ulid  The key of the field: items that do not hold it are skipped.
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change  Takes the values of an item that holds the key
     *                                                                       and returns what they become.
     * @param  Collection<int, InventoryField>  $fields  The fields the search text is built from: those the inventory has
     *                                                   once the change is done.
     *
     * @throws JsonException
     */
    private function rewriteValues(Inventory $inventory, string $ulid, Closure $change, Collection $fields): void
    {
        $this->db->table('items')
            ->where('inventory_id', $inventory->getKey())
            ->chunkById(self::PURGE_CHUNK, function (Collection $rows) use ($ulid, $change, $fields): void {
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

                    $changed = $change($values);
                    $update = [];

                    if ($changed !== $values) {
                        $update['values'] = json_encode($changed === [] ? new stdClass : $changed, JSON_THROW_ON_ERROR);
                    }

                    $text = $this->searchText->build($fields, $changed);

                    if ($text !== $row->search_text) {
                        $update['search_text'] = $text;
                    }

                    if ($update !== []) {
                        $this->db->table('items')->where('id', $row->id)->update($update);
                    }
                }
            }, 'id');
    }
}
