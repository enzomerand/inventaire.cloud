<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Database\DeadlockRetries;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Attachments\FileNames;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exceptions\ImportLimitReachedException;
use App\Inventories\Exceptions\ImportNotEditableException;
use App\Inventories\Exceptions\InvalidImportMapping;
use App\Inventories\Fields\FieldTypes;
use App\Jobs\ImportItems;
use App\Models\Import;
use App\Models\Inventory;
use App\Models\User;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

/**
 * The life of an import up to the moment the queue takes it: a file is sent and read, the person looks at the columns and changes how
 * the file is read, then chooses where each column goes and starts it. What the API does apart from the HTTP; the job that does the
 * rest is {@see ImportItems} ({@see ImportRunner}).
 *
 * A file is **sent** after two checks: its name must be one of a format the import reads (the content is checked against it when it is
 * read), and the person must not have as many unfinished imports of this inventory as they may ({@see ImportLimitReachedException}),
 * counted under the lock of the row of the person so that two files sent at the same moment cannot cross the quota together, and once
 * before the file is read, so that a person at the limit does not make the server read it. The file is read once to detect how it is
 * written and to propose a mapping ({@see ImportAnalyzer}): the answer comes with the request, not from a queue. It is written to the
 * disk before the row that records it, like the files of items, and deleted again if the row cannot be written.
 *
 * Until it is started an import can be **read again** with other options (the first row is not a header, the file is in another
 * encoding, the separator is another one): the columns and the proposal are made anew. **Starting** it checks the mapping against the
 * fields the inventory has now ({@see ImportMapping::assertValid()}), and moves it from `uploaded` to `pending` in one conditional
 * update, so that two requests cannot start it twice and a deletion cannot be undone; the job is queued once that is committed.
 */
final class Imports
{
    /**
     * Gets the database, the locks, the reader and the analyzer of files, the storage, and the registry of types.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly ImportReader $reader,
        private readonly ImportAnalyzer $analyzer,
        private readonly ImportFiles $files,
        private readonly AttachmentStorage $storage,
        private readonly FieldTypes $types,
    ) {}

    /**
     * Receives a file: reads it, proposes a mapping and records the import, `uploaded`.
     *
     * @param  array<string, mixed>  $input  What the request chose for the reading: `has_header`, `delimiter`, `encoding` (all optional).
     * @param  bool  $canManageFields  Whether the person may create fields, which the proposal depends on.
     *
     * @throws ImportFileException When the file is not one the import reads, is empty or damaged, or is too large once unpacked.
     * @throws ImportLimitReachedException When the person has as many unfinished imports of this inventory as they may.
     */
    public function upload(Inventory $inventory, User $user, UploadedFile $file, array $input, bool $canManageFields): Import
    {
        $name = FileNames::sanitize($file->getClientOriginalName());
        $format = ImportFormat::fromFileName($name) ?? throw new ImportFileException(ImportFileException::UNRECOGNIZED);
        $limit = config()->integer('inventories.max_active_imports');

        // Looked at before the file is read; judged again under the lock of the person.
        if ($this->activeCount($inventory, $user) >= $limit) {
            throw new ImportLimitReachedException($limit);
        }

        $local = $file->getRealPath();

        if ($local === false) {
            throw new ImportFileException(ImportFileException::UNREADABLE);
        }

        $locale = mb_substr(app()->getLocale(), 0, 12);
        $wanted = ImportOptions::fromArray(Arr::only($input, ['has_header', 'delimiter', 'encoding']), $locale);
        $rows = $this->reader->open($local, $format, $wanted->delimiter, $wanted->encoding);

        try {
            // Without a decimal sign of its own, the options take the one that goes with the separator that was found; the analysis
            // finds the one the values show, and the order of the dates.
            $options = ImportOptions::fromArray(['has_header' => $wanted->hasHeader, 'delimiter' => $rows->delimiter, 'encoding' => $rows->encoding], $locale);
            $analysis = $this->analyzer->analyze($rows, $options, $inventory->fields()->get(), $canManageFields, app()->getLocale());
            $options = $analysis->options;
        } finally {
            $rows->close();
        }

        $ulid = strtolower((string) Str::ulid());
        $disk = $this->storage->diskName();
        $path = $this->files->pathFor($inventory, $ulid, $format);
        $attributes = [
            'ulid' => $ulid,
            'inventory_id' => $inventory->getKey(),
            'user_id' => $user->getKey(),
            'format' => $format,
            'status' => ImportStatus::Uploaded,
            'disk' => $disk,
            'path' => $path,
            'file_name' => $name,
            'size' => (int) $file->getSize(),
            'locale' => $locale,
            'options' => $options->toArray(),
            'columns' => $analysis->columns,
            'mapping' => $analysis->mapping->toArray(),
            'expires_at' => now()->addHours(config()->integer('inventories.import_ttl_hours')),
        ];

        try {
            $this->files->store($disk, $path, $local);

            return DeadlockRetries::run(fn (): Import => $this->db->transaction(function () use ($inventory, $user, $limit, $attributes): Import {
                // The row of the person serialises their files: the count below cannot be crossed by two at once.
                $this->locks->users([$user->getKey()]);

                if ($this->activeCount($inventory, $user) >= $limit) {
                    throw new ImportLimitReachedException($limit);
                }

                // A fresh instance each time the transaction is tried: one that was saved by a try that was rolled back is not new.
                $import = new Import;
                $import->forceFill($attributes)->save();

                return $import;
            }));
        } catch (Throwable $failure) {
            $this->storage->forgetNow($disk, [$path]);

            throw $failure;
        }
    }

