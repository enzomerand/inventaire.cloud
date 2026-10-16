<?php

declare(strict_types=1);

namespace App\Inventories\Exports;

use App\Database\DeadlockRetries;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Exceptions\ExportLimitReachedException;
use App\Inventories\Exceptions\ExportRefusedException;
use App\Inventories\Exceptions\ExportStorageFullException;
use App\Inventories\Fields\FieldTypes;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\User;
use App\Workspaces\Locks;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

/**
 * Asks for an export: what the API does apart from the HTTP.
 *
 * An export is **asked for** after three checks, in this order. The view it is made on must hold for the fields the inventory has
 * now (the mistakes are those of the list of items, under `query.`: a `ValidationException`). A PDF must not hold more items than the
 * library can lay out in memory, and a zip must have a file to hold ({@see ExportRefusedException}). Two quotas are then counted
 * under the lock of the row of the person, so that two requests at the same moment cannot cross them together: the number of
 * exports the person has **of this inventory**, not expired ones whatever their state ({@see ExportLimitReachedException}), and the
 * room their exports take **in all**, across their inventories ({@see ExportStorageFullException}). The room is the sum of the
 * `size` of the exports that did not expire; an export still waiting or being written has none yet, except a zip, which is given
 * the sum of the sizes of the files it will hold as soon as it is asked for (the real size replaces it when the file is written,
 * and a failed export gives it back), so that a few zips of a big inventory asked for in a row cannot together fill the disk before
 * any of them has run. The row is created `pending` and the job that writes the file is queued once the transaction is committed,
 * so that no worker looks for a row that is not there yet.
 *
 * The checks of the first kind are made on what the inventory holds *now*; the job lists the items again when it runs. An inventory
 * that changes in between changes the file, not the checks: the PDF refuses at write time what it was allowed at request time
 * ({@see ExportError::PdfTooLarge}).
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
     * @throws ExportLimitReachedException When the person has as many exports of this inventory as they may.
     * @throws ExportStorageFullException When their exports, and this one, would take more room than they may.
     */
    public function request(Inventory $inventory, User $user, ExportFormat $format, ExportScope $scope, array $options = []): Export
    {
        $estimate = $this->check($inventory, $format, $scope, $options);

        $limit = config()->integer('inventories.max_active_exports');
        $room = config()->integer('inventories.export_storage_per_user');

        return DeadlockRetries::run(fn (): Export => $this->db->transaction(function () use ($inventory, $user, $format, $scope, $options, $limit, $room, $estimate): Export {
            // The row of the person serialises their requests: the counts below cannot be crossed by two at once.
            $this->locks->users([$user->getKey()]);

            $mine = Export::query()->where('user_id', $user->getKey())->active();

            if ((clone $mine)->where('inventory_id', $inventory->getKey())->count() >= $limit) {
                throw new ExportLimitReachedException($limit);
            }

            if ((int) (clone $mine)->sum('size') + $estimate > $room) {
                throw new ExportStorageFullException($room);
            }

            $export = new Export;
            $export->forceFill([
                'inventory_id' => $inventory->getKey(),
                'user_id' => $user->getKey(),
                'format' => $format,
                'scope' => $scope,
                'options' => $options,
                // The weight of a zip is known before it is written: it counts against the room of the person from now on.
                'size' => $estimate > 0 ? $estimate : null,
                'locale' => mb_substr(app()->getLocale(), 0, 12),
                'status' => ExportStatus::Pending,
                'disk' => $this->storage->diskName(),
            ])->save();

            GenerateExport::dispatch($export)->afterCommit();

            return $export;
        }));
    }

    /**
     * The checks that depend on what the inventory holds: the view, the size of a PDF, the files of a zip.
     *
     * @param  array<string, mixed>  $options
     * @return int The bytes the file is expected to weigh when it is known beforehand: the files of a zip, stored as they are. 0 for the other formats.
     *
     * @throws ValidationException
     * @throws ExportRefusedException
     */
    private function check(Inventory $inventory, ExportFormat $format, ExportScope $scope, array $options): int
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
                $files->whereIn('field_id', $this->types->photoFieldIds($fields));
            }

            if ($scope === ExportScope::View) {
                $files->whereIn('item_id', $this->items->subQuery($matching));
            }

            $found = $files->selectRaw('count(*) as files, coalesce(sum(size), 0) as bytes')->first();

            if ($found === null || (int) $found->files === 0) {
                throw new ExportRefusedException(ExportRefusedException::NOTHING_TO_ZIP);
            }

            return (int) $found->bytes;
        }

        return 0;
    }
}
