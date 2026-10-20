<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Symfony\Component\Uid\Ulid;

/**
 * The identifier the API gives a member of a workspace, in place of the number of their account.
 *
 * The number of an account never leaves the application but to its own owner (`GET /me`): it would tell every member how many
 * accounts the instance holds, and which ones were opened around the same time. A member is named instead by a ULID-shaped string
 * that only this class can compute: the keyed hash (HMAC-SHA-256, with the application key) of the workspace and the account,
 * cut to 128 bits. Nothing is stored, so there is nothing to migrate, and the same member always has the same identifier in a
 * given workspace. It is a different one in each workspace, so that two workspaces cannot be matched by it. It lasts as long as
 * the application key does: the key that signs the sessions and encrypts the cookies is not rotated lightly.
 *
 * An identifier is only ever looked up among the members of one workspace ({@see self::find()}), never across the table of the
 * accounts, so that a guess says nothing about accounts that do not belong to it.
 */
final class MemberIds
{
    /**
     * The identifier of an account in a workspace, in lowercase. It does not say whether the account belongs to the workspace.
     */
    public function of(Workspace $workspace, User|int $user): string
    {
        $account = $user instanceof User ? (int) $user->getKey() : $user;
        $digest = hash_hmac('sha256', "workspace-member:{$workspace->getKey()}:{$account}", config()->string('app.key'), true);

        return strtolower(Ulid::fromBinary(substr($digest, 0, 16))->toBase32());
    }

    /**
     * The account a member identifier stands for, among the members of the workspace; null when it stands for none of them (the
     * identifier is made up, belongs to another workspace, or its member left).
     */
    public function find(Workspace $workspace, string $id): ?User
    {
        $id = strtolower($id);

        if (preg_match('/^[0-9a-z]{26}$/', $id) !== 1) {
            return null;
        }

        foreach (WorkspaceMember::query()->where('workspace_id', $workspace->getKey())->pluck('user_id') as $account) {
            if (hash_equals($this->of($workspace, (int) $account), $id)) {
                return User::query()->whereKey((int) $account)->first();
            }
        }

        return null;
    }
}
