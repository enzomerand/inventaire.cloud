<?php

declare(strict_types=1);

namespace App\Database;

use App\Rules\SingleLineText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Normalizer;

/**
 * Searches text columns for a word, ignoring case and accents, the same way on PostgreSQL, MySQL and MariaDB.
 *
 * `LIKE` is case-insensitive on MySQL and MariaDB (with the usual collations) and case-sensitive on PostgreSQL, which
 * has `ILIKE` for it. The accents are the other difference: the collation of a MySQL or MariaDB column (`utf8mb4_unicode_ci`)
 * makes `e` equal `é`, where PostgreSQL compares them as two letters and has no collation that does not fold them
 * (its `unaccent` is an extension, which an installation cannot be assumed to have). So the term is brought to a plain
 * form here, in PHP, and on PostgreSQL the column is brought to the same plain form by the SQL: `lower(translate(column,
 * ...)) like pattern`. This is the only place that decides how the application searches text, and no other engine-specific
 * SQL is written for it.
 *
 * The plain form is: the case lowered, the letters that Unicode builds from a base letter and a mark (Latin-1,
 * Latin Extended-A and B, and the Latin Extended Additional block, so `é`, `ç`, `ñ`, `ő`, `ș`, `ệ`...) written as the base
 * letter, and the combining marks (U+0300 to U+036F) of the term dropped, which is what a term typed in its decomposed form
 * holds (the text the application stores is composed, see {@see SingleLineText}). Both sides read the same table (see
 * {@see self::folds()}), so that they cannot disagree.
 *
 * Two things are done apart from it. `%` and `_` typed by the person are ordinary characters, so they are escaped (with the
 * backslash, the default escape character of `LIKE` on all three engines). And the term is lowered even for the engines
 * that ignore the case, because the email columns of MySQL and MariaDB have a binary collation, where `LIKE` tells capitals
 * from small letters (see {@see ExactEmailColumns}), and addresses are stored in lowercase.
 *
 * What it does not fold: the letters Unicode does not decompose (`œ`, `æ`, `ß`, `ø`, `ł`, `đ`, `ı`) and the other alphabets
 * (Greek, Cyrillic...). They are found as they are written, in the case the engine knows: PostgreSQL folds the case of the
 * letters its database locale knows (a database created with the `C` locale only folds ASCII ones, which is why the capital
 * accented letters are in the table too), and MySQL and MariaDB may take some of them for their transliteration (`ß` for
 * `ss`) because their collation does. MySQL and MariaDB rely on the collation of the column for its side of the accents, so
 * a column with an accent-sensitive one would not find `é` by `e`. The searches an administrator makes (a part of a name or of
 * an address) do not depend on any of it.
 */
final class CaseInsensitiveSearch
{
    /** The first and the last code points of the blocks whose letters are looked at: Latin-1 to Latin Extended-B, and Latin Extended Additional. */
    private const BLOCKS = [[0x00C0, 0x024F], [0x1E00, 0x1EFF]];

    /** The combining diacritical marks, which a text in decomposed form holds beside its letters. */
    private const COMBINING = '/[\x{0300}-\x{036F}]/u';

    /** @var array<string, string>|null The letters that are folded and what they are folded to, built on first use. */
    private static ?array $folds = null;

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
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::fold(trim($term))).'%';
        $folds = self::folds();
        $grammar = $query->getQuery()->getGrammar();
        $accents = $query->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? [implode('', array_keys($folds)), implode('', $folds)]
            : null;

        // The base query builder takes a fragment of SQL the application wrote (see RawSql), the Eloquent one only a literal.
        $query->getQuery()->where(function (QueryBuilder $group) use ($columns, $pattern, $grammar, $accents): void {
            foreach ($columns as $column) {
                if ($accents === null) {
                    $group->orWhereLike($column, $pattern);

                    continue;
                }

                $group->whereRaw(new RawSql('lower(translate('.$grammar->wrap($column).', ?, ?)) like ?'), [...$accents, $pattern], 'or');
            }
        });
    }

    /**
     * The text in the plain form the columns are compared in: lowercase, the letters with a mark written as their base
     * letter, the combining marks dropped. What is not valid UTF-8 is replaced by `?` first, so that any input gives an answer.
     */
    private static function fold(string $text): string
    {
        $lowered = mb_strtolower(mb_scrub($text));

        return preg_replace(self::COMBINING, '', strtr($lowered, self::folds())) ?? $lowered;
    }

    /**
     * The letters that have a base letter and a mark in Unicode, each with its base letter in lowercase: `é` and `É` give `e`.
     * Only those whose base is one ASCII letter are in, since `translate()` swaps a character for a character.
     *
     * @return array<string, string>
     */
    private static function folds(): array
    {
        if (self::$folds !== null) {
            return self::$folds;
        }

        $folds = [];

        foreach (self::BLOCKS as [$first, $last]) {
            for ($code = $first; $code <= $last; $code++) {
                $letter = mb_chr($code, 'UTF-8');
                $decomposed = Normalizer::normalize($letter, Normalizer::FORM_D);

                if ($decomposed === false || $decomposed === $letter) {
                    continue;
                }

                $base = preg_replace(self::COMBINING, '', $decomposed);

                if ($base !== null && preg_match('/^[A-Za-z]$/', $base) === 1) {
                    $folds[$letter] = strtolower($base);
                }
            }
        }

        return self::$folds = $folds;
    }
}
