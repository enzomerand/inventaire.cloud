<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Inventories\Exceptions\ImportFailure;
use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exceptions\InvalidImportValue;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\Currencies;
use App\Inventories\Fields\DecimalType;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\MoneyType;
use App\Inventories\Fields\NumberType;
use App\Inventories\Fields\PercentageType;
use App\Inventories\Fields\RatingType;
use App\Inventories\Fields\TextType;
use App\Inventories\ItemValues;
use App\Inventories\SearchText;
use App\Jobs\ImportItems;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\User;
use App\Rules\SingleLineText;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Does the work of an import and records how it went: what {@see ImportItems} does, apart from the queue.
 *
 * The import is claimed first, `pending` to `running` in one conditional update, so that two workers handed the same job (or a job
 * handed again) cannot both do it; the one that loses simply stops. A copy of the file is read from the disk, then:
 *
 * 1. **The fields to create are drawn.** A field created from a column has no options the person could choose, so they are worked out
 *    from the values: the decimals of a number are the most the column uses (up to the type's limit), an amount has the currency its
 *    values are written with, a text is as long as the longest, a rating goes up to the highest grade, and the choices of a choice field
 *    are the different values of its column, the case and the accents ignored, with the first spelling kept ({@see ChoiceType::MAX_CHOICES}
 *    at most). That is a first pass over the file, only when a field is created.
 * 2. **Every row is converted and checked**, outside any lock: each cell is read by the type of its field ({@see FieldType::importValue()}),
 *    then the values of the row are checked as an item's are ({@see ItemValues::apply()}). The file is read to the end whatever happens, so
 *    that the report counts all the rows in error; the first {@see ImportErrors::MAX} errors are kept. A file with more rows than an
 *    import takes (`inventories.import_max_rows`, empty rows apart) fails.
 * 3. **The rows are written** by {@see ImportWriter}, in one transaction, unless a row is in error and the person chose to cancel
 *    everything in that case: then nothing is written.
 *
 * The import ends `completed`, with its report and the end of its life (the report stays as long as an export does), or `failed` with
 * a code ({@see ImportError}); the source file is deleted either way and `path` is emptied. A person who no longer may change the items
 * of the inventory by the time the job runs gets `not_allowed`. The messages are written in the language of the import, whatever the
 * language of the worker. An import deleted while it runs is not an error: the file is deleted by its folder, as the row that says
 * where it is is gone.
 */
final class ImportRunner
{
    /**
     * Gets what reading, converting and writing need.
     */
    public function __construct(
        private readonly ImportReader $reader,
        private readonly ImportFiles $files,
        private readonly FieldTypes $types,
        private readonly ItemValues $values,
        private readonly ImportWriter $writer,
    ) {}

    /**
     * Does the import, unless another worker has already taken it. Never throws for what goes wrong with the import itself: it is
     * recorded on the import.
     */
    public function run(Import $import): void
    {
        $claimed = Import::query()
            ->whereKey($import->getKey())
            ->where('status', ImportStatus::Pending->value)
            ->update(['status' => ImportStatus::Running->value, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $locale = App::getLocale();
        $disk = $import->disk;
        $path = $import->path;

        try {
            // Read inside the try: the row may have been deleted since it was claimed.
            $import->refresh();
            App::setLocale($import->locale);
            $this->process($import);
        } catch (ModelNotFoundException $exception) {
            // The import, its inventory or the person was deleted while it ran: the import is gone with them or there is nothing
            // to report; only the file is left to delete. A model that is missing while the import is still there is something
            // else (a record the import depends on, lost), and it is told in the log before the import is failed.
            if (Import::query()->whereKey($import->getKey())->exists()) {
                Log::warning('An import could not be done: a record it needs is missing.', ['import' => $import->ulid, 'exception' => $exception]);
                $this->fail($import, ImportError::ImportFailed);
            } else {
                $this->files->delete($disk, $path);
            }
        } catch (ImportFailure $failure) {
            $this->fail($import, $failure->error, $failure->errors, $failure->failedRows, $failure->rowsTotal);
        } catch (ImportFileException $failure) {
            $this->fail($import, ImportError::FileUnreadable);
        } catch (Throwable $exception) {
            Log::error('An import could not be done.', ['import' => $import->ulid, 'exception' => $exception]);
            $this->fail($import, ImportError::ImportFailed);
        } finally {
            App::setLocale($locale);
        }
    }

    /**
     * Gives up on an import that has not ended: `failed` with the code and the report of the rows in error, if any, its source file
     * deleted. An import that has completed in the meantime is left as it is: a late call (a job reported failed after it ended, the
     * pruner acting on a row it read before it completed) must never undo a result. For an import that is gone, or already failed,
     * the file is deleted all the same: nothing of a failed attempt may stay.
     *
     * @param  list<array{row: int, column: string|null, message: string}>  $errors  The first rows in error.
     * @param  int  $failedRows  How many rows are in error.
     * @param  int|null  $total  How many rows the file holds, when it was read to the end: the counts are then part of the report (nothing was imported).
     */
    public function fail(Import $import, ImportError $error, array $errors = [], int $failedRows = 0, ?int $total = null): void
    {

        Import::query()
            ->whereKey($import->getKey())
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Running->value])
            ->update([
                'status' => ImportStatus::Failed->value,
                'error' => $error->value,
                'path' => null,
                'rows_total' => $total,
                'rows_imported' => $total === null ? null : 0,
                'rows_failed' => $total === null ? null : $failedRows,
                'fields_created' => $total === null ? null : 0,
                'errors' => $total === null ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'expires_at' => now()->addHours(config()->integer('inventories.import_ttl_hours')),
                'updated_at' => now(),
            ]);

        // The source was deleted by the completion that won the race, or has to go now.
        $this->files->delete($import->disk, $import->path ?? $this->knownPath($import));
    }

    /**
     * Converts and checks the rows, writes them and records the result.
     *
     * @throws ImportFailure
     * @throws ImportFileException
     * @throws ModelNotFoundException
     */
    private function process(Import $import): void
    {
        $inventory = Inventory::query()->findOrFail($import->inventory_id);
        $author = User::query()->findOrFail($import->user_id);
        $mapping = ImportMapping::from($import->mapping ?? []);

        $gate = Gate::forUser($author);

        if (! $gate->allows('editItems', $inventory) || ($this->createsFields($mapping) && ! $gate->allows('manageFields', $inventory))) {
            throw new ImportFailure(ImportError::NotAllowed);
        }

        $options = $import->settings();
        $context = ImportContext::for($options, $import->locale);
        $local = $this->files->localCopy($import);

        try {
            $fields = $inventory->fields()->get();
            $targets = $this->targets($import, $mapping, $fields);
            $targets = $this->draw($local, $import, $options, $context, $targets);
            $plan = new ImportPlan($targets, ImportPlan::fingerprint($fields));
            [$rows, $total, $errors] = $this->convert($local, $import, $options, $context, $plan, $fields);
        } finally {
            @unlink($local);
        }

        if ($options->onError === 'abort' && $errors->any()) {
            throw new ImportFailure(ImportError::InvalidRows, $errors->all(), $errors->rows(), $total);
        }

        try {
            $written = $this->writer->write($inventory, $author, $plan, $rows, $options->onError);
        } catch (ImportFailure $failure) {
            // The rows were counted and checked: the report says how many, and which were not valid.
            throw new ImportFailure($failure->error, $failure->errors !== [] ? $failure->errors : $errors->all(), $failure->failedRows > 0 ? $failure->failedRows : $errors->rows(), $total);
        }

        $this->complete($import, $total, $written, $errors);
    }

    /**
     * Whether the mapping creates a field.
     */
    private function createsFields(ImportMapping $mapping): bool
    {
        foreach ($mapping->mapped() as $entry) {
            if ($entry['target'] === ImportMapping::NEW) {
                return true;
            }
        }

        return false;
    }

    /**
     * The columns that go somewhere, each with its field: the field of the inventory, or one made up for a field to create, with the
     * options of its type by default (drawn from the values by {@see self::draw()}).
     *
     * @param  Collection<int, InventoryField>  $fields
     * @return list<ImportTarget>
     *
     * @throws ImportFailure When a field of the mapping is gone, or cannot be filled by an import.
     */
    private function targets(Import $import, ImportMapping $mapping, Collection $fields): array
    {
        $byUlid = $fields->keyBy('ulid');
        $headers = array_column($import->columns, 'header', 'index');
        $targets = [];

        foreach ($mapping->mapped() as $entry) {
            $label = (string) ($headers[$entry['column']] ?? ImportAnalyzer::letter($entry['column']));

            if ($entry['target'] === ImportMapping::FIELD) {
                $field = $byUlid[$entry['field'] ?? ''] ?? null;
                $type = $field === null ? null : $this->types->of($field);

                if ($field === null || $type === null || $type->filledByUpload()) {
                    throw new ImportFailure(ImportError::FieldsChanged);
                }

                $targets[] = new ImportTarget($entry['column'], $label, $field, $type, false);

                continue;
            }

            $type = $this->types->find((string) $entry['type']);

            if ($type === null || $type->filledByUpload()) {
                throw new ImportFailure(ImportError::FieldsChanged);
            }

            $targets[] = new ImportTarget($entry['column'], $label, $this->madeUp((string) $entry['name'], $type, []), $type, true);
        }

        return $targets;
    }

    /**
     * A field that is not saved, to check the values against, with a ULID of its own.
     *
     * @param  array<string, mixed>  $options  As the type takes them; the type completes them.
     */
    private function madeUp(string $name, FieldType $type, array $options): InventoryField
    {
        try {
            $normalized = $type->normalizeOptions($options, null);
        } catch (ValidationException) {
            // A list of choices with no choice yet: the choices are drawn from the values before anything is checked against the field.
            $normalized = [];
        }

        $field = new InventoryField(['name' => $name, 'type' => $type->key(), 'options' => $normalized === [] ? null : $normalized, 'required' => false]);
        $field->ulid = strtolower((string) Str::ulid());

        return $field;
    }

    /**
     * Draws the options of the fields to create from the values of their columns (see the class). A first pass over the file, made only
     * when a field is created that has something to draw.
     *
     * @param  list<ImportTarget>  $targets
     * @return list<ImportTarget> The targets, those of the fields to create with their options.
     *
     * @throws ImportFailure When a column holds too many different values for a list of choices, or none for a field that needs one.
     * @throws ImportFileException
     */
    private function draw(string $local, Import $import, ImportOptions $options, ImportContext $context, array $targets): array
    {
        $drawn = array_values(array_filter($targets, fn (ImportTarget $target): bool => $target->new && in_array($target->type->key(), ['number', 'money', 'percentage', 'text', 'select', 'rating'], true)));

        if ($drawn === []) {
            return $targets;
        }

        $seen = [];

        foreach ($drawn as $target) {
            $seen[$target->column] = ['scale' => 0, 'currency' => null, 'length' => 0, 'grade' => 0, 'choices' => []];
        }

        $rows = $this->reader->open($local, $import->format, $options->delimiter, $options->encoding);

        try {
            $header = $options->hasHeader;
            $count = 0;

            foreach ($rows->rows() as $cells) {
                if ($header) {
                    $header = false;

                    continue;
                }

                if (++$count > config()->integer('inventories.import_max_rows')) {
                    throw new ImportFailure(ImportError::TooManyRows);
                }

                foreach ($drawn as $target) {
                    $cell = $cells[$target->column] ?? null;

                    if ($cell !== null && ! $cell->isEmpty()) {
                        $this->look($target, $cell, $context, $seen[$target->column]);
                    }
                }
            }
        } finally {
            $rows->close();
        }

        return array_map(fn (ImportTarget $target): ImportTarget => isset($seen[$target->column]) && $target->new
            ? new ImportTarget($target->column, $target->label, $this->madeUp($target->field->name, $target->type, $this->optionsFor($target, $seen[$target->column])), $target->type, true)
            : $target, $targets);
    }

    /**
     * What a value of a column says about the field it would become.
     *
     * @param  array{scale: int, currency: string|null, length: int, grade: int, choices: array<string, string>}  $seen
     *
     * @throws ImportFailure When the column holds too many different values for a list of choices.
     */
    private function look(ImportTarget $target, ImportCell $cell, ImportContext $context, array &$seen): void
    {
        if ($target->type instanceof DecimalType) {
            try {
                $read = ImportNumbers::read($cell, $context);
            } catch (InvalidImportValue) {
                return;
            }

            $seen['scale'] = max($seen['scale'], strlen($read['number']->fraction));
            $seen['currency'] ??= $read['currency'];
        } elseif ($target->type instanceof TextType) {
            $seen['length'] = max($seen['length'], mb_strlen($cell->line()));
        } elseif ($target->type instanceof RatingType) {
            try {
                $grade = $target->type->importValue($cell, $target->field, $context);
            } catch (InvalidImportValue) {
                return;
            }

            $seen['grade'] = max($seen['grade'], is_int($grade) ? $grade : 0);
        } elseif ($target->type instanceof ChoiceType) {
            $label = SingleLineText::normalize($cell->line());
            $key = SearchText::normalize($label);

            if ($label === '' || mb_strlen($label) > ChoiceType::LABEL_MAX_LENGTH || isset($seen['choices'][$key])) {
                return;
            }

            if (count($seen['choices']) >= ChoiceType::MAX_CHOICES) {
                throw new ImportFailure(ImportError::TooManyChoices);
            }

            $seen['choices'][$key] = $label;
        }
    }

    /**
     * The options of a field to create, drawn from what its column holds.
     *
     * @param  array{scale: int, currency: string|null, length: int, grade: int, choices: array<string, string>}  $seen
     * @return array<string, mixed>
     *
     * @throws ImportFailure When a field of choices has no choice to offer.
     */
    private function optionsFor(ImportTarget $target, array $seen): array
    {
        return match (true) {
            $target->type instanceof NumberType => ['decimals' => min($seen['scale'], NumberType::MAX_DECIMALS)],
            $target->type instanceof PercentageType => ['decimals' => min($seen['scale'], PercentageType::MAX_DECIMALS)],
            $target->type instanceof MoneyType => ['currency' => $seen['currency'] !== null && Currencies::exists($seen['currency']) ? $seen['currency'] : MoneyType::DEFAULT_CURRENCY],
            $target->type instanceof TextType => ['max_length' => min(TextType::LIMIT, max(TextType::DEFAULT_MAX_LENGTH, $seen['length']))],
            $target->type instanceof RatingType => ['max' => min(RatingType::MAX_OF_MAX, max(RatingType::DEFAULT_MAX, $seen['grade']))],
            $target->type instanceof ChoiceType => $seen['choices'] === []
                ? throw new ImportFailure(ImportError::NoChoices)
                : ['choices' => array_map(fn (string $label): array => ['label' => $label], array_values($seen['choices']))],
            default => [],
        };
    }

    /**
     * Converts and checks every row of the file.
     *
     * @param  Collection<int, InventoryField>  $fields  The fields of the inventory.
     * @return array{0: list<array{row: int, values: array<string, mixed>}>, 1: int, 2: ImportErrors} The rows that are valid with their stored values, how many rows the file holds, and the report of the others.
     *
     * @throws ImportFailure When the file holds more rows than an import takes.
     * @throws ImportFileException
     */
    private function convert(string $local, Import $import, ImportOptions $options, ImportContext $context, ImportPlan $plan, Collection $fields): array
    {
        $all = $fields->concat(array_map(fn (ImportTarget $target): InventoryField => $target->field, $plan->created()))->values();
        $labels = $plan->labels();
        $limit = config()->integer('inventories.import_max_rows');
        $errors = new ImportErrors;
        $rows = [];
        $total = 0;
        $header = $options->hasHeader;
        $source = $this->reader->open($local, $import->format, $options->delimiter, $options->encoding);

        try {
            foreach ($source->rows() as $number => $cells) {
                if ($header) {
                    $header = false;

                    continue;
                }

                if (++$total > $limit) {
                    throw new ImportFailure(ImportError::TooManyRows);
                }

                $found = $this->convertRow($number, $cells, $plan, $context, $all, $labels);

                if ($found['errors'] === []) {
                    $rows[] = ['row' => $number, 'values' => $found['values']];
                } else {
                    $errors->add($found['errors']);
                }
            }
        } finally {
            $source->close();
        }

        return [$rows, $total, $errors];
    }

    /**
     * Converts and checks one row.
     *
     * @param  list<ImportCell>  $cells
     * @param  Collection<int, InventoryField>  $all  The fields of the inventory and those to create.
     * @param  array<string, string>  $labels
     * @return array{values: array<string, mixed>, errors: list<array{row: int, column: string|null, message: string}>}
     */
    private function convertRow(int $number, array $cells, ImportPlan $plan, ImportContext $context, Collection $all, array $labels): array
    {
        $input = [];
        $errors = [];

        foreach ($plan->targets as $target) {
            $cell = $cells[$target->column] ?? null;

            if ($cell === null || $cell->isEmpty()) {
                continue;
            }

            try {
                $value = $target->type->importValue($cell, $target->field, $context);
            } catch (InvalidImportValue $exception) {
                $errors[] = ['row' => $number, 'column' => $target->label, 'message' => $exception->getMessage()];

                continue;
            }

            if ($value !== null) {
                $input[$target->field->ulid] = $value;
            }
        }

        if ($errors !== []) {
            return ['values' => [], 'errors' => $errors];
        }

        try {
            return ['values' => $this->values->apply($all, $input, [], true), 'errors' => []];
        } catch (ValidationException $exception) {
            return ['values' => [], 'errors' => ImportErrors::fromValidation($exception, $number, $all, $labels)];
        }
    }

    /**
     * Records the result: `completed`, with the report, the end of its life, and the source file deleted.
     */
    private function complete(Import $import, int $total, ImportWritten $written, ImportErrors $errors): void
    {
        $failed = $errors->rows() + $written->skippedRows;
        $reported = array_slice([...$errors->all(), ...$written->skipped], 0, ImportErrors::MAX);

        Import::query()
            ->whereKey($import->getKey())
            ->where('status', ImportStatus::Running->value)
            ->update([
                'status' => ImportStatus::Completed->value,
                'path' => null,
                'rows_total' => $total,
                'rows_imported' => $written->created,
                'rows_failed' => $failed,
                'fields_created' => $written->fields,
                'errors' => json_encode($reported, JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'expires_at' => now()->addHours(config()->integer('inventories.import_ttl_hours')),
                'updated_at' => now(),
            ]);

        // Whatever the update did (the import may have been deleted meanwhile), the source is not needed any more.
        $this->files->delete($import->disk, $import->path);
    }

    /**
     * Where the source of an import is, worked out from the row when the row does not say (it was emptied already).
     */
    private function knownPath(Import $import): ?string
    {
        try {
            $inventory = Inventory::query()->find($import->inventory_id);

            return $inventory === null ? null : $this->files->pathFor($inventory, $import->ulid, $import->format);
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
