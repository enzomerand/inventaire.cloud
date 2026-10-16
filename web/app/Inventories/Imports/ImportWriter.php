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
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
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
 * The values of a field that is created were keyed by a ULID made up for it, and a choice by the id the made-up field gave it: they are
 * rewritten with the ULID and the ids the saved field has. The fields are saved first, so that the items refer to fields that exist.
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
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows  The rows that were valid, by their number in the file, with their stored values keyed by the ULID of the field (a made-up one for a field to create).
     * @param  string  $onError  `abort` or `skip`: what to do with a row that turns out not to be valid under the lock.
     *
     * @throws ImportFailure When the import cannot go through: nothing is written then.
     */
    public function write(Inventory $inventory, User $author, ImportPlan $plan, array $rows, string $onError): ImportWritten
    {
        return DeadlockRetries::run(fn (): ImportWritten => $this->db->transaction(function () use ($inventory, $author, $plan, $rows, $onError): ImportWritten {
            $this->locks->author($author);
            $locked = $this->locks->inventory($inventory->getKey());
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
            $rows = $this->rewrite($rows, $plan, $created);

            $skipped = [];
            $skippedRows = 0;

            if (! $unchanged) {
                [$rows, $skipped, $skippedRows] = $this->check($fields, $rows, $labels, $onError);
            }

            try {
                $this->items->createMany($locked, $inventory, $fields, array_column($rows, 'values'), $author);
            } catch (ItemLimitReachedException) {
                throw new ImportFailure(ImportError::ItemLimitReached);
            }

            return new ImportWritten(count($rows), count($created), $skipped, $skippedRows);
        }));
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
     * The rows with the ULID of each saved field in place of the made-up one, and the ids of its choices in place of the made-up ones.
     *
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows
     * @param  array<string, InventoryField>  $created
     * @return list<array{row: int, values: array<string, mixed>}>
     */
    private function rewrite(array $rows, ImportPlan $plan, array $created): array
    {
        if ($created === []) {
            return $rows;
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

        foreach ($rows as $position => $row) {
            $values = [];

            foreach ($row['values'] as $ulid => $value) {
                if (! isset($created[$ulid])) {
                    $values[$ulid] = $value;

                    continue;
                }

                $values[$created[$ulid]->ulid] = isset($choices[$ulid]) && is_string($value) ? ($choices[$ulid][$value] ?? $value) : $value;
            }

            $rows[$position]['values'] = $values;
        }

        return $rows;
    }

    /**
     * Checks every row again against the fields as they are under the lock.
     *
     * @param  Collection<int, InventoryField>  $fields
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows
     * @param  array<string, string>  $labels
     * @return array{0: list<array{row: int, values: array<string, mixed>}>, 1: list<array{row: int, column: string|null, message: string}>, 2: int} The rows that are valid, the errors of the others (the first ones), and how many others there are.
     *
     * @throws ImportFailure In the mode that cancels everything, when a row is not valid.
     */
    private function check(Collection $fields, array $rows, array $labels, string $onError): array
    {
        $kept = [];
        $errors = new ImportErrors;

        foreach ($rows as $row) {
            try {
                $kept[] = ['row' => $row['row'], 'values' => $this->values->apply($fields, $row['values'], [], true)];
            } catch (ValidationException $exception) {
                $errors->add(ImportErrors::fromValidation($exception, $row['row'], $fields, $labels));
            }
        }

        if ($onError === 'abort' && $errors->any()) {
            throw new ImportFailure(ImportError::InvalidRows, $errors->all(), $errors->rows());
        }

        return [$kept, $errors->all(), $errors->rows()];
    }
}
