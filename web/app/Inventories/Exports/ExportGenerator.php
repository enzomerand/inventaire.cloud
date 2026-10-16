<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\PdfTooLargeException;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemValues;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Inventory;
use App\Support\DisplayTime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes the file of an export and records how it went: what {@see GenerateExport} does, apart from the queue.
 *
 * The export is claimed first, `pending` to `running` in one conditional update, so that two workers handed the same job (or a job
 * handed again) cannot both write it; the one that loses simply stops. The ids of the items are listed, the writer of the format
 * writes a local scratch file, the file is stored under the folder of its export, and the export is then marked `completed` with
 * its size, the number of items and the end of its life, again in one conditional update: if the row is gone by then (the person
 * deleted the export while it was being written, or its inventory or workspace was deleted) or was given up on, the file just
 * stored is purged instead, by the folder it was written to: the row it belongs to is no longer there to find it from.
 *
 * Anything that goes wrong marks the export `failed` with a code ({@see ExportError}) and deletes what was written: a view that
 * no longer holds (a field it filters on was deleted) is `invalid_query`, a PDF the library cannot lay out (the inventory has
 * grown since it was asked for, or its texts are long) is `pdf_too_large`, anything else is `generation_failed`, with the cause in
 * the log. A failed export is kept as long as a completed one, so that the person sees it, and can delete it, before it goes away.
 * The file is written in the language of the export, whatever the language of the worker.
 */
final class ExportGenerator
{
    /**
     * Gets what the lists of items, the columns and the files are made with.
     */
    public function __construct(
        private readonly ExportItems $items,
        private readonly FieldTypes $types,
        private readonly ItemValues $values,
        private readonly AttachmentStorage $storage,
        private readonly ExportCleanup $cleanup,
    ) {}

