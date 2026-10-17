<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventories\StartImportRequest;
use App\Http\Requests\Inventories\StoreImportRequest;
use App\Http\Requests\Inventories\UpdateImportRequest;
use App\Http\Resources\ImportResource;
use App\Http\Responses\ApiError;
use App\Inventories\Exceptions\FieldLimitReachedException;
use App\Inventories\Exceptions\ImportFileException;
use App\Inventories\Exceptions\ImportLimitReachedException;
use App\Inventories\Exceptions\ImportNotEditableException;
use App\Inventories\Exceptions\InvalidImportMapping;
use App\Inventories\Imports\ImportCleanup;
use App\Inventories\Imports\Imports;
use App\Inventories\Imports\ImportStatus;
use App\Models\Import;
use App\Models\Inventory;
use App\Policies\InventoryPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The imports of an inventory: send a file (`201`: it is read and its columns are proposed a destination), list the ones a person
 * has, read one, read the file again with other options, start it (`202`: a job adds the rows), cancel it.
 *
 * Changing the items is what an import does, so the roles that may are the ones that may (editors and owners: see
 * {@see InventoryPolicy}; a viewer is refused, a stranger told the inventory is not there), and an import is its author's alone: another
 * person's import, even in the same workspace, is a `404` like one that does not exist. The report is kept for
 * `inventories.import_ttl_hours` after the import ends, as is a file that was sent and never started.
 */
final class ImportController extends Controller
{
    /** Imports the list gives. */
    private const LIST_LIMIT = 20;

    /**
     * Gets the service that receives, reads and starts the imports.
     */
    public function __construct(private readonly Imports $imports) {}

    /**
     * The imports of the signed-in person for this inventory whose time is not up, the newest first, twenty at most.
     */
    public function index(Request $request, Inventory $inventory): AnonymousResourceCollection
    {
        Gate::authorize('editItems', $inventory);

        $imports = $inventory->imports()
            ->where('user_id', $this->user($request)->getKey())
            ->unexpired()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return ImportResource::collection($imports);
    }

