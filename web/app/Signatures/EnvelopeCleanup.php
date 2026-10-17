<?php

declare(strict_types=1);

namespace App\Signatures;

use App\Inventories\Versions\SignatureStatus;
use App\Jobs\CancelDocumensoEnvelopes;
use App\Models\Inventory;
use App\Models\Workspace;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * What becomes of the requests for signatures that are still open at Documenso when what holds them is deleted: a version, its
 * inventory or its workspace. Their signers have been emailed, and must not be left with a document to sign that nobody keeps; the
 * envelopes are cancelled at Documenso in the background ({@see CancelDocumensoEnvelopes}), once the deletion is committed.
 *
 * A request is open when it has not ended ({@see SignatureStatus::unfinished()}: `requested`, `sending`, `awaiting` or `failed`) and
 * Documenso gave it an envelope. A `failed` one is open as much as the others: the call that sent it may have been lost after Documenso
 * had emailed the signers, so its envelope may be pending there; and one that is `requested` again after a failure keeps the envelope
 * of the first try. Over-collecting costs nothing: Documenso only cancels an envelope that is pending, so a draft or an envelope that
 * is over is refused and left alone. The ids are read before the deletion removes the rows (the database
 * cascades those of an inventory and of a workspace without firing an event), in the `deleting` event of the model, like the folders
 * to purge ({@see AttachmentCleanup}, which calls this class), and the jobs are queued only in its `deleted` event: a deletion that
 * fails cancels nothing. The ids go to the jobs {@see self::perJob()} at a time: a job runs for three minutes at the longest, whatever
 * the instance is doing.
 */
final class EnvelopeCleanup
{
    /** The envelopes one job takes care of: each is one call, of 20 seconds at the longest. */
    public const PER_JOB = 10;

    /** The envelopes one job takes care of when it also deletes drafts: each is two calls (it is read, then deleted or cancelled). */
    public const PER_JOB_DELETING_DRAFTS = 5;

    /**
     * The envelopes to cancel for each inventory and workspace that is being deleted, between its `deleting` and its `deleted` event.
     * Dropped with the model if the deletion fails.
     *
     * @var WeakMap<Model, list<string>>|null
     */
    private static ?WeakMap $collected = null;

    /**
     * Gets the database.
     */
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * How many envelopes one job takes care of, so that its calls, which wait 20 seconds each at the longest, end within its timeout.
     *
     * @param  bool  $deleteDrafts  Whether the job reads each envelope first, to delete it when it is a draft: two calls instead of one.
     * @return int<1, max>
     */
    public static function perJob(bool $deleteDrafts): int
    {
        return $deleteDrafts ? self::PER_JOB_DELETING_DRAFTS : self::PER_JOB;
    }

    /**
     * Remembers the open envelopes of the versions of an inventory that is being deleted.
     */
    public function collectForInventory(Inventory $inventory): void
    {
        $this->remember($inventory, $this->open()->where('inventory_versions.inventory_id', $inventory->getKey()));
    }

    /**
     * Remembers the open envelopes of the versions of the inventories of a workspace that is being deleted.
     */
    public function collectForWorkspace(Workspace $workspace): void
    {
        $this->remember($workspace, $this->open()
            ->join('inventories', 'inventories.id', '=', 'inventory_versions.inventory_id')
            ->where('inventories.workspace_id', $workspace->getKey()));
    }

    /**
     * Queues the cancellation of what was remembered for a model that has just been deleted, once the transaction that deletes it is
     * committed (at once when there is none).
     */
    public function dispatchCollected(Model $model): void
    {
        $collected = self::collected();
        $ids = $collected[$model] ?? [];
        unset($collected[$model]);

        $this->cancelAfterCommit($ids);
    }

    /**
     * Queues the cancellation of envelopes once the transaction in progress is committed (at once when there is none): one that is
     * rolled back, or retried after a deadlock, cancels nothing. A queue that cannot be reached is written in the log: the signers
     * keep a document to sign, and an operator can cancel it in Documenso. The envelopes go to the jobs {@see self::perJob()} at a time.
     *
     * @param  list<string>  $envelopeIds
     * @param  bool  $deleteDrafts  Whether an envelope that is still a draft is deleted at Documenso (it cannot be cancelled, and
     *                              nobody was emailed): for the requests the schedule gave up on.
     */
    public function cancelAfterCommit(array $envelopeIds, bool $deleteDrafts = false): void
    {
        $envelopeIds = array_values(array_unique($envelopeIds));

        if ($envelopeIds === []) {
            return;
        }

        $this->db->connection()->afterCommit(function () use ($envelopeIds, $deleteDrafts): void {
            foreach (array_chunk($envelopeIds, self::perJob($deleteDrafts)) as $chunk) {
                try {
                    CancelDocumensoEnvelopes::dispatch($chunk, null, $deleteDrafts);
                } catch (Throwable $exception) {
                    Log::error('The cancellation of signature requests could not be queued: cancel them in Documenso by hand.', ['envelopes' => $chunk, 'exception' => $exception]);
                }
            }
        });
    }

    /**
     * The versions with a request for signatures open at Documenso.
     */
    private function open(): Builder
    {
        return $this->db->table('inventory_versions')
            ->whereIn('inventory_versions.signature_status', SignatureStatus::unfinishedValues())
            ->whereNotNull('inventory_versions.documenso_envelope_id');
    }

    /**
     * Reads the envelope ids of a query and remembers them for a model.
     */
    private function remember(Model $model, Builder $query): void
    {
        $ids = array_values(array_map(strval(...), $query->pluck('inventory_versions.documenso_envelope_id')->all()));

        $collected = self::collected();

        if ($ids === []) {
            unset($collected[$model]);

            return;
        }

        $collected[$model] = $ids;
    }

    /**
     * What was collected, by model.
     *
     * @return WeakMap<Model, list<string>>
     */
    private static function collected(): WeakMap
    {
        return self::$collected ??= new WeakMap;
    }
}
