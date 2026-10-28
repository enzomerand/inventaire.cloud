<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\BillingAccount;

/**
 * What {@see WorkspacePlan} has already read from the database in this request or job, by workspace: a listing, an import or a page that
 * asks a dozen questions about the same workspace costs one query, not a dozen.
 *
 * The container makes one per request and one per queued job (it is registered `scoped`, like `InstanceSettings`): a worker that lives for
 * days never answers from what it read for an earlier job. Inside a process the memory is forgotten as soon as the subscription of a workspace
 * is written, whoever writes it: the models fire the events that {@see BillingAccount} forwards here, and the synchronisation
 * ({@see BillingSync}) forgets again once its transaction is over. What is written by another process meanwhile is seen by the next request or
 * job; a request lasts for a fraction of a second, and the plan of a workspace changes a few times a year.
 */
final class PlanMemory
{
    /**
     * The plans read so far, by the id of the workspace.
     *
     * @var array<int, Plan>
     */
    private array $plans = [];

    /**
     * The plan read for a workspace, if it was read.
     */
    public function recall(int $workspaceId): ?Plan
    {
        return $this->plans[$workspaceId] ?? null;
    }

    /**
     * Keeps the plan read for a workspace.
     */
    public function remember(int $workspaceId, Plan $plan): void
    {
        $this->plans[$workspaceId] = $plan;
    }

    /**
     * Forgets everything that was read: the next question about any workspace reads the database again. (Everything and not one
     * workspace, because the answer is cheap to read again and a subscription that moved between workspaces is not worth reasoning about.)
     */
    public function forget(): void
    {
        $this->plans = [];
    }
}
