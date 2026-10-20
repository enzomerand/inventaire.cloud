<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\Ulid;

/**
 * Gives every account the identifier by which the other members of a workspace name it: `users.ulid`.
 *
 * The number of an account never leaves the application but to its owner (`GET /me`): it would tell every member how many accounts
 * the instance holds, and which ones were opened around the same time. The members of a workspace are named by this value instead. It
 * has the shape of a ULID (26 lowercase Crockford characters, the first of them 0 to 7), but it is drawn at random all the way: the
 * first 48 bits of a real ULID are the moment it was made, and would say when the account was opened.
 *
 * The column is added empty, filled in chunks (an account keeps the value it was given, so running this again after it stopped half way
 * finishes the job) and only then made unique and mandatory, so that it runs on a table of any size and on PostgreSQL, MySQL and
 * MariaDB alike. A MySQL or MariaDB table is not locked for the length of the backfill, since the rows are updated one by one by their key.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ulid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->ulid('ulid')->nullable();
            });
        }

        // Right before the column becomes mandatory: nothing but an account that has none can stop the change.
        $this->fill();

        Schema::table('users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable(false)->change();
            $table->unique('ulid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['ulid']);
            $table->dropColumn('ulid');
        });
    }

    /**
     * Gives a value to every account that has none, a few hundred at a time.
     */
    private function fill(): void
    {
        do {
            $ids = DB::table('users')->whereNull('ulid')->orderBy('id')->limit(500)->pluck('id');

            foreach ($ids as $id) {
                DB::table('users')->where('id', $id)->whereNull('ulid')->update(['ulid' => strtolower(Ulid::fromBinary(random_bytes(16))->toBase32())]);
            }
        } while ($ids->isNotEmpty());
    }
};
