<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a column compare its values exactly, case included, on the engines whose default does not.
 *
 * The identifiers Stripe hands out (`cus_…`, `sub_…`, `price_…`) are case-sensitive: two customers can differ by the case of one letter.
 * PostgreSQL compares strings byte for byte already. MySQL and MariaDB use the collation of the column, and the usual defaults ignore
 * case (and accents), in a lookup as in a unique index. A migration that creates a column passes its definition through
 * {@see self::apply()}: the column is binary where it has to be and left alone where it need not be. Like {@see ExactEmailColumns}, which
 * does the same for the addresses of tables that exist, this is the only place that picks the engine.
 */
final class ExactColumns
{
    /** The binary collation of the utf8mb4 character set. */
    public const COLLATION = 'utf8mb4_bin';

    /**
     * Gives a column that is being created the binary collation on MySQL and MariaDB; leaves it as it is on the other engines.
     *
     * @return ColumnDefinition The same definition, to go on chaining (`->nullable()`, `->unique()`).
     */
    public static function apply(ColumnDefinition $column): ColumnDefinition
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? $column->collation(self::COLLATION) : $column;
    }
}
