<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives the revisions of the items the identifier the outside world uses.
 *
 * Every model the API hands out is known by a `ulid` and keeps its numeric key to itself, so that nothing reveals how many
 * rows exist or lets one be guessed. The revisions kept the number (`id`) and gave it out in the history of an item: they now
 * have a `ulid` as well, written when the revision is, and the number stays what orders and prunes them.
 *
 * The revisions that already exist get theirs here, a chunk at a time, one statement each (a ULID cannot be made by the
 * database): `up()` can be run again after a failure half way through, since MySQL and MariaDB cannot undo a change of schema
 * (the column, the ULIDs written so far and the index are each added only when they are not there).
 *
 * It is not a table of its own: nothing is added to `ApplicationTables`.
 */
return new class extends Migration
{
    /** Rows given a ULID per read. */
    private const CHUNK = 500;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('item_revisions', 'ulid')) {
            Schema::table('item_revisions', function (Blueprint $table) {
                $table->ulid('ulid')->nullable();
            });
        }

        DB::table('item_revisions')->whereNull('ulid')->chunkById(self::CHUNK, function ($revisions): void {
            foreach ($revisions as $revision) {
                DB::table('item_revisions')->where('id', $revision->id)->update(['ulid' => strtolower((string) Str::ulid())]);
            }
        });

        Schema::table('item_revisions', function (Blueprint $table) {
            $table->ulid('ulid')->nullable(false)->change();
        });

        if (! Schema::hasIndex('item_revisions', 'item_revisions_ulid_unique')) {
            Schema::table('item_revisions', function (Blueprint $table) {
                $table->unique('ulid');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_revisions', function (Blueprint $table) {
            $table->dropUnique(['ulid']);
            $table->dropColumn('ulid');
        });
    }
};
