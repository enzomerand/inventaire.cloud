<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Database\DeadlockRetries;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Exceptions\ImportFailure;
use App\Inventories\Exceptions\ItemLimitReachedException;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\InventoryFields;
use App\Inventories\Items;
use App\Inventories\ItemValues;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\User;
use App\Workspaces\Locks;
use Closure;
use Generator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Writes the rows of an import: the new fields, then the items, all in one transaction under the lock of the inventory, so that an
 * import is all or nothing, and no other write to the inventory can come between its fields and its items.
 *
 * The rows were converted and checked **before** the lock, against the fields as they were then ({@see ImportRunner}): holding the lock of
 * the inventory while ten thousand rows are validated would hold up everybody who writes to it. So the first thing done under the lock
 * is to read the fields again and compare:
 *
 * - a field a column fills that is gone, or has another type, fails the import (`fields_changed`): the rows mean nothing now;
 * - a field to create whose name was taken meanwhile fails it too, and the quota of fields (`field_limit_reached`) and of items
 *   (`item_limit_reached`) are judged here, where nobody else can change the counts;
 * - the fields of the inventory are compared as a whole ({@see ImportPlan::fingerprint()}): when nothing changed in how they judge a
 *   value, the rows are known to be valid and are written as they are; when something did (a choice was removed, a maximum lowered, a
 *   field made required), every row is checked again, against the fields as they are, and a row that is not valid any more fails the
 *   import in the mode that cancels everything, and is left out and reported in the mode that skips.
 *
 * The person who started the import is judged again under the lock: their role may have changed while the import waited in the queue
 * or was being read (`not_allowed`), and the right to create fields is judged too when a field is created.
 *
 * The values of a field that is created were keyed by a ULID made up for it, and a choice by the id the made-up field gave it: they are
 * rewritten with the ULID and the ids the saved field has, one row at a time as the items are written, and never into a second copy of
 * the rows (ten thousand rows held twice is the memory of the import doubled; and the rows must stay as they were, since a transaction
 * that loses a deadlock is tried again with fields saved under other ULIDs). The fields are saved first, so that the items refer to
 * fields that exist.
 *
 * The end of the import is recorded in the same transaction, by the caller's `$finish`: the import is completed with its report if and
 * only if its items are written. An import that was given up on meanwhile (the pruner took it for lost, it was deleted) has no
 * running row to complete, `$finish` fails, and the transaction takes the fields and the items back with it.
 */
final class ImportWriter
{
    /**
     * Gets the database, the locks, the services that add fields and items, and the checks of values.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly InventoryFields $fields,
        private readonly Items $items,
        private readonly ItemValues $values,
    ) {}

    /**
     * Creates the fields and the items.
     *
     * @param  array<int, array<string, mixed>>  $rows  The stored values of the rows that were valid, keyed by the ULID of the field (a made-up one for a field to create), by their number in the file.
     * @param  string  $onError  `abort` or `skip`: what to do with a row that turns out not to be valid under the lock.
     * @param  Closure(ImportWritten): void  $finish  Called inside the transaction once everything is written, to record the end of the import: it throws to take everything back.
     *
     * @throws ImportFailure When the import cannot go through: nothing is written then.
     */
    public function write(Inventory $inventory, User $author, ImportPlan $plan, array $rows, string $onError, Closure $finish): ImportWritten
    {
        return DeadlockRetries::run(fn (): ImportWritten => $this->db->transaction(function () use ($inventory, $author, $plan, $rows, $onError, $finish): ImportWritten {
            $this->locks->author($author);
            $locked = $this->locks->inventory($inventory->getKey());
            // Judged under the locks, before the first read of the fields: the person may have lost the right while the import waited.
            $this->assertAllowed($author, $locked, $plan);
            // Read last: the first plain read of the transaction opens its snapshot, and every lock is taken by now.
            $fields = $locked->fields()->get();

            foreach ($plan->existing() as $target) {
                $current = $fields->first(fn (InventoryField $field): bool => $field->ulid === $target->field->ulid);

                if ($current === null || $current->type !== $target->field->type) {
                    throw new ImportFailure(ImportError::FieldsChanged);
                }
            }

            $unchanged = ImportPlan::fingerprint($fields) === $plan->fingerprint;
            $created = $this->createFields($locked, $fields, $plan);
            $labels = $this->labels($plan, $created);
            $stored = $this->rewritten($rows, $plan, $created);
            $count = count($rows);
            $skipped = [];
            $skippedRows = 0;

            if (! $unchanged) {
                [$stored, $skipped, $skippedRows] = $this->check($fields, $stored, $labels, $onError);
                $count = count($stored);
            }

            try {
                $this->items->createMany($locked, $inventory, $fields, $stored, $author, $count);
            } catch (ItemLimitReachedException) {
                throw new ImportFailure(ImportError::ItemLimitReached);
            }

            $written = new ImportWritten($count, count($created), $skipped, $skippedRows);
            $finish($written);

            return $written;
        }));
    }

