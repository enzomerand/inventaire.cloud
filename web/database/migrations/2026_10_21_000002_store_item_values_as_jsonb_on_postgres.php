<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the values of the items as `jsonb` on PostgreSQL.
 *
 * `json` keeps the text it was given, so reading one key of a value means parsing the whole text, and the totals of a list
 * read every key of every item; `jsonb` is parsed once, when it is written, and looked into by key afterwards (about twice
 * as fast on the totals, measured at the quota of items and fields). The queries are the same text: the key and presence
 * operators of the grammar read both types. What `jsonb` does not keep is the order of the keys and the white space, which
 * MySQL and MariaDB do not keep either and nothing relies on.
 *
 * What `jsonb` cannot hold at all is the null character: `json` keeps the escape `\u0000` as the text it is, `jsonb` refuses
 * it, and so would the conversion of a table that holds one. The rules of the fields refuse the null character today, but
 * the first release did not (an `email` value could carry one), and a failing migration would fail `inventaire:upgrade` for
 * good, which keeps a container in a restart loop. So the conversion drops the escape from the stored values: a null
 * character is no data (nothing can show it, and PostgreSQL could not read it back out of a text anyway). Only a real
 * escape is dropped: `\\u0000` (an escaped backslash followed by the text `u0000`) is left as it is, because the pairs of
 * backslashes are consumed as the pattern goes along. Going back to `json` needs nothing of the kind.
 *
 * This is the one place where the schema depends on the engine: MySQL and MariaDB have a single `json` type (a `longtext`
 * checked for validity on MariaDB, a binary form on MySQL), so there is nothing to change there. PostgreSQL rewrites the
 * table to change the type of a column and holds an exclusive lock on it meanwhile: this runs once, in an upgrade.
 *
 * It can be run again over a column that is already `jsonb`: it changes the type only when the column is not that.
 */
return new class extends Migration
{
    /**
     * The text of a `json` value without its null characters, as the expression that `jsonb` is cast from. The pattern
     * matches a pair of backslashes (the escape of a backslash, put back as it was) or the escape `\u0000` (put back as
     * nothing). The text is read from the left, so a backslash is first seen at the start of its escape: `\\u0000` is a
     * pair then plain text, `\\\u0000` a pair then a null character. PostgreSQL reads a backslash in a string as itself.
     */
    private const WITHOUT_NULL_ESCAPES = <<<'SQL'
regexp_replace("values"::text, '(\\\\)|\\u0000', '\1', 'g')
SQL;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->convert('json', 'jsonb');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->convert('jsonb', 'json');
    }

    /**
     * Changes the type of the column when it is `$from` (PostgreSQL only: the other engines are left as they are).
     *
     * @param  string  $from  The type the column has now.
     * @param  string  $to  The type it must have.
     */
    private function convert(string $from, string $to): void
    {
        if (DB::getDriverName() !== 'pgsql' || Schema::getColumnType('items', 'values') !== $from) {
            return;
        }

        // Text only goes through the expression above when it is about to be parsed as jsonb, which is what refuses the null character.
        $source = $to === 'jsonb' ? self::WITHOUT_NULL_ESCAPES : '"values"';

        // The names are fixed words, not input: nothing is bound because a statement of schema takes no binding.
        DB::statement("ALTER TABLE items ALTER COLUMN \"values\" TYPE {$to} USING {$source}::{$to}");
    }
};
