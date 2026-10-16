<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the number of items of an inventory on the inventory itself.
 *
 * Counting the rows of `items` is what the quota, the list of inventories and the account export all needed, and it
 * grows with the inventory. The counter is read in O(1) and is only ever written with the inventory locked (see
 * `App\Inventories\Items`), so it cannot drift under concurrent writes. The inventories that already exist are counted
 * once here, with one statement that reads the same on PostgreSQL, MySQL and MariaDB.
 *
 * MySQL and MariaDB cannot undo a change of schema: when the counting fails after the column was added, the column stays
 * and the migration is not recorded. `up()` can therefore be run again: it adds the column only when it is not there, and
 * counts (the count is the same however many times it runs).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('inventories', 'items_count')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unsignedInteger('items_count')->default(0);
            });
        }

        DB::update('UPDATE inventories SET items_count = (SELECT COUNT(*) FROM items WHERE items.inventory_id = inventories.id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('items_count');
        });
    }
};
