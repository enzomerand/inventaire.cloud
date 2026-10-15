<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Workspaces\Workspaces;

/**
 * Everything the application holds about an account, in the form the person can take away (GDPR, right of access
 * and portability).
 *
 * The document is versioned (`format`, `version`): lots that add data to an account add their part to it and leave
 * what is there alone. Each workspace already carries an `inventories` list for the inventories of lot 4, empty
 * until they exist, so that the structure does not change when they arrive.
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
        ];
    }
}
