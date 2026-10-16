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
 * MySQL and MariaDB do not keep either and nothing relies on; the null character is refused by `jsonb` at the write, and
 * the rules of the fields already refuse it.
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

        // The names are fixed words, not input: nothing is bound because a statement of schema takes no binding.
        DB::statement("ALTER TABLE items ALTER COLUMN \"values\" TYPE {$to} USING \"values\"::{$to}");
    }
};
