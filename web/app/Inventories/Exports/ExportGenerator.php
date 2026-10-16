<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\ItemValues;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Inventory;
use App\Support\DisplayTime;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Writes the file of an export and records how it went: what {@see GenerateExport} does, apart from the queue.
 *
 * The export is claimed first, `pending` to `running` in one conditional update, so that two workers handed the same job (or a job
 * handed again) cannot both write it; the one that loses simply stops. The ids of the items are listed, the writer of the format
 * writes a local scratch file, the file is stored under the folder of its export, and the export is then marked `completed` with
 * its size, the number of items and the end of its life, again in one conditional update: if the row is gone by then (the person
 * deleted the export while it was being written) or was given up on, the file just stored is purged instead.
 *
 * Anything that goes wrong marks the export `failed` with a code ({@see ExportError}) and deletes what was written: a view that
 * no longer holds (a field it filters on was deleted) is `invalid_query`, anything else is `generation_failed`, with the cause in
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

        $export->refresh();
        $locale = App::getLocale();
        $scratch = null;

        try {
            App::setLocale($export->locale);
            $scratch = ExportTemp::file();
            $this->generate($export, $scratch);
        } catch (ValidationException) {
            $this->fail($export, ExportError::InvalidQuery);
        } catch (Throwable $exception) {
            Log::error('An export could not be generated.', ['export' => $export->ulid, 'format' => $export->format->value, 'exception' => $exception]);
            $this->fail($export, ExportError::GenerationFailed);
        } finally {
            if ($scratch !== null) {
                @unlink($scratch);
            }

            App::setLocale($locale);
        }
    }

    /**
     * Gives up on an export that has not ended: `failed` with the code, its folder purged. An export that has ended, or is gone, is
     * left as it is (the folder is purged all the same: nothing of a failed attempt may stay).
     */
    public function fail(Export $export, ExportError $error): void
    {
        Export::query()
            ->whereKey($export->getKey())
            ->whereIn('status', [ExportStatus::Pending->value, ExportStatus::Running->value])
            ->update([
                'status' => ExportStatus::Failed->value,
                'error' => $error->value,
                'completed_at' => now(),
                'expires_at' => now()->addHours(config()->integer('inventories.export_ttl_hours')),
                'updated_at' => now(),
            ]);

        $this->cleanup->purge($export);
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
     * @throws ValidationException When the view of the export does not hold any more.
     */
    private function generate(Export $export, string $scratch): void
    {
        $context = $this->context($export);
        $inventory = $context->inventory;
        $count = $this->writer($export->format)->write($context, $scratch);

        $name = $this->fileName($inventory, $export->format);
        $path = AttachmentStorage::exportDirectory($inventory->workspace()->firstOrFail()->ulid, $inventory->ulid, $export->ulid).'/'.$name;
        $this->store($export->disk, $path, $scratch);

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
            // Deleted, or given up on, while it was being written: nobody will ever ask for this file.
            $this->cleanup->purge($export);
        }
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
     * The name the file is downloaded as: the name of the inventory and the day, in the timezone of the instance.
     */
    private function fileName(Inventory $inventory, ExportFormat $format): string
    {
        return ExportNames::entry($inventory->name, __('Items'), 80).' - '.DisplayTime::of(now())->format('Y-m-d').'.'.$format->extension();
    }

    /**
     * Copies the scratch file to the disk of the export.
     *
     * @throws RuntimeException When the file cannot be read or written.
     */
    private function store(string $disk, string $path, string $scratch): void
    {
        $this->storage->writeFrom($disk, $path, $scratch);
    }
}
