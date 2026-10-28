<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Workspace;

/**
 * The single place every per-workspace quota is read from: what the workspace may hold and do.
 *
 * With billing off ({@see Billing::enabled()}), each answer is exactly what the call site read before billing existed, the setting of
 * the instance (`inventories.*`, `workspaces.max_members`), so a self-hosted instance behaves as it always did. With billing on, it is
 * the limit of the plan the workspace is on ({@see WorkspacePlan}). The per-user limits (exports, imports, tokens, photo analyses per
 * day) are not here: they stay global.
 */
final class WorkspaceLimits
{
    /**
     * How many inventories the workspace may have.
     */
    public static function inventories(Workspace $workspace): int
    {
        return Billing::enabled()
            ? WorkspacePlan::for($workspace)->limit('inventories')
            : config()->integer('inventories.max_per_workspace');
    }

    /**
     * How many items one inventory of the workspace may hold.
     */
    public static function itemsPerInventory(Workspace $workspace): int
    {
        return Billing::enabled()
            ? WorkspacePlan::for($workspace)->limit('items')
            : config()->integer('inventories.max_items');
    }

    /**
     * How many bytes the files of the workspace may weigh together.
     */
    public static function storageBytes(Workspace $workspace): int
    {
        return Billing::enabled()
            ? WorkspacePlan::for($workspace)->limit('storage_bytes')
            : config()->integer('inventories.storage_per_workspace');
    }

    /**
     * How many places the workspace has for its members and the invitations that are waiting, together.
     */
    public static function members(Workspace $workspace): int
    {
        return Billing::enabled()
            ? WorkspacePlan::for($workspace)->limit('members')
            : config()->integer('workspaces.max_members');
    }

    /**
     * How many share links that still work one inventory of the workspace may have.
     */
    public static function shareLinksPerInventory(Workspace $workspace): int
    {
        return Billing::enabled()
            ? WorkspacePlan::for($workspace)->limit('share_links')
            : config()->integer('inventories.max_share_links');
    }

    /**
     * Whether the plan of the workspace allows the photo entry. Without billing the plan takes nothing away: the administrator's switch,
     * the API key and the owner's consent (see `App\PhotoEntry\PhotoEntry`) decide alone.
     */
    public static function photoEntry(Workspace $workspace): bool
    {
        return ! Billing::enabled() || WorkspacePlan::for($workspace)->allows('photo_entry');
    }
}
