<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\InventoryVersion;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Workspaces\Invitations;
use App\Workspaces\Membership;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Everything the application holds about an account, in the form the person can take away (GDPR, right of access
 * and portability).
 *
 * The document is versioned (`format`, `version`): lots that add data to an account add their part to it and leave
 * what is there alone.
 *
 * Each workspace the person belongs to carries its `inventories`: for each one its identifier (ULID), name,
 * description, dates and **fields** (identifier, name, type, options, whether it is required, position) and the
 * **number** of its items (`items_count`). The items themselves are **not** in this document: an inventory may hold
 * thousands of them (10000 by default, in each of up to 100 inventories of each workspace), which is not a size for a
 * document built in memory and sent in one answer. Their content comes with the export of the inventories (lot 6), which
 * is streamed one inventory at a time; until then the document says how many there are and nothing more about them. The
 * inventories are those of every workspace the person belongs to, whatever their role in it: they see them in the
 * application.
 *
 * What the person wrote in a workspace is counted there, since the history of an item and its files are data about them
 * (right of access) that the items' export does not carry: `revisions_written` is the number of lines of the history of
 * items that they authored (the oldest ones of an item are deleted when it has more than the history keeps), and
 * `files_uploaded` the `count` and the total size in `bytes` of the files they uploaded and that are still attached, and
 * `official_versions_created` the number of official versions they froze that still exist (see {@see InventoryVersion}). All
 * are zero in a workspace where they wrote nothing. They are an addition to the document, which keeps its version.
 *
 * `user.ulid` is the identifier by which the other members of a workspace know the account in the API (`id` there). An addition to
 * the document, which keeps its version.
 *
 * `invitations` are the invitations to join a workspace that still wait for an answer: the ones the account **sent** (the workspace, the
 * address invited, the role, when it was sent and when it lapses) and the ones **received** (the name of the workspace, the name of who
 * invited, the role, when it lapses). The ones that lapsed are not there: the application treats them as gone. The ones sent are only
 * those of the workspaces the account still owns: the address of a person who was invited is shown to the owner who manages the
 * invitations and to nobody else, and an account that handed a workspace over (or was removed from it) no longer sees them in the
 * application, so its export does not give them either. An addition to the document, which keeps its version.
 *
 * `share_links` are the share links the account made, from the inventories of the workspaces it still owns (those it can still see in the
 * application, as for the invitations): the inventory (`id` and `name`), the label, whether the files are shown, and when the link was made, expires
 * and was last opened, the ones that expired and were not deleted yet included. Never the token, nor the hash and the ciphertext that
 * the database keeps of it: the address is a secret, and the account's export can end up anywhere. An addition to the document, which keeps its
 * version.
 *
 * Besides the profile and the workspaces it holds what the application keeps about the account's access: the
 * sessions it has in the database (network address, browser and last activity, never the session id, which is a
 * credential) and the metadata of its API tokens (name, abilities, dates, never the token or its hash).
 */
final class AccountExport
{
    /** Name of the format, so a file found alone says what it is. */
    public const FORMAT = 'inventaire.cloud/account-export';

    /** Version of the structure of the document. */
    public const VERSION = 1;

    public function __construct(private readonly Workspaces $workspaces, private readonly Invitations $invitations) {}

