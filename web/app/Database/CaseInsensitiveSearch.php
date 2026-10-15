<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Eloquent\Builder;

/**
 * Searches text columns for a word, ignoring case, the same way on PostgreSQL, MySQL and MariaDB.
 *
 * `LIKE` is case-insensitive on MySQL and MariaDB (with the usual collations) and case-sensitive on PostgreSQL, whose
 * answer is `ILIKE`. The query builder's `whereLike()` writes the right one for the engine, so this is the only place
 * that decides how the application searches text, and no engine-specific SQL is written anywhere.
 *
 * Two things are done here rather than left to the engine. `%` and `_` typed by the person are ordinary characters,
 * so they are escaped (with the backslash, the default escape character of `LIKE` on all three engines). And the term
 * is lowered: the email columns of MySQL and MariaDB have a binary collation, where `LIKE` tells capitals from small
 * letters (see {@see ExactEmailColumns}), and addresses are stored in lowercase.
 *
 * Letters the engine does not fold are not found by another case or accent: PostgreSQL folds the case of the letters
 * its database locale knows (a database created with the `C` locale only folds ASCII ones), and MySQL and MariaDB
 * fold accents in names (`e` finds `é`) where PostgreSQL does not. The searches an administrator makes (a part of a
 * name or of an address) do not depend on it.
 */
final class CaseInsensitiveSearch
{
    /**
     * Restricts the query to the rows where at least one of the columns contains the term.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns  Columns to look in, as the query names them.
     * @param  string  $term  What to look for, as typed: `%` and `_` in it are ordinary characters.
     */
    public static function apply(Builder $query, array $columns, string $term): void
    {
        // The backslash is the escape character of LIKE on all three engines unless a statement says otherwise.
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($term))).'%';

        $query->where(function (Builder $group) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $group->orWhereLike($column, $pattern);
            }
        });
    }
}
