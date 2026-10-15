<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything the application holds about an account, in the form the person can take away (GDPR, right of access
 * and portability).
 *
 * The document is versioned (`format`, `version`): lots that add data to an account add their part to it and leave
 * what is there alone. Each workspace already carries an `inventories` list for the inventories of lot 4, empty
 * until they exist, so that the structure does not change when they arrive.
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
            'workspaces' => array_map(fn ($membership): array => [
                'id' => $membership->workspace->ulid,
                'name' => $membership->workspace->name,
                'role' => $membership->role->value,
                'is_current' => $membership->current,
                'created_at' => $membership->workspace->created_at?->toIso8601String(),
                'updated_at' => $membership->workspace->updated_at?->toIso8601String(),
                'inventories' => [],
            ], $this->workspaces->memberships($user)),
            'sessions' => $this->sessions($user),
            'tokens' => $this->tokens($user),
        ];
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
