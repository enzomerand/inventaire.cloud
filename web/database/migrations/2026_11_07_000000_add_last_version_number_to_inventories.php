<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the number of the last official version of an inventory on the inventory itself.
 *
 * A version is numbered 1, 2, 3... inventory by inventory, and a number is never given twice: numbering by `MAX(number) + 1` over the
 * versions that exist would give number N again to the version frozen after the last one (number N) was deleted, and a signed PDF, an
 * email or a printout that says "version no. N" would then name two different documents. The counter is only ever written with the
 * inventory locked, by the freeze (see `App\Inventories\Versions\OfficialVersions`), like `items_count`. The inventories that already
 * hold versions get the highest number they use, with one statement that reads the same on PostgreSQL, MySQL and MariaDB.
 *
 * MySQL and MariaDB cannot undo a change of schema: when the update fails after the column was added, the column stays and the
 * migration is not recorded. `up()` can therefore be run again: it adds the column only when it is not there, and sets the numbers (the
 * result is the same however many times it runs).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('inventories', 'last_version_number')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unsignedInteger('last_version_number')->default(0);
            });
        }

        DB::update('UPDATE inventories SET last_version_number = COALESCE((SELECT MAX(number) FROM inventory_versions WHERE inventory_versions.inventory_id = inventories.id), 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('last_version_number');
        });
    }
};