    /**
     * The export of an account.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'ulid' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'is_admin' => $user->is_admin,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'workspaces' => $this->workspaces($user),
            'invitations' => $this->invitations($user),
            'share_links' => $this->shareLinks($user),
            'sessions' => $this->sessions($user),
            'tokens' => $this->tokens($user),
        ];
    }

    /**
     * The workspaces of the account, oldest membership first, each with its inventories.
     *
     * @return list<array<string, mixed>>
     */
    private function workspaces(User $user): array
    {
        $memberships = $this->workspaces->memberships($user);

        $workspaceIds = array_map(fn (Membership $membership): int => $membership->workspace->getKey(), $memberships);

        // Two queries for all the workspaces (the inventories, which carry the number of their items, then their fields), not
        // two per workspace; and one more for each of the things the person wrote.
        $inventories = Inventory::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->with('fields')
            ->orderBy('id')
            ->get()
            ->groupBy('workspace_id');
        $revisions = $this->revisionsWritten($user, $workspaceIds);
        $files = $this->filesUploaded($user, $workspaceIds);
        $versions = $this->versionsCreated($user, $workspaceIds);

        return array_map(fn (Membership $membership): array => [
            'id' => $membership->workspace->ulid,
            'name' => $membership->workspace->name,
            'role' => $membership->role->value,
            'is_current' => $membership->current,
            'created_at' => $membership->workspace->created_at?->toIso8601String(),
            'updated_at' => $membership->workspace->updated_at?->toIso8601String(),
            'revisions_written' => $revisions[$membership->workspace->getKey()] ?? 0,
            'files_uploaded' => $files[$membership->workspace->getKey()] ?? ['count' => 0, 'bytes' => 0],
            'official_versions_created' => $versions[$membership->workspace->getKey()] ?? 0,
            'inventories' => $this->inventories($inventories->get($membership->workspace->getKey(), new Collection)),
        ], $memberships);
    }

