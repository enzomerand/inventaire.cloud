<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Database\DeadlockRetries;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\ExportLimitReachedException;
use App\Inventories\Exceptions\ExportRefusedException;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\UploadedFilesType;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\User;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Asks for an export and deletes one: what the API does apart from the HTTP.
 *
 * An export is **asked for** after three checks, in this order. The view it is made on must hold for the fields the inventory has
 * now (the mistakes are those of the list of items, under `query.`: a `ValidationException`). A PDF must not hold more items than the
 * library can lay out in memory, and a zip must have a file to hold ({@see ExportRefusedException}). The number of exports the person
 * has, not expired ones whatever their state, is then counted under the lock of their row: two requests at the same moment cannot
 * cross the limit together ({@see ExportLimitReachedException}). The row is created `pending` and the job that writes the file is
 * queued once the transaction is committed, so that no worker looks for a row that is not there yet.
 *
 * The checks of the first kind are made on what the inventory holds *now*; the job lists the items again when it runs. An inventory
 * that changes in between changes the file, not the checks: the PDF refuses at write time what it was allowed at request time.
 */
final class Exports
{
    /**
     * Gets the database, the locks, the lists of items and the storage whose disk the files are written to.
     */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Locks $locks,
        private readonly ExportItems $items,
        private readonly FieldTypes $types,
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * Asks for an export of an inventory on behalf of a person.
     *
     * @param  array<string, mixed>  $options  What the format needs: `query` (the view, for the scope `view`), `delimiter` (CSV), `files` (zip).
     *
     * @throws ValidationException When the view does not hold for the fields of the inventory; the keys are under `query.`.
     * @throws ExportRefusedException For a PDF with too many items, or a zip with no file.
     * @throws ExportLimitReachedException When the person has as many exports as they may.
     */
    public function request(Inventory $inventory, User $user, ExportFormat $format, ExportScope $scope, array $options = []): Export
    {
        $this->check($inventory, $format, $scope, $options);

        $limit = config()->integer('inventories.max_active_exports');

        return DeadlockRetries::run(fn (): Export => $this->db->transaction(function () use ($inventory, $user, $format, $scope, $options, $limit): Export {
            // The row of the person serialises their requests: the count below cannot be crossed by two at once.
            $this->locks->users([$user->getKey()]);

            if (Export::query()->where('user_id', $user->getKey())->active()->count() >= $limit) {
                throw new ExportLimitReachedException($limit);
            }

            $export = new Export;
            $export->forceFill([
                'inventory_id' => $inventory->getKey(),
                'user_id' => $user->getKey(),
                'format' => $format,
                'scope' => $scope,
                'options' => $options,
                'locale' => mb_substr(app()->getLocale(), 0, 12),
                'status' => ExportStatus::Pending,
                'disk' => $this->storage->diskName(),
            ])->save();

            GenerateExport::dispatch($export)->afterCommit();

            return $export;
        }));
    }

    /**
     * Deletes an export: its row now, its file once the deletion is committed. One that is being written is deleted all the same;
     * the worker finds the row gone when it is done and deletes the file it wrote.
     */
    public function delete(Export $export): void
    {
        $export->delete();
    }

    /**
     * The checks that depend on what the inventory holds: the view, the size of a PDF, the files of a zip.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ValidationException
     * @throws ExportRefusedException
     */
    private function check(Inventory $inventory, ExportFormat $format, ExportScope $scope, array $options): void
    {
        $fields = $inventory->fields()->get();
        $view = is_array($options['query'] ?? null) ? $options['query'] : [];

        try {
            $matching = $this->items->matching($inventory, $fields, $scope, $view);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['query.'.$key => $messages])->all());
        }

        if ($format === ExportFormat::Pdf) {
            $limit = PdfWriter::maxItems($fields->filter(fn (InventoryField $field): bool => $this->types->of($field) !== null)->count());

            if ($this->items->count($matching) > $limit) {
                throw new ExportRefusedException(ExportRefusedException::PDF_TOO_LARGE, $limit);
            }
        }

        if ($format === ExportFormat::Zip) {
            $files = $this->db->table('attachments')->where('inventory_id', $inventory->getKey());

            if (($options['files'] ?? ZipWriter::PHOTOS) !== ZipWriter::ALL) {
                $files->whereIn('field_id', $this->photoFieldIds($fields));
            }

            if ($scope === ExportScope::View) {
                $files->whereIn('item_id', $this->items->subQuery($matching));
            }

            if (! $files->exists()) {
                throw new ExportRefusedException(ExportRefusedException::NOTHING_TO_ZIP);
            }
        }
    }

    /**
     * The ids of the photo fields of the inventory.
     *
     * @param  Collection<int, InventoryField>  $fields
     * @return list<int>
     */
    private function photoFieldIds(Collection $fields): array
    {
        $ids = [];

        foreach ($fields as $field) {
            $type = $this->types->of($field);

            if ($type instanceof UploadedFilesType && $type->imagesOnly()) {
                $ids[] = (int) $field->getKey();
            }
        }

        return $ids;
    }
}
