<?php

declare(strict_types=1);

namespace App\Database;

use App\Auth\EmailAddress;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes email columns compare exactly, on the engines whose default does not.
 *
 * PostgreSQL compares strings byte for byte already. MySQL and MariaDB compare
 * them with the collation of the column, and the usual defaults (`utf8mb4_0900_ai_ci`,
 * `utf8mb4_unicode_ci`, `utf8mb4_uca1400_ai_ci`) ignore case *and accents*:
 * `john@gmäil.com` equals `john@gmail.com`, in a lookup as in a unique index.
 * A binary collation closes that door at the database, whatever the code does;
 * addresses are stored in a single canonical form anyway (see {@see EmailAddress}),
 * so nothing else relies on the case-insensitivity.
 *
 * This is the only place that knows the syntax: the engine is picked here and
 * nowhere else.
 */
final class ExactEmailColumns
{
    /** The binary collation of the utf8mb4 character set. */
    public const COLLATION = 'utf8mb4_bin';

    /**
     * Gives the column a binary collation. Does nothing on PostgreSQL.
     *
     * @param  string  $table  Table with an `email` column: an address up to 255 characters, not null.
     */
    public static function apply(string $table): void
    {
        if (! self::needsCollation()) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('email')->collation(self::COLLATION)->change();
        });
    }

    /**
     * Gives the column back the default collation of the connection. Does nothing on PostgreSQL.
     *
     * @param  string  $table  Table with an `email` column: an address up to 255 characters, not null.
     */
    public static function revert(string $table): void
    {
        if (! self::needsCollation()) {
            return;
        }

        $default = Schema::getConnection()->getConfig('collation');

        Schema::table($table, function (Blueprint $blueprint) use ($default): void {
            $blueprint->string('email')->collation(is_string($default) ? $default : 'utf8mb4_unicode_ci')->change();
        });
    }

    /**
     * Whether the connection is one of the engines that need the collation.
     */
    private static function needsCollation(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