    /**
     * The invitations to join a workspace that wait for an answer, sent by the account (from the workspaces it still owns) and addressed
     * to it, newest first.
     *
     * @return array{sent: list<array<string, mixed>>, received: list<array<string, mixed>>}
     */
    private function invitations(User $user): array
    {
        $owned = WorkspaceMember::query()->where('user_id', $user->getKey())->where('role', WorkspaceRole::Owner->value)->select('workspace_id');
        $sent = WorkspaceInvitation::query()->where('invited_by_id', $user->getKey())->whereIn('workspace_id', $owned)->pending()->with('workspace')->orderByDesc('id')->get();

        return [
            'sent' => array_values($sent->map(fn (WorkspaceInvitation $invitation): array => [
                'workspace' => ['id' => $invitation->workspace->ulid, 'name' => $invitation->workspace->name],
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'created_at' => $invitation->created_at?->toIso8601String(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ])->all()),
            'received' => array_values($this->invitations->pendingFor($user)->map(fn (WorkspaceInvitation $invitation): array => [
                'workspace' => $invitation->workspace->name,
                'invited_by' => $invitation->inviter?->name,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ])->all()),
        ];
    }

    /**
     * The share links the account made, from the inventories of the workspaces it still owns, newest first. Never the token.
     *
     * @return list<array<string, mixed>>
     */
    private function shareLinks(User $user): array
    {
        $owned = WorkspaceMember::query()->where('user_id', $user->getKey())->where('role', WorkspaceRole::Owner->value)->select('workspace_id');
        $inventories = Inventory::query()->whereIn('workspace_id', $owned)->select('id');

        return array_values(ShareLink::query()
            ->where('created_by_id', $user->getKey())
            ->whereIn('inventory_id', $inventories)
            ->with('inventory')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShareLink $link): array => [
                'inventory' => ['id' => $link->inventory->ulid, 'name' => $link->inventory->name],
                'label' => $link->label,
                'include_files' => $link->include_files,
                'created_at' => $link->created_at?->toIso8601String(),
                'expires_at' => $link->expires_at?->toIso8601String(),
                'last_used_at' => $link->last_used_at?->toIso8601String(),
            ])->all());
    }

    /**
     * How many lines of the history of items the person wrote, by workspace (one query).
     *
     * @param  list<int>  $workspaceIds
     * @return array<int, int> Only the workspaces where they wrote some.
     */
    private function revisionsWritten(User $user, array $workspaceIds): array
    {
        return $this->countedByWorkspace('item_revisions', $user, $workspaceIds);
    }

    /**
     * How many files the person uploaded and what they weigh, by workspace (one query).
     *
     * @param  list<int>  $workspaceIds
     * @return array<int, array{count: int, bytes: int}> Only the workspaces where they uploaded some.
     */
    private function filesUploaded(User $user, array $workspaceIds): array
    {
        $totals = [];

        $rows = DB::table('attachments')
            ->join('inventories', 'inventories.id', '=', 'attachments.inventory_id')
            ->where('attachments.uploaded_by', $user->getKey())
            ->whereIn('inventories.workspace_id', $workspaceIds)
            ->groupBy('inventories.workspace_id')
            ->selectRaw('inventories.workspace_id as workspace_id, count(*) as total, sum(attachments.size) as bytes')
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->workspace_id] = ['count' => (int) $row->total, 'bytes' => (int) $row->bytes];
        }

        return $totals;
    }

    /**
     * How many official versions the person froze, by workspace (one query).
     *
     * @param  list<int>  $workspaceIds
     * @return array<int, int> Only the workspaces where they froze some.
     */
    private function versionsCreated(User $user, array $workspaceIds): array
    {
        return $this->countedByWorkspace('inventory_versions', $user, $workspaceIds);
    }

    /**
     * How many rows of a table that hangs from an inventory (`inventory_id`) and names its author (`user_id`) a person is the author of,
     * by workspace (one query).
     *
     * @param  string  $table  The table, one of the application's own.
     * @param  list<int>  $workspaceIds
     * @return array<int, int> Only the workspaces where they are the author of some.
     */
    private function countedByWorkspace(string $table, User $user, array $workspaceIds): array
    {
        $counts = [];

        $rows = DB::table($table)
            ->join('inventories', 'inventories.id', '=', $table.'.inventory_id')
            ->where($table.'.user_id', $user->getKey())
            ->whereIn('inventories.workspace_id', $workspaceIds)
            ->groupBy('inventories.workspace_id')
            ->selectRaw('inventories.workspace_id as workspace_id, count(*) as total')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->workspace_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * The inventories of a workspace, oldest first: what describes them and their fields, and how many items they hold.
     *
     * @param  Collection<int, Inventory>  $inventories  With their fields loaded and their items counted.
     * @return list<array<string, mixed>>
     */
    private function inventories(Collection $inventories): array
    {
        return array_values($inventories->map(fn (Inventory $inventory): array => [
            'id' => $inventory->ulid,
            'name' => $inventory->name,
            'description' => $inventory->description,
            'created_at' => $inventory->created_at?->toIso8601String(),
            'updated_at' => $inventory->updated_at?->toIso8601String(),
            'fields' => $inventory->fields->map(fn (InventoryField $field): array => [
                'id' => $field->ulid,
                'name' => $field->name,
                'type' => $field->type,
                // An object, also when the type has no options: the same shape for every field.
                'options' => $field->options === null || $field->options === [] ? new stdClass : $field->options,
                'required' => $field->required,
                'position' => $field->position,
            ])->all(),
            'items_count' => $inventory->items_count,
        ])->all());
    }

    /**
     * The sessions the account has in the database, most recent first. Empty when sessions are kept anywhere else
     * (files, cache): there is nothing the application could list then.
     *
     * @return list<array{ip_address: string|null, user_agent: string|null, last_activity: string}>
     */
    private function sessions(User $user): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $connection = config('session.connection');
        $rows = DB::connection(is_string($connection) ? $connection : null)
            ->table(config()->string('session.table'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['ip_address', 'user_agent', 'last_activity']);

        $sessions = [];

        foreach ($rows as $row) {
            $sessions[] = [
                'ip_address' => is_string($row->ip_address) ? $row->ip_address : null,
                'user_agent' => is_string($row->user_agent) ? $row->user_agent : null,
                'last_activity' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
            ];
        }

        return $sessions;
    }

    /**
     * The API tokens of the account, oldest first: what they are called, what they may do and when they were made
     * and last used. Never the token, nor the hash the database keeps of it.
     *
     * @return list<array{name: string, abilities: list<string>, last_used_at: string|null, created_at: string|null}>
     */
    private function tokens(User $user): array
    {
        $tokens = [];

        foreach ($user->tokens()->orderBy('id')->get() as $token) {
            $tokens[] = [
                'name' => $token->name,
                'abilities' => array_values(array_map(strval(...), $token->abilities ?? [])),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ];
        }

        return $tokens;
    }
}
