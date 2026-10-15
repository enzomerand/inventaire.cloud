<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\User;
use App\Workspaces\Membership;
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

    public function __construct(private readonly Workspaces $workspaces) {}

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
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'is_admin' => $user->is_admin,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'workspaces' => $this->workspaces($user),
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

        // Two queries for all the workspaces (the inventories with the number of their items, then their fields), not
        // two per workspace.
        $inventories = Inventory::query()
            ->whereIn('workspace_id', array_map(fn (Membership $membership): int => $membership->workspace->getKey(), $memberships))
            ->withCount('items')
            ->with('fields')
            ->orderBy('id')
            ->get()
            ->groupBy('workspace_id');

        return array_map(fn (Membership $membership): array => [
            'id' => $membership->workspace->ulid,
            'name' => $membership->workspace->name,
            'role' => $membership->role->value,
            'is_current' => $membership->current,
            'created_at' => $membership->workspace->created_at?->toIso8601String(),
            'updated_at' => $membership->workspace->updated_at?->toIso8601String(),
            'inventories' => $this->inventories($inventories->get($membership->workspace->getKey(), new Collection)),
        ], $memberships);
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
            'items_count' => (int) $inventory->getAttribute('items_count'),
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