    /**
     * Reads the file again with other options, and proposes a mapping anew. Only what the request gives changes.
     *
     * @param  array<string, mixed>  $changes  Keyed as {@see ImportOptions::toArray()}: `has_header`, `delimiter`, `encoding` change how the
     *                                         file is read; `decimal`, `date_order`, `on_error` are kept.
     *
     * @throws ImportNotEditableException When the import was started, or its time is up.
     * @throws ImportFileException When the file cannot be read with these options.
     */
    public function reanalyze(Import $import, Inventory $inventory, array $changes, bool $canManageFields): Import
    {
        $this->assertEditable($import);

        $current = $import->settings();
        $merged = [...$current->toArray(), ...Arr::only($changes, ['has_header', 'delimiter', 'encoding', 'decimal', 'date_order', 'on_error'])];

        // A new separator brings its own decimal sign, unless the person chose one.
        if (isset($changes['delimiter']) && $changes['delimiter'] !== $current->delimiter && ! isset($changes['decimal'])) {
            unset($merged['decimal']);
        }

        $options = ImportOptions::fromArray($import->format === ImportFormat::Csv ? $merged : [...$merged, 'delimiter' => null, 'encoding' => null], $import->locale);
        $local = $this->files->localCopy($import);

        // What the person chose is kept; the rest is found out from the values again.
        $detect = array_values(array_diff(['decimal', 'date_order'], array_keys($changes)));

        try {
            $rows = $this->reader->open($local, $import->format, $options->delimiter, $options->encoding);

            try {
                $analysis = $this->analyzer->analyze($rows, $options, $inventory->fields()->get(), $canManageFields, app()->getLocale(), $detect);
                $options = $analysis->options;
            } finally {
                $rows->close();
            }
        } finally {
            @unlink($local);
        }

        $updated = Import::query()
            ->whereKey($import->getKey())
            ->where('status', ImportStatus::Uploaded->value)
            ->update([
                'options' => json_encode($options->toArray(), JSON_THROW_ON_ERROR),
                'columns' => json_encode($analysis->columns, JSON_THROW_ON_ERROR),
                'mapping' => json_encode($analysis->mapping->toArray(), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ImportNotEditableException;
        }

        return $import->refresh();
    }

    /**
     * Starts an import: checks the mapping against the fields, records it with the options and queues the job (`202`).
     *
     * @param  array<array-key, mixed>  $mapping  The entries of {@see ImportMapping}.
     * @param  array<string, mixed>  $settings  `decimal`, `date_order`, `on_error`: kept from the import when not given.
     *
     * @throws ImportNotEditableException When the import was started already, or its time is up.
     * @throws InvalidImportMapping When the mapping cannot be started (see {@see ImportMapping::assertValid()}).
     * @throws FieldLimitReachedException When the new fields would take the inventory past the number of fields it may have.
     */
    public function start(Import $import, Inventory $inventory, array $mapping, array $settings, bool $canManageFields): Import
    {
        $this->assertEditable($import);

        $entries = ImportMapping::from($mapping);
        $entries->assertValid($inventory->fields()->get(), $this->types, count($import->columns), $canManageFields);
        $options = $import->settings()->with(Arr::only($settings, ['decimal', 'date_order', 'on_error']));

        DeadlockRetries::run(fn () => $this->db->transaction(function () use ($import, $entries, $options): void {
            $claimed = Import::query()
                ->whereKey($import->getKey())
                ->where('status', ImportStatus::Uploaded->value)
                ->update([
                    'status' => ImportStatus::Pending->value,
                    'mapping' => json_encode($entries->toArray(), JSON_THROW_ON_ERROR),
                    'options' => json_encode($options->toArray(), JSON_THROW_ON_ERROR),
                    // Until it ends an import has no end date: the pruner gives up on one that is stuck by the day it was started.
                    'expires_at' => null,
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw new ImportNotEditableException;
            }

            ImportItems::dispatch($import)->afterCommit();
        }));

        return $import->refresh();
    }

    /**
     * How many unfinished imports of the inventory a person has: sent and not started yet, waiting for the queue, or running.
     */
    public function activeCount(Inventory $inventory, User $user): int
    {
        return Import::query()->where('user_id', $user->getKey())->where('inventory_id', $inventory->getKey())->active()->count();
    }

    /**
     * Refuses to change an import that was started, or whose file has expired.
     *
     * @throws ImportNotEditableException
     */
    private function assertEditable(Import $import): void
    {
        if ($import->status !== ImportStatus::Uploaded) {
            throw new ImportNotEditableException;
        }

        if ($import->isExpired()) {
            throw new ImportNotEditableException(ImportNotEditableException::EXPIRED);
        }
    }
}