    /**
     * Writes the export, unless another worker has already taken it. Never throws for what goes wrong with the export itself: it is
     * recorded on the export.
     */
    public function run(Export $export): void
    {
        $claimed = Export::query()
            ->whereKey($export->getKey())
            ->where('status', ExportStatus::Pending->value)
            ->update(['status' => ExportStatus::Running->value, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $locale = App::getLocale();
        $scratch = null;
        $path = null;

        try {
            // Read inside the try: the row may have been deleted since it was claimed.
            $export->refresh();
            App::setLocale($export->locale);
            $scratch = ExportTemp::file();
            $this->generate($export, $scratch, $path);
        } catch (ModelNotFoundException $exception) {
            // The export, its inventory or its workspace was deleted while the file was written: the export is gone with them, there
            // is nothing to report, only what was stored to purge. A model that is missing while the export is still there is
            // something else (a record the export depends on, lost), and it is told in the log before the export is failed.
            if (Export::query()->whereKey($export->getKey())->exists()) {
                Log::warning('An export could not be generated: a record it needs is missing.', ['export' => $export->ulid, 'format' => $export->format->value, 'exception' => $exception]);
            }

            $this->fail($export, ExportError::GenerationFailed, $path);
        } catch (ValidationException) {
            $this->fail($export, ExportError::InvalidQuery, $path);
        } catch (PdfTooLargeException $exception) {
            // What the inventory held when it was asked for was fine: it has grown since, or its texts are long. The person can
            // narrow the view; nothing is wrong with the server, so it is not an error of the log.
            Log::warning('A PDF export was too large for the library.', ['export' => $export->ulid, 'message' => $exception->getMessage()]);
            $this->fail($export, ExportError::PdfTooLarge, $path);
        } catch (Throwable $exception) {
            Log::error('An export could not be generated.', ['export' => $export->ulid, 'format' => $export->format->value, 'exception' => $exception]);
            $this->fail($export, ExportError::GenerationFailed, $path);
        } finally {
            if ($scratch !== null) {
                @unlink($scratch);
            }

            App::setLocale($locale);
        }
    }

    /**
     * Gives up on an export that has not ended: `failed` with the code, without a size (a failed export holds nothing), its
     * folder purged. An export that has completed in the meantime is left as it is, file included: a late call (a job reported
     * failed after it ended, the pruner acting on a row it read before it completed) must never delete a file somebody can
     * download. For an export that is gone, or already failed, the folder is purged all the same: nothing of a failed attempt may
     * stay.
     *
     * @param  string|null  $path  The file this attempt stored, when it got that far: its folder is purged by that path, which still
     *                             works when the inventory (and so the export) is gone. Otherwise the folder is worked out from the row.
     */
    public function fail(Export $export, ExportError $error, ?string $path = null): void
    {
        Export::query()
            ->whereKey($export->getKey())
            ->whereIn('status', [ExportStatus::Pending->value, ExportStatus::Running->value])
            ->update([
                'status' => ExportStatus::Failed->value,
                'error' => $error->value,
                'size' => null,
                'completed_at' => now(),
                'expires_at' => now()->addHours(config()->integer('inventories.export_ttl_hours')),
                'updated_at' => now(),
            ]);

        // Whatever the update did, the row is now completed (it won the race: keep its file), failed or gone (purge).
        if (Export::query()->whereKey($export->getKey())->where('status', ExportStatus::Completed->value)->exists()) {
            return;
        }

        if ($path === null) {
            $this->cleanup->purge($export);
        } else {
            $this->purgeFolderOf($export->disk, $path);
        }
    }

    /**
     * What a writer needs for an export: its inventory and fields, and the ids of the items it holds, listed now in their order.
     *
     * @throws ValidationException When the view of the export does not hold any more (a field it filters or sorts by was deleted).
     */
    public function context(Export $export): ExportContext
    {
        $inventory = Inventory::query()->findOrFail($export->inventory_id);
        $fields = $inventory->fields()->get();
        $table = new ExportTable($fields, $this->types, $this->values);

        $view = $export->scope === ExportScope::View && is_array($export->option('query')) ? $export->option('query') : [];
        $ids = $this->items->ids($this->items->matching($inventory, $fields, $export->scope, $view));

        return new ExportContext($export, $inventory, $table->fields(), $ids, $table, $this->items);
    }

    /**
     * Lists the items, writes the file in `$scratch`, stores it and completes the export.
     *
     * @param  string|null  $path  Set to where the file is stored as soon as that is known, before it is written to the disk: if
     *                             storing it fails half way, the caller still knows which folder to purge.
     *
     * @param-out string $path
     *
     * @throws ValidationException When the view of the export does not hold any more.
     */
    private function generate(Export $export, string $scratch, ?string &$path): void
    {
        $context = $this->context($export);
        $inventory = $context->inventory;
        $count = $this->writer($export->format)->write($context, $scratch);

        $name = $this->fileName($inventory, $export->format);
        $path = AttachmentStorage::exportDirectory($inventory->workspace()->firstOrFail()->ulid, $inventory->ulid, $export->ulid).'/'.$name;
        $this->storage->writeFrom($export->disk, $path, $scratch);

        $completed = Export::query()
            ->whereKey($export->getKey())
            ->where('status', ExportStatus::Running->value)
            ->update([
                'status' => ExportStatus::Completed->value,
                'path' => $path,
                'file_name' => $name,
                'mime' => $export->format->mime(),
                'size' => (int) filesize($scratch),
                'items_count' => $count,
                'completed_at' => now(),
                'expires_at' => now()->addHours(config()->integer('inventories.export_ttl_hours')),
                'updated_at' => now(),
            ]);

        if ($completed !== 1) {
            // Deleted, or given up on, while it was being written: nobody will ever ask for this file. Purged by its folder, not
            // through the row: the inventory or the workspace may have been deleted too (and its own purge may have run before
            // the file was stored), and the row that says where the folder is is gone with it.
            $this->purgeFolderOf($export->disk, $path);
        }
    }

    /**
     * Has the folder of a stored export file purged once the transaction in progress is committed (at once when there is none).
     */
    private function purgeFolderOf(string $disk, string $path): void
    {
        $this->storage->purgeAfterCommit($disk, [], [dirname($path)]);
    }

    /**
     * The writer of a format.
     */
    private function writer(ExportFormat $format): ExportWriter
    {
        return match ($format) {
            ExportFormat::Xlsx, ExportFormat::Ods => new SpreadsheetWriter($format),
            ExportFormat::Csv => new CsvWriter,
            ExportFormat::Json => new JsonWriter,
            ExportFormat::Pdf => new PdfWriter,
            ExportFormat::Zip => app(ZipWriter::class),
        };
    }

    /**
     * The name the file is downloaded as: the name of the inventory and the day, in the timezone of the instance. The whole name,
     * day and extension included, is at most {@see ExportNames::FILE_MAX_BYTES} bytes.
     */
    private function fileName(Inventory $inventory, ExportFormat $format): string
    {
        $suffix = ' - '.DisplayTime::of(now())->format('Y-m-d').'.'.$format->value;

        return ExportNames::entry($inventory->name, __('Items'), ExportNames::FILE_MAX_BYTES - strlen($suffix)).$suffix;
    }
}
