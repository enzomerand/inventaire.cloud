<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the column of the text an item is searched by.
 *
 * The text is in a form that ignores the case and the accents, which no database collation does the same way on
 * PostgreSQL, MySQL and MariaDB: it is built in PHP (`App\Inventories\ItemSearchText`) and the search is a plain `LIKE`
 * on it. The column is nullable: an item that holds nothing to search for has no text, and so does every item that
 * existed before the column.
 *
 * A migration does not call the application's code, which is the code of the day it runs and not of the day it was
 * written: the texts of the items that already exist are built by `inventaire:upgrade` (`App\Inventories\DerivedData`),
 * which builds the texts of the items that have none.
 *
 * MySQL and MariaDB cannot undo a change of schema, so `up()` adds the column only when it is not there.
 *
 * It is not a table of its own: nothing is added to `ApplicationTables`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'search_text')) {
            Schema::table('items', function (Blueprint $table) {
                $table->longText('search_text')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