    /**
     * Sends a file (`201`, with the import `uploaded`: the columns, a type guessed for each and a proposal of where each goes).
     *
     * `422` for a file that is not one the import reads (`file`, with a `code`: `unrecognized_format`, `unreadable_file`, `empty_file`,
     * `unpacked_too_large`, `too_many_columns`, `too_many_cells`, `too_many_lines`) and for a mistake in the request; `409` when the person already has as many unfinished
     * imports of this inventory as they may (`import_limit_reached`, with the `limit`); `429` beyond the pace of files.
     */
    public function store(StoreImportRequest $request, Inventory $inventory): JsonResponse
    {
        try {
            $import = $this->imports->upload($inventory, $this->user($request), $request->uploaded(), $request->reading(), Gate::allows('manageFields', $inventory));
        } catch (ImportLimitReachedException $limit) {
            return ApiError::conflict(
                __('You already have too many imports under way for this inventory. Cancel one or wait for it to end, then try again.'),
                ImportLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        } catch (ImportFileException $failure) {
            return ApiError::invalid($failure->getMessage(), 'file', $failure->reason);
        }

        return (new ImportResource($import))->response()->setStatusCode(201);
    }

    /**
     * One import of the signed-in person.
     */
    public function show(Request $request, Inventory $inventory, Import $import): ImportResource
    {
        return new ImportResource($this->owned($request, $inventory, $import));
    }

    /**
     * Reads the file again with other options (`has_header`, `delimiter`, `encoding`): the columns and the proposal come back made anew.
     * `409` (`import_not_editable`) once it was started, `404` (`import_expired`) once its time is up, `422` on `file` when the
     * file cannot be read that way, `429` beyond the pace of files.
     */
    public function update(UpdateImportRequest $request, Inventory $inventory, Import $import): ImportResource|JsonResponse
    {
        $import = $this->owned($request, $inventory, $import);

        try {
            return new ImportResource($this->imports->reanalyze($import, $inventory, $request->changes(), Gate::allows('manageFields', $inventory)));
        } catch (ImportNotEditableException $failure) {
            return $this->notEditable($failure);
        } catch (ImportFileException $failure) {
            return ApiError::invalid($failure->getMessage(), 'file', $failure->reason);
        }
    }

    /**
     * Starts the import (`202`, with the import `pending`: a job adds the rows).
     *
     * `422` for a mistake in the request and for a mapping that cannot be started (on `mapping`, with a `code`: `unknown_column`,
     * `duplicate_column`, `unknown_field`, `field_not_importable`, `duplicate_field`, `invalid_field_type`, `invalid_field_name`,
     * `duplicate_field_name`, `field_name_taken`, `cannot_manage_fields`, `required_field_unmapped`, `nothing_mapped`); `409` when the new
     * fields would take the inventory past the number of fields it may have (`field_limit_reached`, with the `limit`) and
     * when it was started already (`import_not_editable`); `404` once its time is up (`import_expired`).
     */
    public function start(StartImportRequest $request, Inventory $inventory, Import $import): JsonResponse
    {
        $import = $this->owned($request, $inventory, $import);

        try {
            $started = $this->imports->start($import, $inventory, $request->mapping(), $request->settings(), Gate::allows('manageFields', $inventory));
        } catch (ImportNotEditableException $failure) {
            return $this->notEditable($failure);
        } catch (InvalidImportMapping $invalid) {
            return ApiError::invalid($invalid->getMessage(), 'mapping', $invalid->reason, $invalid->extra);
        } catch (FieldLimitReachedException $limit) {
            return ApiError::conflict(
                trans_choice('An inventory cannot have more than :count field.|An inventory cannot have more than :count fields.', $limit->limit),
                FieldLimitReachedException::CODE,
                ['limit' => $limit->limit],
            );
        }

        return (new ImportResource($started))->response()->setStatusCode(202);
    }

    /**
     * Cancels an import or clears its report, and deletes its file (`204`). `409` (`import_running`) while the job is working on it.
     *
     * The row is deleted by one conditional statement that wants it not to be running, and not after a look at its status: the job may
     * claim it between the look and the deletion, and an import that is deleted while it writes would lose its report (the job takes
     * its own writes back when it finds the import gone, but the person would have been told it was cancelled). The file goes through
     * {@see ImportCleanup}, once the deletion is committed, as when the model is deleted.
     */
    public function destroy(Request $request, Inventory $inventory, Import $import, ImportCleanup $cleanup): Response|JsonResponse
    {
        $import = $this->owned($request, $inventory, $import);

        $deleted = Import::query()->whereKey($import->getKey())->where('status', '<>', ImportStatus::Running->value)->delete();

        if ($deleted === 1) {
            $cleanup->purge($import);
        } elseif (Import::query()->whereKey($import->getKey())->exists()) {
            return ApiError::conflict(__('This import is running: wait for it to end.'), 'import_running');
        }

        // An import that is gone by now (the pruner took it, another request deleted it) is what the person asked for.
        return response()->noContent();
    }

    /**
     * The import, if the signed-in person may read it: the person who sent it, who may still change the items of the inventory. Anything
     * else is a `404`, so that nobody learns which imports exist.
     */
    private function owned(Request $request, Inventory $inventory, Import $import): Import
    {
        Gate::authorize('editItems', $inventory);

        if ($import->user_id !== $this->user($request)->getKey()) {
            throw (new ModelNotFoundException)->setModel(Import::class, [$import->ulid]);
        }

        return $import;
    }

    /**
     * The answer for an import that cannot be changed or started any more.
     */
    private function notEditable(ImportNotEditableException $failure): JsonResponse
    {
        return $failure->reason === ImportNotEditableException::EXPIRED
            ? ApiError::notFound(__('This import is no longer available. Choose the file again.'), ImportNotEditableException::EXPIRED)
            : ApiError::conflict(__('This import has already started and can no longer be changed.'), ImportNotEditableException::CODE);
    }
}