    /**
     * Refuses the import when the person who started it may no longer change the items of the inventory, or create fields when the
     * import creates some. The right is read again here, after the locks: it was judged when the import was started and again when the
     * job began, but a role can change in between, and what the import writes is theirs to answer for.
     *
     * @throws ImportFailure `not_allowed`.
     */
    private function assertAllowed(User $author, Inventory $locked, ImportPlan $plan): void
    {
        $gate = Gate::forUser($author);

        if (! $gate->allows('editItems', $locked) || ($plan->created() !== [] && ! $gate->allows('manageFields', $locked))) {
            throw new ImportFailure(ImportError::NotAllowed);
        }
    }

    /**
     * Saves the fields the import creates, at the end of the inventory in the order of the columns.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory, read under the lock; the new ones are added.
     * @return array<string, InventoryField> The saved fields by the made-up ULID of each.
     *
     * @throws ImportFailure When the inventory has too many fields, or a name was taken meanwhile.
     */
    private function createFields(Inventory $locked, Collection $fields, ImportPlan $plan): array
    {
        $created = [];

        foreach ($plan->created() as $target) {
            try {
                $created[$target->field->ulid] = $this->fields->insert($locked, $fields, $target->field->type, $target->field->name, $target->field->options ?? [], false);
            } catch (FieldLimitReachedException) {
                throw new ImportFailure(ImportError::FieldLimitReached);
            } catch (ValidationException) {
                throw new ImportFailure(ImportError::FieldsChanged);
            }
        }

        return $created;
    }

    /**
     * The headers of the columns by the ULID of the fields as they are saved.
     *
     * @param  array<string, InventoryField>  $created
     * @return array<string, string>
     */
    private function labels(ImportPlan $plan, array $created): array
    {
        $labels = [];

        foreach ($plan->labels() as $ulid => $label) {
            $labels[isset($created[$ulid]) ? $created[$ulid]->ulid : $ulid] = $label;
        }

        return $labels;
    }

    /**
     * The stored values of the rows with the ULID of each saved field in place of the made-up one, and the ids of its choices in place
     * of the made-up ones, one row after the other (the rows themselves are left alone: see the class).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, InventoryField>  $created
     * @return Generator<int, array<string, mixed>> The values of each row, by its number in the file.
     */
    private function rewritten(array $rows, ImportPlan $plan, array $created): Generator
    {
        if ($created === []) {
            yield from $rows;

            return;
        }

        // The made-up field and the saved one have the same choices in the same order, and not the same ids.
        $choices = [];

        foreach ($plan->created() as $target) {
            if ($target->type instanceof ChoiceType) {
                $choices[$target->field->ulid] = array_combine(
                    array_column(ChoiceType::choicesOf($target->field->options), 'id'),
                    array_column(ChoiceType::choicesOf($created[$target->field->ulid]->options), 'id'),
                );
            }
        }

        foreach ($rows as $number => $row) {
            $values = [];

            foreach ($row as $ulid => $value) {
                if (! isset($created[$ulid])) {
                    $values[$ulid] = $value;

                    continue;
                }

                $values[$created[$ulid]->ulid] = isset($choices[$ulid]) && is_string($value) ? ($choices[$ulid][$value] ?? $value) : $value;
            }

            yield $number => $values;
        }
    }

    /**
     * Checks every row again against the fields as they are under the lock.
     *
     * @param  Collection<int, InventoryField>  $fields
     * @param  iterable<int, array<string, mixed>>  $rows  The values of each row, by its number in the file.
     * @param  array<string, string>  $labels
     * @return array{0: list<array<string, mixed>>, 1: list<array{row: int, column: string|null, message: string}>, 2: int} The values of the rows that are valid, the errors of the others (the first ones), and how many others there are.
     *
     * @throws ImportFailure In the mode that cancels everything, when a row is not valid.
     */
    private function check(Collection $fields, iterable $rows, array $labels, string $onError): array
    {
        $kept = [];
        $errors = new ImportErrors;

        foreach ($rows as $number => $values) {
            try {
                $kept[] = $this->values->apply($fields, $values, [], true);
            } catch (ValidationException $exception) {
                $errors->add(ImportErrors::fromValidation($exception, $number, $fields, $labels));
            }
        }

        if ($onError === 'abort' && $errors->any()) {
            throw new ImportFailure(ImportError::InvalidRows, $errors->all(), $errors->rows());
        }

        return [$kept, $errors->all(), $errors->rows()];
    }
}
