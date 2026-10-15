<?php

declare(strict_types=1);

namespace App\Workspaces;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Reads and writes the `role` column of a membership as a {@see WorkspaceRole}, without ever failing on what the
 * database holds.
 *
 * The column is a free string (a role can be added without a migration), so a row may name a role this version does
 * not know: written by a newer version after a rollback, edited by hand, or left by a role that was removed. The
 * framework's own enum cast would throw on it and take every page that lists workspaces down with it (the header
 * switcher is on all of them). An unknown role is read as the one with the fewest rights, {@see WorkspaceRole::Viewer},
 * and reported once per value and process, since the same row is read many times.
 *
 * @implements CastsAttributes<WorkspaceRole, WorkspaceRole|string>
 */
final class StoredRole implements CastsAttributes
{
    /** @var array<string, true> The unknown values already reported by this process. */
    private static array $reported = [];

    /**
     * The role of a stored value: unknown (or missing) means the least privilege.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): WorkspaceRole
    {
        $stored = is_string($value) ? $value : '';
        $role = WorkspaceRole::tryFrom($stored);

        if ($role !== null) {
            return $role;
        }

        if (! isset(self::$reported[$stored])) {
            self::$reported[$stored] = true;
            Log::warning('A workspace membership holds a role this version does not know: it is read as a viewer.', ['role' => $stored]);
        }

        return WorkspaceRole::Viewer;
    }

    /**
     * The value to store for a role (a plain string is kept as it is, as attaching with a word does).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value instanceof WorkspaceRole ? $value->value : (string) $value;
    }

    /**
     * Forgets which unknown values were reported, so that they are reported again (for the tests).
     */
    public static function forgetReported(): void
    {
        self::$reported = [];
    }
}
