<?php

declare(strict_types=1);

namespace App\Inventories;

use App\Database\DeadlockRetries;
use App\Database\JsonValues;
use App\Inventories\Attachments\AttachmentCleanup;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\ItemRevision;
use App\Rules\SingleLineText;
use App\Workspaces\Locks;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
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
        private readonly AttachmentCleanup $files,
    ) {}

    /**
     * Adds a field at the end of the inventory.
     *
     * @param  string  $type  A key of the registry.
     * @param  string  $name  Already validated and trimmed.
     * @param  array<string, mixed>  $options  As sent: the type fills in the defaults and puts them in canonical form.
     *
     * @throws FieldLimitReachedException When the inventory already has as many fields as allowed.
     * @throws ValidationException When the name is taken, the options do not fit together, or the type is filled by uploading
     *                             files and the field is required.
     * @throws ModelNotFoundException When the inventory was deleted in the meantime.
     */
    public function create(Inventory $inventory, string $type, string $name, array $options = [], bool $required = false): InventoryField
    {
        $name = SingleLineText::normalize($name);

        return DeadlockRetries::run(fn (): InventoryField => $this->db->transaction(function () use ($inventory, $type, $name, $options, $required): InventoryField {
            $locked = $this->locks->inventory($inventory->getKey());

            return $this->insert($locked, $locked->fields()->get(), $type, $name, $options, $required);
        }));
    }

    /**
     * Adds a field at the end of an inventory that the caller holds, inside the caller's transaction: the checks of
     * {@see self::create()} (the quota of fields, the name, the options) and the insertion, without a transaction or a
     * lock of its own. That is what lets an inventory and all of its fields be made in one transaction, which a nested one
     * could not retry on a deadlock.
     *
     * The inventory is held when the caller locked it ({@see Locks::inventory()}), or when it was created in this very
     * transaction, which nobody else can see before the commit. The new field is added to `$fields`, so that the next call
     * sees it.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields the inventory has now, read after the lock; none for an
     *                                                   inventory that was just made.
     * @param  string  $type  A key of the registry.
     * @param  string  $name  Already validated and normalized ({@see SingleLineText::normalize()}).
     * @param  array<string, mixed>  $options  As sent: the type fills in the defaults and puts them in canonical form.
     *
     * @throws FieldLimitReachedException When the inventory already has as many fields as allowed.
     * @throws ValidationException When the name is taken, the options do not fit together, or the type is filled by uploading
     *                             files and the field is required.
     */
    public function insert(Inventory $inventory, Collection $fields, string $type, string $name, array $options, bool $required): InventoryField
    {
        // Whoever adds a field, by hand or with the many of a template, a field that is filled by uploading files is not required.
        if ($required) {
            $this->assertCanBeRequired($this->types->get($type));
        }

        $limit = config()->integer('inventories.max_fields');

        if ($fields->count() >= $limit) {
            throw new FieldLimitReachedException($limit);
        }

        $this->assertNameFree($fields, $name, null);

        $normalized = $this->types->get($type)->normalizeOptions($options, null);
        $field = new InventoryField(['name' => $name, 'type' => $type, 'options' => $normalized === [] ? null : $normalized, 'required' => $required]);
        $field->inventory_id = $inventory->getKey();
        $field->position = $fields->isEmpty() ? 0 : (int) $fields->max('position') + 1;
        $field->save();
        $fields->push($field);

        return $field;
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
     * @throws ValidationException When the name is taken, the options do not fit together, or the field is filled by uploading
     *                             files and is made required.
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
                if ($changes['required'] && ($type = $this->types->of($current)) !== null) {
                    $this->assertCanBeRequired($type);
                }

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
     * Deletes a field, and its values from every item of the inventory, in the same transaction. The files of a file or
     * photo field go with it: their rows are deleted here, they are counted out of the storage of the workspace (which is why
     * the workspace is locked first for such a field: see {@see Locks}) and the files are deleted from the disk once the
     * transaction is committed.
     *
     * The values are removed with the query builder, so the items keep their `updated_at`: the items did not change,
     * the field they had a value for is gone. The text the items are searched by loses what the field said in the same
     * pass. The positions of the fields left are closed up.
     *
     * The history goes the same way: the values the field held are taken out of every revision of the inventory (they are
     * the raw values of a field that no longer exists, which nobody should be handed any more), and a revision that has
     * nothing left to say is deleted. The revisions of files stay, and no longer name the field.
     *
     * @throws ModelNotFoundException When the inventory or the field was deleted in the meantime.
     */
    public function delete(Inventory $inventory, InventoryField $field): void
    {
        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($inventory, $field): void {
            // The type of a field never changes, so the bound copy tells whether files can hang from it.
            $workspace = $this->types->of($field)?->filledByUpload() ? $this->locks->workspace($inventory->workspace_id) : null;
            $locked = $this->locks->inventory($inventory->getKey());
            $fields = $locked->fields()->get();
            $current = $this->find($fields, $field);

            if ($workspace !== null) {
                $this->files->releaseField($workspace, $current);
            }

            $remaining = $fields->reject(fn (InventoryField $other): bool => $other->is($current))->values();

            $this->rewriteValues($locked, $current->ulid, fn (array $values): array => array_diff_key($values, [$current->ulid => true]), $remaining);
            $this->forgetInHistory($locked, $current->ulid);
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
     * Refuses to make required a field whose value is made by uploading files: an item is saved first and its files are
     * added after, so a required file could never be satisfied when the item is created.
     *
     * @throws ValidationException
     */
    private function assertCanBeRequired(FieldType $type): void
    {
        if ($type->filledByUpload()) {
            throw ValidationException::withMessages(['required' => [__('A field filled by uploading files cannot be required.')]]);
        }
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
     * Takes a deleted field out of the history of the items of the inventory: its values leave the revisions of values (the
     * revision is deleted when nothing else is left in it), and the revisions of files that were attached to the field no
     * longer name it.
     *
     * Three statements, however long the history is (it can be a hundred revisions of each of ten thousand items), with the
     * JSON edited by the database ({@see JsonValues}, the only place that knows how each engine does it): the revisions
     * that hold nothing but the field go first, the others lose the field, and the revisions of files lose their reference
     * to it. A revision that was empty already stays. The inventory is locked by the caller, so no revision is written meanwhile.
     *
     * @param  string  $ulid  The key of the field.
     */
    private function forgetInHistory(Inventory $inventory, string $ulid): void
    {
        $json = new JsonValues($this->db->connection());
        $revisions = fn (array $actions): Builder => $this->db->table('item_revisions')->where('inventory_id', $inventory->getKey())->whereIn('action', $actions);
        $ofValues = [ItemRevision::CREATED, ItemRevision::UPDATED];

        $nothingElse = $revisions($ofValues);
        $json->whereHoldsOnly($nothingElse, 'changes', $ulid);
        $nothingElse->delete();

        $others = $revisions($ofValues);
        $json->whereHolds($others, 'changes', $ulid);
        $others->update(['changes' => $json->withoutKey('changes', $ulid)]);

        $revisions([ItemRevision::ATTACHMENT_ADDED, ItemRevision::ATTACHMENT_REMOVED])
            ->where('changes->field', $ulid)
            ->update(['changes' => $json->withKeyNull('changes', 'field')]);
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
